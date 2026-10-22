"""Facility authorization checks, called only inside the disposable integration harness."""
import http.cookiejar
import json
import urllib.request

def run_tests(sql, request, csrf, check, password_hash):
    def query(statement):
        return sql("USE shelter_test; " + statement).strip()
    for uid, label in ((3,"north"),(4,"south"),(5,"revoked")):
        query("INSERT INTO users(id,email,badge_number,password_hash) VALUES(%d,'%s@example.invalid','SCOPE-%d','%s');" % (uid,label,uid,password_hash))
    query("INSERT INTO resident_facility_access VALUES(3,1),(4,2),(5,1);"
          "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES"
          "(200,1,'SCOPE-NORTH','Scope North Resident'),(201,2,'SCOPE-SOUTH','Scope South Resident'),(202,1,'SCOPE-LEGACY','Scope Legacy Resident');"
          "INSERT INTO beds(id,room_id,code) VALUES(200,1,'NORTH-SCOPE-BED'),(201,2,'SOUTH-SCOPE-BED');")
    def login(label):
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _,body,_=request("/login",client=client)
        result=request("/login",{"_csrf":csrf(body),"identifier":label+"@example.invalid","password":"fictional-test-password"},client)
        check(result[0]==200,"Scoped employee login")
        return client
    north,south,revoked=(login(label) for label in ("north","south","revoked"))
    def get(path,client=north):
        return request(path,client=client)
    def post(path,values,client=north):
        _,body,_=get(path,client)
        return request(path,dict(_csrf=csrf(body),**values),client)
    for client,own,hidden in ((north,"Scope North Resident","Scope South Resident"),(south,"Scope South Resident","Scope North Resident")):
        status,body,response=get("/admin/residents",client)
        check(status==200 and own in body and hidden not in body,"Resident list facility isolation")
        check(response.headers["Cache-Control"]=="no-store","Private resident response")
        check(get("/admin/employees",client)[0]==403,"Assignment does not grant administrative access")
        status,body,_=get("/api/residents/search?q=Scope",client)
        results=json.loads(body)
        check(status==200 and any(own in str(row) for row in results) and hidden not in body,"Search facility isolation")
    check(json.loads(get("/api/residents/search?q=SCOPE-SOUTH")[1])==[],"Exact foreign reference hidden")
    check(get("/admin/residents/200/history")[0]==200,"Own history accessible")
    check(get("/admin/residents/201/history")[0]==404 and get("/admin/residents/99999/history")[0]==404,"Foreign history indistinguishable from absent")
    body=get("/admin/occupancy")[1]
    check("NORTH-SCOPE-BED" in body and "SOUTH-SCOPE-BED" not in body and "OTHER-BED" not in body,"Bed options scoped")
    before=query("SELECT COUNT(*) FROM residents;")
    check(post("/admin/residents",{"facility_id":2,"display_name":"Scope Forbidden"})[0]==422,"Tampered intake facility denied")
    check(query("SELECT COUNT(*) FROM residents;")==before,"Denied intake does not write")
    check(post("/admin/residents",{"facility_id":1,"display_name":"Scope Registered"})[0]==200,"Assigned intake succeeds")
    for resident,bed in ((201,201),(200,201),(201,200)):
        check(post("/admin/occupancy",{"action":"checkin","resident_id":resident,"bed_id":bed})[0]==422,"Forged admission denied")
    for client,resident,bed in ((north,200,200),(south,201,201)):
        check(post("/admin/occupancy",{"action":"checkin","resident_id":resident,"bed_id":bed},client)[0]==200,"Assigned admission succeeds")
    foreign=query("SELECT id FROM stays WHERE resident_id=201 AND checked_out_at IS NULL;")
    departure={"action":"checkout","stay_id":foreign,"departure_type":"voluntary","departure_reason":"Synthetic facility test"}
    check(post("/admin/occupancy",departure)[0]==422,"Foreign stay cannot be closed")
    check(query("SELECT COUNT(*) FROM stays WHERE id="+foreign+" AND checked_out_at IS NULL;")=="1","Foreign stay preserved")
    check("Scope South Resident" not in get("/admin/occupancy")[1],"Foreign occupancy hidden")
    for client,resident in ((north,200),(south,201)):
        stay=query("SELECT id FROM stays WHERE resident_id=%d AND checked_out_at IS NULL;"%resident)
        check(post("/admin/occupancy",dict(departure,stay_id=stay),client)[0]==200,"Assigned checkout succeeds")
    # Defensive behavior for an inconsistent legacy stay spanning facilities.
    query("INSERT INTO stays(resident_id,bed_id,checked_in_at) VALUES(202,201,NOW());")
    legacy=query("SELECT id FROM stays WHERE resident_id=202 AND checked_out_at IS NULL;")
    check("SOUTH-SCOPE-BED" not in get("/admin/residents")[1],"Legacy foreign bed hidden from resident list")
    check("SOUTH-SCOPE-BED" not in get("/admin/residents/202/history")[1],"Legacy foreign bed hidden from history")
    check(post("/admin/occupancy",dict(departure,stay_id=legacy))[0]==422,"Legacy foreign stay cannot be mutated")
    check(post("/admin/occupancy",dict(departure,stay_id=legacy),None)[0]==200,"Global administrator can repair legacy stay")
    path="/admin/employees/3/resident-facilities"
    check(get(path)[0]==403 and request(path,{"facility_ids[]":"2"},north)[0]==403,"Employee cannot assign own access")
    check(request(path,{"_csrf":"invalid"},None)[0]==419,"Assignment requires CSRF")
    check(post(path,{"facility_ids[]":"99999"},None)[0]==422,"Unknown assignment rejected")
    check(query("SELECT facility_id FROM resident_facility_access WHERE user_id=3;")=="1","Invalid assignment leaves access intact")
    _,body,_=get(path,None)
    check(request(path,[("_csrf",csrf(body)),("facility_ids[]","1"),("facility_ids[]","1")],None)[0]==422,"Duplicate assignments rejected")
    check(post(path,{},None)[0]==200,"Administrator revokes assignments")
    check(get("/admin/residents")[0]==403 and get("/api/residents/search?q=Scope")[0]==403,"Revocation effective in existing session")
    check(post(path,{"facility_ids[]":"1"},None)[0]==200 and get("/admin/residents")[0]==200,"Administrator restores assigned access")
    check(query("SELECT COUNT(*) FROM audit_events WHERE event_name='resident_facility_access_updated' AND entity_id=3;")=="2","Assignment changes audited")
    check(query("SELECT COUNT(*) FROM user_roles WHERE user_id=3;")=="0","Assignments do not grant roles")
    query("UPDATE facilities SET active=0 WHERE id=1;")
    check(get("/admin/residents",revoked)[0]==403,"Inactive facility grants no access")
    query("UPDATE facilities SET active=1 WHERE id=1;")
    check("Scope South Resident" in get("/admin/residents",None)[1],"Global administrator retains all-facility access")
    print("PASS: facility authorization for lists, search, history, intake, stays, assignments, revocation, and legacy isolation.")
