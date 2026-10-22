"""Incident facility authorization in the disposable integration environment."""
import http.cookiejar
import urllib.request

def run_tests(sql, request, csrf, check, password_hash, root):
    # Validate migration 019 on a database containing an older unscoped report.
    legacy_schema=(root/"database/schema.sql").read_text().split("-- ===== 019_incident_facility_access.sql =====")[0]
    sql("CREATE DATABASE incident_upgrade_test; USE incident_upgrade_test;\n"+legacy_schema)
    sql("USE incident_upgrade_test; INSERT INTO users(id,email,badge_number,password_hash) VALUES(1,'legacy@example.invalid','LEGACY','unused');"
        "INSERT INTO incidents(category,severity,location,description,reported_by,occurred_at) VALUES('Other','Low','Synthetic legacy','Preserve this report',1,NOW());")
    sql("USE incident_upgrade_test;\n"+(root/"database/migrations/019_incident_facility_access.sql").read_text())
    check(sql("USE incident_upgrade_test; SELECT COUNT(*) FROM incidents WHERE description='Preserve this report' AND facility_id IS NULL;").strip()=="1","Upgrade preserves legacy report without guessing facility")
    def query(s):
        return sql("USE shelter_test; "+s).strip()
    for uid,label in ((10,"incidentnorth"),(11,"incidentsouth"),(12,"incidentunassigned"),(13,"incidentnorole")):
        query("INSERT INTO users(id,email,badge_number,password_hash) VALUES(%d,'%s@example.invalid','INC-%d','%s');"%(uid,label,uid,password_hash))
    query("INSERT INTO user_roles SELECT u.id,r.id FROM users u CROSS JOIN roles r WHERE u.id IN(10,11,12) AND r.name='Staff';"
          "INSERT INTO incident_facility_access VALUES(10,1),(11,2),(13,1);"
          "INSERT INTO incidents(id,facility_id,category,severity,location,description,reported_by,occurred_at) VALUES"
          "(100,1,'Medical','Low','North fixture','North private report',10,NOW()),"
          "(101,2,'Safety','Low','South fixture','South private report',11,NOW()),"
          "(102,NULL,'Property','Low','Legacy fixture','Legacy private report',1,NOW());"
          "INSERT INTO incident_notes(incident_id,author_id,body) VALUES(101,11,'South private note');"
          "INSERT INTO incident_people(incident_id,display_name,person_type,involvement,added_by) VALUES(101,'South fictional witness','Unknown','Witness',11);")
    def login(label):
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _,body,_=request("/login",client=client)
        check(request("/login",{"_csrf":csrf(body),"identifier":label+"@example.invalid","password":"fictional-test-password"},client)[0]==200,"Incident employee login")
        return client
    north,south,unassigned,norole=(login(x) for x in ("incidentnorth","incidentsouth","incidentunassigned","incidentnorole"))
    def get(path,client=north):
        return request(path,client=client)
    def post(path,values,client=north,token_path=None):
        _,body,_=get(token_path or path,client)
        return request(path,dict(_csrf=csrf(body),**values),client)
    status,body,response=get("/incidents")
    check(status==200 and '/incidents/100"' in body and '/incidents/101"' not in body and '/incidents/102"' not in body,"Incident list scope and legacy privacy")
    check(response.headers["Cache-Control"]=="no-store","Incident no-store")
    check("North private report" in get("/incidents/100")[1],"Own report visible")
    status,body,_=get("/incidents/101")
    check(status==404 and all(x not in body for x in ("South private","South fictional")),"Foreign detail, notes and people hidden")
    check(get("/incidents/102")[0]==404 and get("/incidents/99999")[0]==404,"Legacy and unknown reports hidden")
    check("Legacy private report" in get("/incidents/102",None)[1],"Administrator retains legacy access")
    body=get("/incidents/new")[1]
    check("Fictional Shelter" in body and "Other Fictional Shelter" not in body,"Incident form facilities scoped")
    form={"category":"Conflict","severity":"Low","location":"Synthetic location","description":"New synthetic report","facility_id":1}
    before=query("SELECT COUNT(*) FROM incidents;")
    check(post("/incidents",dict(form,facility_id=2),token_path="/incidents/new")[0]==422,"Forged report facility denied")
    check(post("/incidents",dict(form,facility_id=""),token_path="/incidents/new")[0]==422,"Missing report facility denied")
    check(query("SELECT COUNT(*) FROM incidents;")==before,"Denied reports do not write")
    check(post("/incidents",form,token_path="/incidents/new")[0]==200,"Scoped report creation")
    check(query("SELECT facility_id FROM incidents WHERE description='New synthetic report';")=="1","Created report assigned correctly")
    _,body,_=get("/incidents/100")
    token=csrf(body)
    for kind,values,table in (("notes",{"body":"Allowed synthetic note"},"incident_notes"),("people",{"display_name":"Allowed fictional witness","person_type":"Unknown","involvement":"Witness"},"incident_people")):
        before=query("SELECT COUNT(*) FROM "+table+";")
        for hidden in (101,102,99999):
            check(request("/incidents/%d/%s"%(hidden,kind),dict(_csrf=token,**values),north)[0]==404,"Guessed foreign/legacy mutation denied")
        check(query("SELECT COUNT(*) FROM "+table+";")==before,"Denied incident children do not write")
        check(request("/incidents/100/"+kind,dict(_csrf="invalid",**values),north)[0]==419,"Incident mutation requires CSRF")
        check(request("/incidents/100/"+kind,dict(_csrf=token,**values),north)[0]==200,"Own incident child added")
    check("Allowed synthetic note" in get("/incidents/100")[1] and "Allowed fictional witness" in get("/incidents/100")[1],"Incident children readable in scope")
    for client in (unassigned,norole):
        check(get("/incidents",client)[0]==403 and get("/incidents/new",client)[0]==403,"Both role permission and assignment required")
    # Resident assignment alone does not confer incident access.
    residentonly=login("north")
    check(get("/incidents",residentonly)[0]==403,"Resident assignment cannot grant incident access")
    check(get("/admin/residents")[0]==403 and get("/admin/employees")[0]==403,"Incident assignment does not grant resident or admin access")
    path="/admin/employees/10/incident-facilities"
    check(get(path)[0]==403 and request(path,{"facility_ids[]":2},north)[0]==403,"Employees cannot assign incident access")
    check(request(path,{"_csrf":"invalid"},None)[0]==419,"Incident assignments require CSRF")
    check(post(path,{"facility_ids[]":"99999"},None)[0]==422,"Unknown incident facility rejected")
    check(query("SELECT facility_id FROM incident_facility_access WHERE user_id=10;")=="1","Invalid incident assignment unchanged")
    check(post(path,{},None)[0]==200 and get("/incidents")[0]==403,"Incident revocation immediate")
    check(post(path,{"facility_ids[]":"1"},None)[0]==200 and get("/incidents")[0]==200,"Incident assignment restored")
    check(query("SELECT COUNT(*) FROM audit_events WHERE event_name='incident_facility_access_updated' AND entity_id=10;")=="2","Incident assignment changes audited")
    query("UPDATE facilities SET active=0 WHERE id=1;")
    check(get("/incidents")[0]==403,"Inactive facility denies incident access")
    check(post("/admin/employees/11/incident-facilities",{"facility_ids[]":"1"},None)[0]==422,"Inactive incident facility cannot be assigned")
    query("UPDATE facilities SET active=1 WHERE id=1; UPDATE users SET active=0 WHERE id=10;")
    check(get("/incidents")[2].url.endswith("/login"),"Deactivated employee session invalidated")
    query("UPDATE users SET active=1 WHERE id=10;")
    check("South private report" in get("/incidents/101",south)[1],"Other assigned facility remains available")
    # Historical attribution is a one-time administrative action.
    path="/incidents/102/facility"
    check(get(path,None)[0]==405,"Attribution accepts POST only")
    check(request(path,{"facility_id":2},south)[0]==403,"Assigned employee cannot attribute reports")
    check(request(path,{"_csrf":"invalid","facility_id":2},None)[0]==419,"Attribution requires CSRF")
    _,adminbody,_=get("/incidents/102",None)
    admin_token=csrf(adminbody)
    for value in ("",0,-1,"99999"):
        check(request(path,{"_csrf":admin_token,"facility_id":value},None)[0]==422,"Invalid attribution facility denied")
    check(request(path,{"_csrf":admin_token,"facility_id[]":2},None)[0]==422,"Array attribution facility denied")
    query("UPDATE facilities SET active=0 WHERE id=2;")
    check(request(path,{"_csrf":admin_token,"facility_id":2},None)[0]==422,"Inactive attribution facility denied")
    query("UPDATE facilities SET active=1 WHERE id=2;")
    check(query("SELECT facility_id IS NULL FROM incidents WHERE id=102;")=="1","Denied attribution preserves ownership")
    check(query("SELECT COUNT(*) FROM audit_events WHERE event_name='incident_facility_assigned';")=="0","Denied attribution has no success audit")
    check(request("/incidents/99999/facility",{"_csrf":admin_token,"facility_id":2},None)[0]==404,"Absent incident attribution denied")
    query("INSERT INTO incident_notes(incident_id,author_id,body) VALUES(102,1,'Historical synthetic note');")
    query("CREATE TRIGGER reject_synthetic_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure';")
    try:
        check(request(path,{"_csrf":admin_token,"facility_id":2},None)[0]==500,"Audit failure prevents successful attribution")
        check(query("SELECT facility_id IS NULL FROM incidents WHERE id=102;")=="1","Failed audit rolls back attribution")
    finally:
        query("DROP TRIGGER reject_synthetic_audit;")
    check(request(path,{"_csrf":admin_token,"facility_id":2},None)[0]==200,"Administrator assigns historical incident")
    check("Legacy private report" in get("/incidents/102",south)[1] and "Historical synthetic note" in get("/incidents/102",south)[1],"Assigned facility can now read historical report and notes")
    check(get("/incidents/102",login("incidentnorth"))[0]==404,"Other facility remains denied after attribution")
    check(request(path,{"_csrf":admin_token,"facility_id":1},None)[0]==409,"Repeated attribution cannot move report")
    check(query("SELECT facility_id FROM incidents WHERE id=102;")=="2","Assigned ownership immutable through attribution")
    check(query("SELECT COUNT(*) FROM audit_events WHERE actor_user_id=1 AND event_name='incident_facility_assigned' AND entity_type='incident' AND entity_id=102;")=="1","Exactly one attribution audit records administrator and report")
    check('action="/incidents/102/facility"' not in get("/incidents/102",None)[1],"Assigned reports no longer offer attribution form")
    print("PASS: incident scope, role separation, private details/children, forged mutations, assignment controls, revocation, and legacy upgrade.")
