"""Browser acceptance for deliberate historical incident attribution."""
def run_browser(base,sql,browser_name):
    from playwright.sync_api import sync_playwright, expect
    incident=300 if browser_name=="chromium" else 301
    sql("USE shelter_test; INSERT INTO incidents(id,category,severity,location,description,reported_by,occurred_at) VALUES(%d,'Other','Low','Fictional browser location','Historical browser fixture',1,NOW());"%incident)
    with sync_playwright() as playwright:
        browser=getattr(playwright,browser_name).launch(headless=True)
        context=browser.new_context(base_url=base,viewport={"width":390,"height":844})
        context.route("**/*",lambda route: route.continue_() if route.request.url.startswith(base+"/") else route.abort())
        page=context.new_page()
        try:
            def login(identifier):
                page.goto(base+"/login")
                page.locator('[name="identifier"]').fill(identifier+"@example.invalid")
                page.locator('[name="password"]').fill("fictional-test-password")
                page.get_by_role("button",name="Sign in",exact=True).click()
                expect(page.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            login("test")
            page.goto(base+"/incidents/"+str(incident))
            expect(page.get_by_role("heading",name="Assign historical report to a facility",exact=True)).to_be_visible()
            page.locator('[name="facility_id"]').select_option("2")
            assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"),"Mobile attribution form fits screen"
            page.get_by_role("button",name="Assign facility and grant facility access",exact=True).click()
            expect(page.get_by_role("heading",name="Incident #"+str(incident),exact=True)).to_be_visible()
            expect(page.locator("main")).to_contain_text("Other Fictional Shelter")
            expect(page.locator('[name="facility_id"]')).to_have_count(0)
            page.get_by_role("button",name="Log out",exact=True).click()
            login("incidentsouth")
            page.goto(base+"/incidents/"+str(incident))
            expect(page.locator("main")).to_contain_text("Historical browser fixture")
            expect(page.locator('[name="facility_id"]')).to_have_count(0)
            assert sql("USE shelter_test; SELECT COUNT(*) FROM audit_events WHERE event_name='incident_facility_assigned' AND entity_id=%d;"%incident).strip()=="1"
            print("PASS: "+browser_name+" historical incident attribution (administrator form, mobile width, facility visibility, audit).")
        finally:
            browser.close()
