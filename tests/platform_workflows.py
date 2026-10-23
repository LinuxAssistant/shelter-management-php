"""Complete operational workflows tested against fictional disposable fixtures."""
import http.cookiejar
import urllib.request

def run_tests(sql,request,csrf,check,password_hash,root):
    def query(s):
        return sql("USE shelter_test; "+s).strip()
    labels=["ops","kitchen","finance","volunteer","case","southmodules","nomodules"]
    for uid,label in enumerate(labels,20):
        query("INSERT INTO users(id,email,badge_number,password_hash) VALUES(%d,'%s@example.invalid','MOD-%d','%s');"%(uid,label,uid,password_hash))
    query("INSERT INTO staff_facility_permissions VALUES(20,1,'operations'),(21,1,'kitchen'),(22,1,'finance'),(23,1,'volunteers'),(24,1,'casework'),(25,2,'operations'),(25,2,'kitchen'),(25,2,'finance'),(25,2,'volunteers'),(25,2,'casework');"
          "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES(400,1,'WORK-NORTH','Workflow North'),(401,2,'WORK-SOUTH','Workflow South');"
          "INSERT INTO volunteers(id,facility_id,display_name) VALUES(400,1,'North volunteer fixture'),(401,2,'South volunteer fixture');"
          "INSERT INTO kitchen_inventory(id,facility_id,item_name,quantity,unit,storage_location) VALUES(400,1,'North stock fixture',5,'kg','Pantry'),(401,2,'South stock fixture',9,'kg','Other pantry');")
    def login(label):
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        _,body,_=request("/login",client=client)
        check(request("/login",{"_csrf":csrf(body),"identifier":label+"@example.invalid","password":"fictional-test-password"},client)[0]==200,"Module employee login")
        return client
    ops,kitchen,finance,volunteer,case,south,denied=[login(x) for x in labels]
    def get(path,client=None):
        return request(path,client=client)
    def post(path,values,client=None,token_path=None):
        _,body,_=get(token_path or path,client)
        return request(path,dict(_csrf=csrf(body),**values),client)
    paths=[(ops,["/admin/chores","/admin/schedules","/admin/programs","/admin/daytime-exceptions","/admin/daytime-food"]),
           (kitchen,["/inventory","/admin/platform/kitchen-inventory","/admin/platform/kitchen-meals","/admin/platform/kitchen-temperatures","/admin/platform/kitchen-waste"]),
           (finance,["/admin/platform/donations","/admin/platform/expenses"]),(volunteer,["/admin/platform/volunteers","/volunteer-shifts"]),(case,["/cases","/cases/400"])]
    for client,routes in paths:
        for path in routes:
            status,body,response=get(path,client)
            check(status==200,"Assigned module page "+path)
            check(response.headers["Cache-Control"]=="no-store","Private workflow no-store")
            check("Workflow South" not in body and "South stock fixture" not in body and "South volunteer fixture" not in body,"Foreign module records hidden")
            check(get(path,denied)[0]==403,"Unassigned module denied")
        check(get("/admin/employees",client)[0]==403,"Module cannot grant employee administration")
    check(get("/cases",ops)[0]==403 and get("/admin/platform/donations",kitchen)[0]==403,"Module separation")
    schedule={"facility_id":1,"daytime_exit":"09:00","normal_return":"17:00","daytime_access_policy":"Synthetic policy"}
    program={"facility_id":1,"provider_name":"Test provider","title":"North program fixture","topic":"Testing","location":"Test room","starts_at":"2026-10-24T10:00","ends_at":"2026-10-24T11:00"}
    chore={"facility_id":1,"title":"North chore fixture","area":"Test area","description":"Synthetic","due_at":"2026-10-24T10:00","recurrence":"none","assignee_type":"resident","assignee_id":400}
    exception={"facility_id":1,"resident_id":400,"reason":"case_manager","starts_at":"2026-10-24T10:00","ends_at":"2026-10-24T11:00","notes":"Synthetic exception"}
    food={"facility_id":1,"exception_id":"","food_description":"North food fixture","source":"donated","portions_served":3,"served_at":"2026-10-24T10:00","notes":""}
    for path,values in [("/admin/schedules",schedule),("/admin/programs",program),("/admin/chores",chore),("/admin/daytime-exceptions",exception),("/admin/daytime-food",food)]:
        check(post(path,dict(values,facility_id=2),ops)[0]==422,"Foreign operation write denied "+path)
        check(post(path,values,ops)[0]==200,"Assigned operation write "+path)
    check(post("/admin/chores",dict(chore,assignee_id=401),ops)[0]==422,"Foreign resident chore denied")
    check(post("/admin/daytime-exceptions",dict(exception,resident_id=401),ops)[0]==422,"Foreign resident exception denied")
    query("INSERT INTO chores(facility_id,title,due_at) VALUES(2,'South chore fixture','2026-10-24 10:00');")
    foreign=query("SELECT id FROM chores WHERE title='South chore fixture';")
    check(post("/admin/chores",{"chore_id":foreign,"status":"completed"},ops)[0]==404,"Guessed foreign chore update denied")
    own=query("SELECT id FROM chores WHERE title='North chore fixture';")
    check(post("/admin/chores",{"chore_id":own,"status":"completed"},ops)[0]==200,"Assigned chore completed")
    note={"action":"note","body":"Private case fixture <script>alert(1)</script>"}
    check(post("/cases/400",note,case)[0]==200,"Private note saved")
    check("&lt;script&gt;" in get("/cases/400",case)[1] and "<script>alert(1)</script>" not in get("/cases/400",case)[1],"Case output escaped")
    check(get("/cases/401",case)[0]==404 and request("/cases/401",dict(note,_csrf="test"),case)[0]==404,"Foreign case read/write denied")
    check("Private case fixture" not in get("/incidents",None)[1],"Case notes separate from incidents")
    appointment={"action":"appointment","title":"North appointment fixture","location":"Private office","starts_at":"2026-10-24T11:00","ends_at":"2026-10-24T12:00"}
    check(post("/cases/400",dict(appointment,ends_at="2026-10-24T10:00"),case)[0]==422,"Invalid appointment range denied")
    check(post("/cases/400",appointment,case)[0]==200,"Appointment scheduled")
    own=query("SELECT id FROM resident_appointments WHERE title='North appointment fixture';")
    check(post("/cases/400",{"action":"appointment_status","appointment_id":own,"status":"completed"},case)[0]==200,"Appointment completed")
    check(post("/cases/400",{"action":"appointment_status","appointment_id":99999,"status":"completed"},case)[0]==422,"Guessed appointment denied")
    shift={"action":"create","volunteer_id":400,"starts_at":"2026-10-24T10:00","ends_at":"2026-10-24T12:00"}
    check(post("/volunteer-shifts",dict(shift,volunteer_id=401),volunteer)[0]==422,"Foreign volunteer shift denied")
    check(post("/volunteer-shifts",shift,volunteer)[0]==200,"Volunteer shift scheduled")
    check(post("/volunteer-shifts",shift,volunteer)[0]==422,"Overlapping shift denied")
    shift_id=query("SELECT id FROM volunteer_shifts WHERE volunteer_id=400;")
    check(post("/volunteer-shifts",{"action":"status","shift_id":shift_id,"status":"completed"},volunteer)[0]==200,"Volunteer shift approved")
    check(query("SELECT approved_by FROM volunteer_shift_details WHERE shift_id="+shift_id+";")=="23","Volunteer approval attributed")
    movement={"inventory_id":400,"quantity_change":"-6","reason":"Synthetic consumption"}
    check(post("/inventory",movement,kitchen)[0]==422 and query("SELECT quantity FROM kitchen_inventory WHERE id=400;")=="5.00","Negative stock prevented")
    check(post("/inventory",dict(movement,inventory_id=401,quantity_change="1"),kitchen)[0]==422,"Foreign stock denied")
    check(post("/inventory",dict(movement,quantity_change="2"),kitchen)[0]==200,"Stock received")
    check(post("/inventory",dict(movement,quantity_change="-3"),kitchen)[0]==200,"Stock consumed")
    check(query("SELECT quantity FROM kitchen_inventory WHERE id=400;")=="4.00","Stock movement balance")
    check(query("SELECT COUNT(*) FROM inventory_movements WHERE inventory_id=400;")=="2","Stock movement ledger")
    expense={"facility_id":1,"description":"North expense fixture","amount":"12.50","spent_at":"2026-10-22T10:00"}
    donation={"facility_id":1,"donor_label":"North donor fixture","kind":"cash","amount":"50.00","description":"Synthetic donation","received_at":"2026-10-22T10:00","item_category":"","quantity":"","unit":"","estimated_value":""}
    for path,values in [("/admin/platform/expenses",expense),("/admin/platform/donations",donation)]:
        check(post(path,dict(values,facility_id=2),finance)[0]==422,"Foreign financial write denied")
        check(post(path,values,finance)[0]==200,"Assigned financial write saved")
    check("50.00" in get("/reports",finance)[1] and "Private case fixture" not in get("/reports",finance)[1],"Scoped financial report")
    check("Cash donations" not in get("/reports",kitchen)[1],"Reports respect independent modules")
    _,body,_=get("/login",denied)
    # Public pages do not require staff authentication or reveal private fixture content.
    anon=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    for path in ["/","/welcome","/services","/contact"]:
        status,body,_=get(path,anon)
        check(status==200 and "Private case fixture" not in body and "North donor fixture" not in body,"Public page privacy")
    check(get("/admin/audit",case)[0]==403 and get("/admin/public-site",case)[0]==403,"Restricted administration")
    settings={"site_name":"Fictional Shelter Public","introduction":"Welcome <script>unsafe</script>","services":"Meals and shelter","contact_text":"Ask the front desk"}
    check(post("/admin/public-site",settings)[0]==200,"Public settings saved")
    check("Fictional Shelter Public" in get("/welcome",anon)[1] and "&lt;script&gt;" in get("/welcome",anon)[1],"Public settings escaped")
    # Intake lookup, duplicate check and explicit distinct-person handling.
    intake=login("north")
    check("Workflow North" in get("/admin/residents?q=WORK-NORTH",intake)[1],"Intake lookup includes existing records")
    check(post("/admin/residents",{"facility_id":1,"display_name":" workflow north "},intake)[0]==409,"Accidental duplicate intake prevented")
    check(post("/admin/residents",{"facility_id":1,"display_name":"Workflow North","distinct_person":"1"},intake)[0]==200,"Deliberate distinct person allowed")
    check(get("/admin/residents/401/edit",intake)[0]==404,"Foreign intake update hidden")
    check(post("/admin/residents/400/edit",{"display_name":"Updated workflow north"},intake)[0]==200,"Intake update saved")
    check(query("SELECT reference_code FROM residents WHERE id=400;")=="WORK-NORTH","Intake reference remains stable")
    # Module assignments can be changed only by administrators and revoke active sessions.
    path="/admin/employees/20/module-facilities"
    check(get(path,ops)[0]==403 and request(path,{"grants[]":"2:finance"},ops)[0]==403,"Cannot self-grant module access")
    check(post(path,{"grants[]":"99999:operations"})[0]==422,"Invalid facility grant denied")
    check(post(path,{"grants[]":"1:admin"})[0]==422,"Unknown capability denied")
    check(post(path,{})[0]==200 and get("/admin/chores",ops)[0]==403,"Module revocation immediate")
    check(post(path,{"grants[]":"1:operations"})[0]==200 and get("/admin/chores",ops)[0]==200,"Module assignment restored")
    query("UPDATE facilities SET active=0 WHERE id=1;")
    check(get("/inventory",kitchen)[0]==403 and get("/cases",case)[0]==403,"Inactive facility denies modules")
    query("UPDATE facilities SET active=1 WHERE id=1;")
    query("INSERT INTO chores(facility_id,title,due_at,recurrence) VALUES(1,'Recurring fixture','2026-01-01 10:00','daily');")
    recurring=query("SELECT id FROM chores WHERE title='Recurring fixture';")
    for _ in range(2):
        check(post("/admin/chores",{"chore_id":recurring,"status":"completed"},ops)[0]==200,"Recurring chore completion")
    check(query("SELECT COUNT(*) FROM chore_successors WHERE parent_chore_id="+recurring+";")=="1","Repeated completion creates one successor")
    check(query("SELECT COUNT(*) FROM chores c JOIN chore_successors s ON s.next_chore_id=c.id WHERE s.parent_chore_id="+recurring+" AND c.status='pending' AND c.due_at>NOW();")=="1","Recurring chore advances to future occurrence")
    # Administrative edits preserve scope, ownership and protected ledgers.
    check(get("/admin/platform/volunteers?edit=400",volunteer)[0]==200,"Edit accessible volunteer")
    check(post("/admin/platform/volunteers",{"edit_record_id":400,"facility_id":1,"display_name":"Updated volunteer fixture","active":"1"},volunteer)[0]==200,"Volunteer edit saved")
    check(post("/admin/platform/volunteers",{"edit_record_id":401,"facility_id":1,"display_name":"Forbidden edit","active":"1"},volunteer)[0]==422,"Foreign edit cannot change ownership")
    stock={"edit_record_id":400,"facility_id":1,"item_name":"North stock fixture","quantity":"999","unit":"kg","storage_location":"Pantry","expires_on":""}
    check(post("/admin/platform/kitchen-inventory",stock,kitchen)[0]==422,"Direct quantity edit cannot bypass stock ledger")
    check(post("/admin/platform/kitchen-inventory",dict(stock,quantity="4.00",storage_location="Updated pantry"),kitchen)[0]==200,"Inventory metadata edit saved")
    bed={"edit_record_id":1,"room_id":1,"code":"TEST-BED","bed_type":"single","position":"single"}
    query("INSERT INTO stays(resident_id,bed_id,checked_in_at) VALUES(1,1,NOW());")
    check(post("/admin/platform/beds",bed)[0]==422,"Occupied bed cannot be deactivated")
    check(post("/admin/platform/beds",dict(bed,active="1",position="upper"))[0]==422,"Invalid bed layout edit denied")
    query("UPDATE stays SET checked_out_at=NOW() WHERE resident_id=1 AND bed_id=1 AND checked_out_at IS NULL;")
    # Private profiles, case-worker assignment, referrals, and disclosure boundaries.
    profile={"action":"profile","caseworker_id":24,"emergency_contact":"Fictional emergency contact","consent_note":"Synthetic consent"}
    check(post("/cases/400",dict(profile,caseworker_id=25),case)[0]==422,"Foreign facility case worker rejected")
    check(post("/cases/400",profile,case)[0]==200,"Case profile and assignment saved")
    referral={"action":"referral","service":"Housing referral fixture","provider":"Fictional provider","notes":"Private referral fixture"}
    check(post("/cases/400",referral,case)[0]==200,"Referral created")
    refid=query("SELECT id FROM case_referrals WHERE resident_id=400;")
    check(post("/cases/400",{"action":"referral_status","referral_id":refid,"status":"completed"},case)[0]==200,"Referral completed")
    check(post("/cases/400",{"action":"referral_status","referral_id":99999,"status":"completed"},case)[0]==422,"Foreign or unknown referral rejected")
    check("Fictional emergency contact" in get("/cases/400",case)[1],"Private profile readable by case staff")
    check("Fictional emergency contact" not in get("/admin/residents",None)[1] and "Private referral fixture" not in get("/reports",None)[1],"Case privacy outside case workflow")
    # Financial receipts and reconciliation are scoped and immutable.
    donation_id=query("SELECT id FROM donations WHERE donor_label='North donor fixture';")
    receipt="/donations/"+donation_id+"/receipt"
    check(get(receipt,south)[0]==404 and get(receipt,ops)[0]==403,"Receipt facility/module isolation")
    check("DON-" in get(receipt,finance)[1],"Acknowledgment available")
    check(post(receipt,{"reference":"TEST-RECON-001"},finance)[0]==200,"Donation reconciled")
    check(post(receipt,{"reference":"TEST-RECON-002"},finance)[0]==422,"Reconciliation cannot be overwritten")
    query("INSERT INTO donations(facility_id,donor_label,kind,amount,received_at) VALUES(1,'=1+1','cash',1,NOW()),(2,'Foreign donor fixture','cash',900,NOW());")
    status,csv,response=get("/reports/donations.csv",finance)
    check(status==200 and "text/csv" in response.headers["Content-Type"] and "'=1+1" in csv and "Foreign donor fixture" not in csv,"Scoped CSV neutralizes spreadsheet formulas")
    check(get("/reports/donations.csv",ops)[0]==403,"Finance export permission")
    # Role management is independent and immediately applied.
    rolepath="/admin/employees/20/roles"
    check(get(rolepath,ops)[0]==403 and request(rolepath,{"role_ids[]":1},ops)[0]==403,"Cannot self-promote")
    check(post("/admin/employees/1/roles",{})[0]==422,"Cannot remove own administrator role")
    staff=query("SELECT id FROM roles WHERE name='Staff';")
    check(post(rolepath,{"role_ids[]":staff})[0]==200,"Administrator assigns staff role")
    check(get("/incidents",ops)[0]==403,"Incident role still requires facility assignment")
    manager=query("SELECT id FROM roles WHERE name='Manager';")
    check(post("/admin/employees/10/roles",{"role_ids[]":manager})[0]==200,"Administrator assigns incident reviewer role")
    reviewer=login("incidentnorth")
    _,body,_=get("/incidents/100",reviewer)
    check(request("/incidents/100/status",{"_csrf":csrf(body),"status":"Resolved"},reviewer)[0]==200,"Assigned incident reviewer resolves report")
    check(request("/incidents/101/status",{"_csrf":csrf(body),"status":"Resolved"},reviewer)[0]==422,"Reviewer cannot resolve foreign report")
    check(query("SELECT status FROM incidents WHERE id=101;")=="Open","Foreign review leaves report unchanged")
    # Administrator-configured mail identity and enablement; no messages are sent here.
    path="/admin/email"
    check(get(path,ops)[0]==403 and request(path,{"enabled":"1","from_address":"sender@example.invalid"},ops)[0]==403,"Email settings require administrator")
    check(request(path,{"_csrf":"invalid","enabled":"1","from_address":"sender@example.invalid"})[0]==419,"Email settings require CSRF")
    check(post(path,{"enabled":"1","from_address":""})[0]==422,"Enabled mail requires sender")
    check(post(path,{"enabled":"1","from_address":"sender@example.invalid"+chr(13)+chr(10)+"Bcc: injected@example.invalid"})[0]==422,"Mail header injection rejected")
    check(post(path,{"enabled":"1","from_address":"sender@example.invalid"})[0]==200,"Administrator configures sender")
    check(query("SELECT CONCAT(configured,':',enabled,':',from_address) FROM app_email_settings WHERE id=1;")=="1:1:sender@example.invalid","Settings saved")
    check(post(path,{"from_address":"sender@example.invalid"})[0]==200,"Administrator disables reset mail")
    check(query("SELECT enabled FROM app_email_settings WHERE id=1;")=="0","Disabled setting persisted")
    # Forward upgrades retain old appointments and case notes.
    old=(root/"database/schema.sql").read_text().split("-- ===== 020_facility_module_permissions.sql =====")[0]
    sql("CREATE DATABASE platform_upgrade_test; USE platform_upgrade_test;\n"+old)
    sql("USE platform_upgrade_test; INSERT INTO users(id,email,badge_number,password_hash) VALUES(1,'upgrade@example.invalid','UPGRADE','unused');"
        "INSERT INTO facilities(id,name) VALUES(1,'Upgrade fixture');"
        "INSERT INTO residents(id,facility_id,reference_code,display_name) VALUES(1,1,'UPGRADE-1','Upgrade resident');"
        "INSERT INTO case_notes(resident_id,author_id,body) VALUES(1,1,'Preserved note');"
        "INSERT INTO resident_appointments(facility_id,resident_id,appointment_type,starts_at,ends_at) VALUES(1,1,'other','2026-10-24 10:00','2026-10-24 11:00');")
    for filename in ("020_facility_module_permissions.sql","021_platform_workflows.sql","022_casework_finance.sql","023_recurring_chores.sql","024_email_settings.sql"):
        sql("USE platform_upgrade_test;\n"+(root/"database/migrations"/filename).read_text())
    check(sql("USE platform_upgrade_test; SELECT title FROM resident_appointments;").strip()=="Appointment","Legacy appointments preserved")
    check(sql("USE platform_upgrade_test; SELECT body FROM case_notes;").strip()=="Preserved note","Legacy case notes preserved")
    print("PASS: operations, private cases/appointments, volunteer scheduling, stock ledger, reports, public site, intake, and module isolation.")
