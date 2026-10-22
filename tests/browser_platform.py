"""Browser smoke coverage for the complete fictional platform."""
from pathlib import Path

def run_browser(base,sql,browser_name):
    from playwright.sync_api import sync_playwright,expect
    with sync_playwright() as p:
        browser=getattr(p,browser_name).launch(headless=True)
        context=browser.new_context(base_url=base,viewport={"width":1280,"height":900})
        context.route("**/*",lambda route: route.continue_() if route.request.url.startswith(base+"/") else route.abort())
        page=context.new_page(); errors=[]
        page.on("pageerror",lambda error:errors.append(str(error)))
        try:
            page.goto(base+"/login")
            page.locator('[name="identifier"]').fill("test@example.invalid")
            page.locator('[name="password"]').fill("fictional-test-password")
            page.get_by_role("button",name="Sign in",exact=True).click()
            expect(page.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            for label in ("Residents","Safety","Case management","Daily operations","Kitchen","Donations and finances","Volunteers","Administration"):
                expect(page.get_by_role("heading",name=label,exact=True)).to_be_visible()
            evidence=Path.home()/"shelter-browser-evidence"/"pr6"; evidence.mkdir(parents=True,exist_ok=True)
            page.screenshot(path=str(evidence/(browser_name+"-platform-dashboard.png")),full_page=True)
            routes=["/cases/400","/volunteer-shifts","/inventory","/reports","/admin/audit","/admin/public-site",
                    "/admin/chores","/admin/schedules","/admin/programs","/admin/daytime-exceptions","/admin/daytime-food",
                    "/admin/platform/volunteers","/admin/platform/donations","/admin/platform/expenses",
                    "/admin/platform/kitchen-inventory","/admin/platform/kitchen-meals","/admin/platform/kitchen-temperatures","/admin/platform/kitchen-waste",
                    "/admin/employees/20/module-facilities","/admin/employees/20/roles","/admin/residents/400/edit"]
            for path in routes:
                response=page.goto(base+path)
                assert response.status==200,path+" must load"
                expect(page.locator("h1")).to_be_visible()
                assert "Fatal error" not in page.locator("body").inner_text()
            page.goto(base+"/cases/400")
            form=page.locator('form').filter(has=page.locator('input[name="action"][value="referral"]'))
            form.locator('[name="service"]').fill("Browser referral "+browser_name)
            form.locator('[name="provider"]').fill("Fictional browser provider")
            form.get_by_role("button",name="Add referral",exact=True).click()
            expect(page.locator("main")).to_contain_text("Browser referral "+browser_name)
            page.goto(base+"/inventory")
            page.locator('[name="inventory_id"]').select_option("400")
            page.locator('[name="quantity_change"]').fill("1")
            page.locator('[name="reason"]').fill("Fictional browser receipt")
            page.get_by_role("button",name="Record stock movement",exact=True).click()
            expect(page.locator("main")).to_contain_text("Fictional browser receipt")
            page.set_viewport_size({"width":390,"height":844})
            for path in ["/","/cases/400","/inventory","/volunteer-shifts","/reports","/admin/public-site","/admin/employees/20/module-facilities"]:
                page.goto(base+path)
                assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"),path+" mobile width"
            page.screenshot(path=str(evidence/(browser_name+"-platform-mobile.png")),full_page=True)
            page.get_by_role("button",name="Log out",exact=True).click()
            page.goto(base+"/welcome")
            expect(page.get_by_role("heading",name="Fictional Shelter Public",exact=True)).to_be_visible()
            assert "Private case fixture" not in page.locator("main").inner_text()
            assert not errors,"No browser script errors"
            print("PASS: "+browser_name+" complete-platform pages, referral/stock forms, mobile layouts, and public privacy.")
        finally:
            browser.close()
