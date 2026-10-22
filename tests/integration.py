#!/usr/bin/env python3
"""Run against a disposable MariaDB instance and copied app; never loads project .env."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

def check(condition, message):
    if not condition:
        raise AssertionError(message)

def run():
    with tempfile.TemporaryDirectory(prefix="shelter-tests-") as directory:
        temp = Path(directory)
        data, app = temp / "data", temp / "app"
        app.mkdir()
        for name in ("app", "bootstrap", "public", "vendor"):
            shutil.copytree(ROOT / name, app / name)
        (app / ".env").write_text("")
        sock = str(temp / "db.sock")
        subprocess.run(["mariadb-install-db", "--no-defaults", "--datadir=" + str(data),
                        "--auth-root-authentication-method=normal"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        with (temp / "db.log").open("w") as log:
            db = subprocess.Popen(["mariadbd", "--no-defaults", "--datadir=" + str(data),
                                   "--socket=" + sock, "--skip-networking", "--pid-file=" + str(temp / "db.pid")],
                                  stdout=log, stderr=log)
            server = None
            try:
                def sql(statement):
                    return subprocess.run(["mariadb", "--no-defaults", "--socket=" + sock, "-uroot", "--batch", "--skip-column-names"],
                                          input=statement, text=True, capture_output=True, check=True).stdout
                for _ in range(100):
                    if db.poll() is not None:
                        raise RuntimeError("Disposable MariaDB failed to start")
                    try:
                        sql("SELECT 1;")
                        break
                    except subprocess.CalledProcessError:
                        time.sleep(.1)
                else:
                    raise RuntimeError("Disposable MariaDB startup timed out")
                sql("CREATE DATABASE shelter_test; USE shelter_test;\n" + (ROOT / "database/schema.sql").read_text())
                password_hash = subprocess.check_output(["php", "-r", "echo password_hash('fictional-test-password', PASSWORD_DEFAULT);"], text=True)
                sql("USE shelter_test; INSERT INTO users(id,email,badge_number,password_hash) VALUES(1,'test@example.invalid','TEST','" + password_hash + "'),(2,'denied@example.invalid','DENIED','" + password_hash + "');"
                    "INSERT INTO user_roles SELECT 1,id FROM roles WHERE name='Administrator';"
                    "INSERT INTO facilities(id,name) VALUES(1,'Fictional Shelter'),(2,'Other Fictional Shelter');"
                    "INSERT INTO rooms(id,facility_id,name) VALUES(1,1,'Test room'),(2,2,'Other room');"
                    "INSERT INTO beds(id,room_id,code) VALUES(1,1,'TEST-BED'),(2,2,'OTHER-BED');"
                    "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES(1,1,'RES-TEST-001','Fictional Alpha'),(2,1,'RES-TEST-002','Fictional Beta'),(3,1,'RES-TEST-003','Literal %_! Name');")
                sessions = temp / "sessions"
                sessions.mkdir()
                with socket.socket() as port_socket:
                    port_socket.bind(("127.0.0.1", 0))
                    port = port_socket.getsockname()[1]
                env = dict(os.environ, DB_HOST="localhost", DB_USER="root", DB_PASSWORD="", DB_NAME="shelter_test", SESSION_SECURE="false")
                with (temp / "http.log").open("w") as http_log:
                    server = subprocess.Popen(["php", "-d", "mysqli.default_socket=" + sock, "-d", "session.save_path=" + str(sessions), "-S", "127.0.0.1:" + str(port),
                                               "-t", str(app / "public"), str(app / "public/router.php")],
                                              cwd=app, env=env, stdout=http_log, stderr=http_log)
                    base = "http://127.0.0.1:" + str(port)
                    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                    def request(path, values=None, client=None):
                        data_bytes = None if values is None else urllib.parse.urlencode(values).encode()
                        try:
                            response = (client or opener).open(base + path, data_bytes, timeout=5)
                        except urllib.error.HTTPError as error:
                            response = error
                        return response.status, response.read().decode(), response
                    for _ in range(100):
                        try:
                            request("/login")
                            break
                        except urllib.error.URLError:
                            time.sleep(.1)
                    def csrf(body):
                        return re.search(r'name="_csrf" value="([^"]+)"', body)[1]
                    status, body, response = request("/api/residents/search?q=Fictional")
                    check(response.url.endswith("/login"), "Anonymous API access must require login")
                    _, body, _ = request("/login")
                    check(request("/login", {"_csrf":csrf(body), "identifier":"test@example.invalid", "password":"fictional-test-password"})[0] == 200, "Login")
                    def search(q):
                        status, body, response = request("/api/residents/search?q=" + urllib.parse.quote(q))
                        check(status == 200 and response.headers["Cache-Control"] == "no-store", "Search response")
                        return json.loads(body)
                    check(len(search("Fictional")) == 2, "Partial-name search")
                    check(len(search("RES-TEST-001")) == 1, "Reference search")
                    check(len(search("%_!")) == 1, "LIKE wildcard escaping")
                    check(search("F") == [] and search("x" * 101) == [], "Query length limits")
                    check(request("/api/residents/search?q[]=bad")[0] == 422, "Array query rejected")
                    check(request("/api/residents/search", {})[0] == 405, "API method")
                    _, body, _ = request("/admin/occupancy")
                    check("Fictional Alpha" not in body and "resident-search.js" in body, "No preloaded resident list")
                    token = csrf(body)
                    check(request("/admin/occupancy", {"_csrf":"invalid","action":"checkin","resident_id":1,"bed_id":1})[0] == 419, "CSRF")
                    def post(values):
                        return request("/admin/occupancy", dict(_csrf=token, **values))
                    check(post(dict(action="checkin",resident_id=1,bed_id=2))[0] == 422, "Cross-facility checkin")
                    for departure in ("staff_exit", "voluntary"):
                        check(post(dict(action="checkin",resident_id=1,bed_id=1))[0] == 200, "Checkin")
                        check(search("RES-TEST-001") == [], "Exclude current residents from search")
                        _, listing, _ = request("/admin/residents")
                        alpha_row = re.search(r"<tr>(?:(?!</tr>).)*Fictional Alpha(?:(?!</tr>).)*</tr>", listing)[0]
                        check("Currently staying" in alpha_row and "TEST-BED" in alpha_row, "Active resident status and bed")
                        check(post(dict(action="checkin",resident_id=2,bed_id=1))[0] == 422, "Double booking")
                        stay = sql("USE shelter_test; SELECT id FROM stays WHERE resident_id=1 AND checked_out_at IS NULL;").strip()
                        if departure == "staff_exit":
                            check(post(dict(action="checkout",stay_id=stay,departure_type=departure,departure_reason=""))[0] == 422, "Staff exit reason required")
                        status, body, _ = post(dict(action="checkout",stay_id=stay,departure_type=departure,departure_reason="Fictional test departure"))
                        check(status == 200 and "Fictional Alpha" not in body, "Checkout removes occupancy")
                        check(post(dict(action="checkout",stay_id=stay,departure_type=departure,departure_reason="test"))[0] == 422, "Repeated checkout rejected")
                        _, body, _ = request("/admin/residents")
                        alpha_row = re.search(r"<tr>(?:(?!</tr>).)*Fictional Alpha(?:(?!</tr>).)*</tr>", body)[0]
                        check("Checked out" in alpha_row and "Not checked in" in alpha_row and "TEST-BED" not in alpha_row, "Retain checked-out resident without bed")
                        check(len(search("RES-TEST-001")) == 1, "Returning resident searchable")
                    _, body, _ = request("/admin/residents/1/history")
                    check(all(text in body for text in ("Staff-directed exit", "Voluntary departure", "Fictional test departure", "test@example.invalid")), "History and staff attribution")
                    sql("USE shelter_test; INSERT INTO stays(resident_id,bed_id,checked_in_at,checked_out_at) VALUES(1,1,'2020-01-01','2020-01-02');")
                    check("Unspecified (older record)" in request("/admin/residents/1/history")[1], "Legacy history")
                    check(request("/admin/residents/999/history")[0] == 404, "Missing history")
                    sql("USE shelter_test; INSERT INTO residents(facility_id,reference_code,display_name) SELECT 1,CONCAT('LIMIT-',seq),CONCAT('Limit Test ',seq) FROM seq_1_to_25;")
                    check(len(search("Limit Test")) == 20, "Search result limit")
                    denied = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                    _, body, _ = request("/login", client=denied)
                    request("/login", {"_csrf":csrf(body), "identifier":"denied@example.invalid", "password":"fictional-test-password"}, denied)
                    check(request("/api/residents/search?q=Fictional", client=denied)[0] == 403, "Unauthorized search")
                    import sys, runpy
                    if "--browser" in sys.argv:
                        browser_test = runpy.run_path(str(ROOT / "tests/browser_residents.py"))["run_browser"]
                        for browser_name in ("chromium", "firefox"):
                            browser_test(base, sql, browser_name)
                    print("PASS: isolated HTTP/MariaDB integration checks (checkout, returning residents, history, search, authorization, CSRF).")
            finally:
                for process in (server, db):
                    if process is not None:
                        process.terminate()
                        try:
                            process.wait(timeout=10)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait()

if __name__ == "__main__":
    run()
