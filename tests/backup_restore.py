"""Backup/restore verification only for the disposable test database."""
import os
import subprocess
import stat

def run_tests(sql,app,sock,temp,env,check):
    destination=temp/"synthetic-backup.sql"
    backup_env=dict(env,DB_SOCKET=sock)
    result=subprocess.run(["php","-d","mysqli.default_socket="+sock,str(app/"scripts/backup.php"),str(destination)],env=backup_env,cwd=app,text=True,capture_output=True)
    check(result.returncode==0,"Disposable backup succeeds: "+result.stderr+result.stdout)
    check(stat.S_IMODE(destination.stat().st_mode)==0o600,"Backup uses private file permissions")
    check("password_hash" not in result.stdout and "fictional-test-password" not in result.stdout,"Backup contents not printed")
    check(subprocess.run(["php",str(app/"scripts/backup.php"),str(destination)],env=backup_env,cwd=app,capture_output=True).returncode!=0,"Backup refuses overwrite")
    check(subprocess.run(["php",str(app/"scripts/backup.php"),str(app/"public/unsafe-backup.sql")],env=backup_env,cwd=app,capture_output=True).returncode!=0,"Backup refuses application directory")
    sql("CREATE DATABASE backup_restore_test; USE backup_restore_test;\n"+destination.read_text())
    check(sql("USE backup_restore_test; SELECT COUNT(*) FROM residents;").strip()==sql("USE shelter_test; SELECT COUNT(*) FROM residents;").strip(),"Resident fixtures restored into separate disposable database")
    check(sql("USE backup_restore_test; SELECT COUNT(*) FROM case_notes;").strip()==sql("USE shelter_test; SELECT COUNT(*) FROM case_notes;").strip(),"Private case fixtures restored")
    check(sql("USE backup_restore_test; SELECT COUNT(*) FROM inventory_movements;").strip()==sql("USE shelter_test; SELECT COUNT(*) FROM inventory_movements;").strip(),"Stock ledger restored")
    print("PASS: private synthetic backup, overwrite/web-root refusal, and isolated restoration.")
