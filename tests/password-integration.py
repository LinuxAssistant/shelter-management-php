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
                sql("CREATE DATABASE shelter_test; USE shelter_test;\n" + "\n".join(path.read_text() for path in sorted((ROOT / "database/migrations").glob("*.sql"))))
                password_hash = subprocess.check_output(["php", "-r", "echo password_hash('fictional-test-password', PASSWORD_DEFAULT);"], text=True)
                sql("USE shelter_test; INSERT INTO users(id,email,badge_number,password_hash,active) VALUES"
                    "(1,'admin@example.invalid','ADMIN','" + password_hash + "',1),"
                    "(2,'employee@example.invalid','EMPLOYEE','" + password_hash + "',1),"
                    "(3,'inactive@example.invalid','INACTIVE','" + password_hash + "',0);"
                    "INSERT INTO user_roles SELECT 1,id FROM roles WHERE name='Administrator';")
                capture, failure = temp / "mail.txt", temp / "fail-mail"
                mail_script = temp / "capture-mail.py"
                mail_script.write_text("import pathlib,sys\np=pathlib.Path(__file__).parent\nbody=sys.stdin.read()\nif (p/'fail-mail').exists(): sys.exit(1)\n(p/'mail.txt').write_text(body)\n")
                sessions = temp / "sessions"
                sessions.mkdir()
                with socket.socket() as port_socket:
                    port_socket.bind(("127.0.0.1", 0))
                    port = port_socket.getsockname()[1]
                env = dict(os.environ, DB_HOST="localhost", DB_USER="root", DB_PASSWORD="", DB_NAME="shelter_test",
                           SESSION_SECURE="false", PASSWORD_RESET_EMAIL_ENABLED="true",
                           APP_URL="https://shelter.example.invalid", MAIL_FROM="noreply@example.invalid")
                def php(code):
                    return subprocess.check_output(["php", "-d", "mysqli.default_socket=" + sock,
                                                    "-d", "session.save_path=" + str(sessions), "-r",
                                                    "require 'bootstrap/app.php'; require 'app/PasswordResetTokens.php';" + code],
                                                   cwd=app, env=env, text=True)
                def issue():
                    return php("echo issuePasswordResetToken(2);").strip()
                def reset_rates():
                    sql("USE shelter_test; DELETE FROM auth_rate_limits;")
                with (temp / "http.log").open("w") as http_log:
                    server = subprocess.Popen(["php", "-d", "mysqli.default_socket=" + sock,
                                               "-d", "session.save_path=" + str(sessions),
                                               "-d", "sendmail_path=python3 " + str(mail_script),
                                               "-S", "127.0.0.1:" + str(port),
                                               "-t", str(app / "public"), str(app / "public/router.php")],
                                              cwd=app, env=env, stdout=http_log, stderr=http_log)
                    base = "http://127.0.0.1:" + str(port)
                    def client():
                        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                    anonymous, admin, employee, second = (client() for _ in range(4))
                    def request(opener, path, values=None):
                        data_bytes = None if values is None else urllib.parse.urlencode(values).encode()
                        try:
                            response = opener.open(base + path, data_bytes, timeout=10)
                        except urllib.error.HTTPError as error:
                            response = error
                        return response.status, response.read().decode(), response
                    for _ in range(100):
                        try:
                            request(anonymous, "/login")
                            break
                        except urllib.error.URLError:
                            time.sleep(.1)
                    else:
                        raise RuntimeError("HTTP server startup timed out")
                    def csrf(body):
                        return re.search(r'name="_csrf" value="([^"]+)"', body)[1]
                    def login(opener, name, password="fictional-test-password"):
                        _, body, _ = request(opener, "/login")
                        return request(opener, "/login", {"_csrf":csrf(body),"identifier":name+"@example.invalid","password":password})
                    def post(opener, path, values, form=None):
                        _, body, _ = request(opener, form or path)
                        return request(opener, path, dict(_csrf=csrf(body), **values))
                    def consume(token, password="fictional-new-password"):
                        return post(anonymous, "/reset-password", dict(token=token,password=password,confirmation=password),
                                    "/reset-password?token=" + token)
                    check(login(admin,"admin")[0] == 200, "Administrator login")
                    check(login(employee,"employee")[0] == 200 and login(second,"employee")[0] == 200, "Two employee sessions")
                    _, body, _ = request(admin,"/admin/employees/2")
                    admin_csrf = csrf(body)
                    check("/admin/employees/2/reset-password" in body, "Reset form wired")
                    path = "/admin/employees/2/reset-password"
                    check(request(admin,path)[0] == 405, "Reset accepts only POST")
                    check(request(employee,path,{"_csrf":"invalid"})[0] == 403, "Reset requires admin permission")
                    check(request(admin,path,{"_csrf":"invalid"})[0] == 419, "Reset CSRF enforced")
                    check(request(admin,"/admin/employees/3/reset-password",{"_csrf":admin_csrf})[0] == 404, "Inactive target rejected")
                    check(request(admin,path,{"_csrf":admin_csrf})[0] == 200, "Administrator reset mail")
                    check(capture.exists(), "Mail captured locally")
                    token = re.search(r"token=([a-f0-9]{64})",capture.read_text())[1]
                    stored = sql("USE shelter_test; SELECT token_hash FROM password_reset_tokens WHERE user_id=2;").strip()
                    check(len(stored)==64 and stored != token, "Only token digest stored")
                    check(token not in request(admin,path,{"_csrf":admin_csrf})[1], "Token not in admin response")
                    check(consume(token)[0] == 400, "Superseded token rejected")
                    token = re.search(r"token=([a-f0-9]{64})",capture.read_text())[1]
                    check(consume(token)[0] == 200, "Valid token consumed")
                    check(consume(token)[0] == 400, "Token cannot be reused")
                    check(request(employee,"/")[2].url.endswith("/login") and request(second,"/")[2].url.endswith("/login"), "Reset revokes both sessions")
                    check(login(employee,"employee")[0] == 401, "Old password rejected")
                    check(login(employee,"employee","fictional-new-password")[0] == 200, "New password works")
                    reset_rates()
                    check(login(second,"employee","fictional-new-password")[0] == 200, "New second session")
                    pending = issue()
                    values = dict(current_password="incorrect",new_password="fictional-next-password",confirm_password="fictional-next-password")
                    check(post(employee,"/account/password",values)[0] == 403, "Wrong current password")
                    values["current_password"]="fictional-new-password"
                    values["confirm_password"]="does-not-match"
                    check(post(employee,"/account/password",values)[0] == 422, "Mismatched password")
                    values["new_password"]=values["confirm_password"]="x"*73
                    check(post(employee,"/account/password",values)[0] == 422, "No bcrypt truncation")
                    values["new_password"]=values["confirm_password"]="fictional-next-password"
                    check(post(employee,"/account/password",values)[0] == 200, "Self-service password change")
                    check(consume(pending)[0] == 400, "Self-service change revokes pending reset")
                    check(request(second,"/")[2].url.endswith("/login"), "Self-service change revokes other session")
                    check(login(employee,"employee","fictional-next-password")[0] == 200, "Changed password works")
                    expired = issue()
                    sql("USE shelter_test; UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE);")
                    check(consume(expired)[0] == 400, "Expired reset rejected")
                    inactive = issue()
                    sql("USE shelter_test; UPDATE users SET active=0 WHERE id=2;")
                    check(consume(inactive)[0] == 400, "Inactive user cannot reset")
                    check(request(employee,"/")[2].url.endswith("/login"), "Inactive user session rejected")
                    sql("USE shelter_test; UPDATE users SET active=1 WHERE id=2;")
                    reset_rates()
                    for _ in range(3):
                        request(admin,path,{"_csrf":admin_csrf})
                    check(request(admin,path,{"_csrf":admin_csrf})[0] == 429, "Administrator reset throttling")
                    reset_rates()
                    failure.touch()
                    check(request(admin,path,{"_csrf":admin_csrf})[0] == 503, "Failed mail reported")
                    check(sql("USE shelter_test; SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=2;").strip()=="0", "Failed delivery token revoked")
                    failure.unlink()
                    reset_rates()
                    def forgot(email):
                        return post(anonymous,"/forgot-password",dict(email=email))
                    known = forgot("employee@example.invalid")[1]
                    unknown = forgot("absent@example.invalid")[1]
                    inactive = forgot("inactive@example.invalid")[1]
                    normalize = lambda html: re.sub(r'name="_csrf" value="[^"]+"','name="_csrf" value="CSRF"',html)
                    check(normalize(known)==normalize(unknown)==normalize(inactive), "Recovery response does not enumerate accounts")
                    capture.unlink()
                    forgot("employee@example.invalid")
                    forgot("employee@example.invalid")
                    capture.unlink()
                    forgot("employee@example.invalid")
                    check(not capture.exists(), "Forgot-password throttling")
                    reset_rates()
                    bad = client()
                    for _ in range(5):
                        login(bad,"employee","wrong")
                    check(login(bad,"employee","wrong")[0] == 429, "Login throttling")
                    events = sql("USE shelter_test; SELECT event_name FROM password_security_events;")
                    check(all(event in events for event in ("password_changed","password_reset","reset_token_issued","admin_reset_requested","reset_delivery_failed")), "Security audit events")
                    check(php("echo validNewPassword(str_repeat('x',72)) ? 'yes' : 'no';")=="yes", "72-byte boundary")
                    check(php("echo validNewPassword(str_repeat('x',73)) ? 'yes' : 'no';")=="no", "73-byte boundary")
                    check(php("echo validNewPassword(str_repeat('é',12)) ? 'yes' : 'no';")=="yes", "Unicode character policy")
                    check(php("echo validNewPassword(str_repeat('é',6)) ? 'yes' : 'no';")=="no", "Unicode minimum counts characters")
                    from concurrent.futures import ThreadPoolExecutor
                    concurrent = issue()
                    code = "echo consumePasswordResetToken('" + concurrent + "','fictional-race-password') ? 'yes' : 'no';"
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        outcomes = list(pool.map(php, [code, code]))
                    check(sorted(outcomes)==["no","yes"], "Concurrent token use succeeds exactly once")
                    with ThreadPoolExecutor(max_workers=2) as pool:
                        list(pool.map(lambda _: issue(), range(2)))
                    check(sql("USE shelter_test; SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=2;").strip()=="1", "Concurrent issuance leaves one token")
                    reset_rates()
                    check(login(employee,"employee","fictional-race-password")[0]==200, "Login before account edit")
                    pending = issue()
                    profile = dict(first_name="Fictional",last_name="Employee",job_title="Tester",email="employee@example.invalid",badge_number="EMPLOYEE")
                    check(post(admin,"/admin/employees/2",profile)[0]==200, "Deactivate employee")
                    check(consume(pending)[0]==400, "Deactivation revokes reset")
                    profile["active"]="1"
                    check(post(admin,"/admin/employees/2",profile)[0]==200, "Reactivate employee")
                    check(request(employee,"/")[2].url.endswith("/login"), "Reactivation cannot restore stale session")
                    check(login(employee,"employee","fictional-race-password")[0]==200, "Login before email edit")
                    pending = issue()
                    profile["email"]="updated@example.invalid"
                    check(post(admin,"/admin/employees/2",profile)[0]==200, "Edit recovery email")
                    check(consume(pending)[0]==400 and request(employee,"/")[2].url.endswith("/login"), "Email edit revokes reset and session")
                    # Configuration failures must fail before issuing a token.
                    config_code = "require 'app/ForgotPassword.php'; putenv('APP_URL=http://insecure.invalid'); echo passwordResetEmailConfigured() ? 'yes' : 'no';"
                    check(php(config_code)=="no", "Non-HTTPS recovery origin rejected")
                    check(php("try { issuePasswordResetToken(2,'employee@example.invalid'); echo 'bad'; } catch (DomainException $e) { echo 'safe'; }")=="safe", "Changed recovery address rejected")
                    reset_rates()
                    check(login(employee,"updated","fictional-race-password")[0]==200,"Login for mail settings permission check")
                    check(request(employee,"/admin/email")[0]==403,"Mail settings require administrator")
                    check(request(admin,"/admin/email",{"_csrf":"invalid","enabled":"1","from_address":"configured@example.invalid"})[0]==419,"Mail settings CSRF")
                    check(post(admin,"/admin/email",{"enabled":"1","from_address":""})[0]==422,"Sender required")
                    capture.unlink(missing_ok=True)
                    check(post(admin,"/admin/email",{"enabled":"1","from_address":"configured@example.invalid"})[0]==200,"Sender configurable")
                    check(not capture.exists(),"Saving settings sends no message")
                    forgot("updated@example.invalid")
                    check(capture.exists() and "From: configured@example.invalid" in capture.read_text(),"Configured sender used by local mail capture")
                    reset_rates()
                    check(post(admin,"/admin/email",{"from_address":"configured@example.invalid"})[0]==200,"Reset email disable configurable")
                    capture.unlink(missing_ok=True)
                    before=sql("USE shelter_test; SELECT COUNT(*) FROM password_reset_tokens;").strip()
                    forgot("updated@example.invalid")
                    check(not capture.exists() and sql("USE shelter_test; SELECT COUNT(*) FROM password_reset_tokens;").strip()==before,"Disabled mail issues no new token or message")
                    sql("USE shelter_test; UPDATE app_email_settings SET configured=0,enabled=0,from_address='' WHERE id=1;")
                    reset_rates()
                    import sys, runpy
                    if "--browser" in sys.argv:
                        browser_test = runpy.run_path(str(ROOT / "tests/browser_password.py"))["run_browser"]
                        for browser_name in ("chromium", "firefox"):
                            browser_test(base, capture, sql, password_hash, browser_name)
                    print("PASS: isolated password/reset/session/permission/CSRF/rate-limit/audit/concurrency tests; mail captured locally.")
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
