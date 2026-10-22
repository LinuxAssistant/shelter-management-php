"""Constraint, upgrade-preflight and real concurrent-process occupancy tests."""
from concurrent.futures import ThreadPoolExecutor
import json
import os
import re
from pathlib import Path
import subprocess
import time

def run_tests(sql, root, sock, temp):
    def check(ok, message):
        if not ok:
            raise AssertionError(message)
    def duplicate(statement):
        try:
            sql(statement)
        except subprocess.CalledProcessError as error:
            check("1062" in error.stderr, "Expected unique-key rejection")
            return
        raise AssertionError("Conflicting active stay was accepted")
    sql("USE shelter_test; INSERT INTO beds(id,room_id,code) VALUES(100,1,'RACE-A'),(101,1,'RACE-B');"
        "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES"
        "(100,1,'RACE-100','Concurrency Alpha'),(101,1,'RACE-101','Concurrency Beta');")
    insert="INSERT INTO stays(resident_id,bed_id,checked_in_at) VALUES"
    clear="USE shelter_test; DELETE FROM stays WHERE resident_id IN(100,101);"
    sql("USE shelter_test; "+insert+"(100,100,NOW());")
    duplicate("USE shelter_test; "+insert+"(101,100,NOW());")
    duplicate("USE shelter_test; "+insert+"(100,101,NOW());")
    sql("USE shelter_test; INSERT INTO stays(resident_id,bed_id,checked_in_at,checked_out_at) VALUES"
        "(100,100,'2020-01-01','2020-01-02'),(100,100,'2020-01-02','2020-01-03');")
    closed=sql("USE shelter_test; SELECT MAX(id) FROM stays WHERE resident_id=100 AND checked_out_at IS NOT NULL;").strip()
    duplicate("USE shelter_test; UPDATE stays SET checked_out_at=NULL WHERE id="+closed+";")
    sql(clear)

    def raw_insert(resident,bed):
        try:
            sql("USE shelter_test; START TRANSACTION; "+insert+f"({resident},{bed},NOW()); DO SLEEP(0.2); COMMIT;")
            return "accepted"
        except subprocess.CalledProcessError as error:
            # MariaDB versions/lock schedules may choose a deadlock victim
            # instead of waiting for the winner and reporting a duplicate key.
            match=re.search(r"ERROR\s+(\d+)",error.stderr)
            code=int(match[1]) if match else None
            check(code in (1062,1205,1213), "Unexpected concurrent SQL error code: "+str(code))
            return "rejected"
    for choices in [[(100,100),(101,100)],[(100,100),(100,101)]]:
        with ThreadPoolExecutor(max_workers=2) as pool:
            outcomes=list(pool.map(lambda pair:raw_insert(*pair),choices))
        check(sorted(outcomes)==["accepted","rejected"], "One SQL writer wins")
        sql(clear)

    (temp/"occupancy-test-instance").write_text("disposable occupancy tests")
    gate=temp/"occupancy-start"
    env=dict(os.environ,OCCUPANCY_TEST_SOCKET=sock)
    for choices in [[(100,100),(101,100)],[(100,100),(100,101)]]:
        gate.unlink(missing_ok=True)
        for ready in temp.glob("ready-*"):
            ready.unlink()
        processes=[subprocess.Popen(["php",str(root/"tests/occupancy-worker.php"),str(resident),str(bed)],
                                    env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                   for resident,bed in choices]
        try:
            deadline=time.monotonic()+8
            while len(list(temp.glob("ready-*")))<2:
                if time.monotonic()>deadline:
                    raise AssertionError("Concurrent workers did not become ready")
                time.sleep(.01)
            gate.touch()
            statuses=[]
            for process in processes:
                stdout,stderr=process.communicate(timeout=15)
                check(process.returncode==0 and not stderr,"Concurrent occupancy worker failed")
                statuses.append(json.loads(stdout.strip())["status"])
            check(statuses.count(303)==1 and any(status in (409,422) for status in statuses),
                  "Exactly one concurrent application check-in succeeds")
            check(sql("USE shelter_test; SELECT COUNT(*) FROM stays WHERE resident_id IN(100,101) AND checked_out_at IS NULL;").strip()=="1",
                  "Application concurrency leaves one active stay")
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.communicate()
        sql(clear)
    migration=(root/"database/migrations/017_active_stay_uniqueness.sql").read_text()
    old_schema="\n".join(path.read_text() for path in sorted((root/"database/migrations").glob("*.sql")) if int(path.name[:3])<17)
    for kind in ("resident","bed"):
        database="shelter_upgrade_"+kind
        sql("CREATE DATABASE "+database+"; USE "+database+";\n"+old_schema)
        sql("USE "+database+"; INSERT INTO facilities(id,name) VALUES(1,'Upgrade fixture');"
            "INSERT INTO rooms(id,facility_id,name) VALUES(1,1,'Upgrade room');"
            "INSERT INTO beds(id,room_id,code) VALUES(1,1,'A'),(2,1,'B');"
            "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES"
            "(1,1,'UP-1','Upgrade Alpha'),(2,1,'UP-2','Upgrade Beta');"
            +insert+("(1,1,NOW()),(1,2,NOW());" if kind=="resident" else "(1,1,NOW()),(2,1,NOW());"))
        try:
            sql("USE "+database+";\n"+migration)
        except subprocess.CalledProcessError as error:
            check("duplicate open stays for a "+kind in error.stderr,"Upgrade must explain conflicting open stays")
        else:
            raise AssertionError("Upgrade accepted conflicting open stays")
        check(sql("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='"+database+"' AND table_name='stays' AND column_name='active_resident_id';").strip()=="0",
              "Blocked upgrade must not partially alter stays")
        check(sql("USE "+database+"; SELECT COUNT(*) FROM stays WHERE checked_out_at IS NULL;").strip()=="2",
              "Blocked upgrade preserves existing rows")
        sql("USE "+database+"; UPDATE stays SET checked_out_at=NOW() WHERE id=2;")
        sql("USE "+database+";\n"+migration)
        check(sql("SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema='"+database+"' AND table_name='stays' AND index_name IN('uq_stays_active_resident','uq_stays_active_bed');").strip()=="2",
              "Reconciled upgrade creates both unique indexes")
    print("PASS: active-stay constraints, closed history, SQL/application concurrency, and safe upgrade preflight.")
