"""Browser acceptance against the disposable resident integration fixture."""
from pathlib import Path

def run_browser(base, sql, browser_name):
    from playwright.sync_api import sync_playwright, expect
    with sync_playwright() as playwright:
        browser = getattr(playwright,browser_name).launch(headless=True)
        context = browser.new_context(base_url=base,viewport={"width":1280,"height":900})
        context.route("**/*",lambda route: route.continue_() if route.request.url.startswith(base + "/") else route.abort())
        page = context.new_page()
        errors = []
        page.on("pageerror",lambda error: errors.append(str(error)))
        try:
            page.goto(base + "/login")
            page.locator('[name="identifier"]').fill("test@example.invalid")
            page.locator('[name="password"]').fill("fictional-test-password")
            page.get_by_role("button",name="Sign in",exact=True).click()
            expect(page.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            for departure in ("staff_exit","voluntary"):
                page.goto(base + "/admin/occupancy")
                search = page.locator("#resident-search")
                search.fill("Z")
                expect(page.locator("#resident-search-status")).to_have_text("Enter 2 to 100 characters.")
                search.fill("Definitely absent")
                expect(page.locator("#resident-search-status")).to_have_text("No available residents found.")
                search.fill("Fictional")
                expect(page.locator("#occupancy-resident option[value='1']")).to_have_count(1)
                search.fill("RES-TEST-001")
                expect(page.locator("#resident-search-status")).to_have_text("1 results. Select a resident.")
                page.locator("#occupancy-resident").select_option("1")
                assert page.locator("#occupancy-bed option[value='1']").get_attribute("disabled") is None
                expect(page.locator("#occupancy-bed option[value='2']")).to_have_attribute("disabled", "")
                page.locator("#occupancy-bed").select_option("1")
                page.get_by_role("button",name="Check in",exact=True).click()
                row = page.get_by_role("row").filter(has_text="Fictional Alpha")
                expect(row).to_contain_text("TEST-BED")
                page.goto(base + "/admin/residents")
                row = page.get_by_role("row").filter(has_text="Fictional Alpha")
                expect(row).to_contain_text("Currently staying")
                expect(row).to_contain_text("TEST-BED")
                page.goto(base + "/admin/occupancy")
                row = page.get_by_role("row").filter(has_text="Fictional Alpha")
                row.locator('[name="departure_type"]').select_option(departure)
                row.locator('[name="departure_reason"]').fill("Fictional browser departure")
                row.get_by_role("button",name="Close stay",exact=True).click()
                expect(page.get_by_role("row").filter(has_text="Fictional Alpha")).to_have_count(0)
                page.goto(base + "/admin/residents")
                row = page.get_by_role("row").filter(has_text="Fictional Alpha")
                expect(row).to_contain_text("Checked out")
                expect(row).to_contain_text("Not checked in")
                expect(row).not_to_contain_text("TEST-BED")
                row.get_by_role("link",name="Fictional Alpha",exact=True).click()
                expect(page.get_by_role("heading",name="Resident history",exact=True)).to_be_visible()
                expect(page.locator("table")).to_contain_text("Staff-directed exit" if departure=="staff_exit" else "Voluntary departure")
                expect(page.locator("table")).to_contain_text("Fictional browser departure")
                expect(page.locator("table")).to_contain_text("test@example.invalid")
            assert sql("USE shelter_test; SELECT COUNT(*) FROM stays WHERE resident_id=1 AND checked_out_at IS NULL;").strip()=="0"
            evidence = Path.home() / "shelter-browser-evidence" / "pr6"
            evidence.mkdir(parents=True,exist_ok=True)
            page.screenshot(path=str(evidence / (browser_name + "-history.png")),full_page=True)
            page.set_viewport_size({"width":390,"height":844})
            page.goto(base + "/admin/occupancy")
            expect(page.locator("#resident-search")).to_be_visible()
            assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), "Mobile occupancy form must fit screen"
            page.screenshot(path=str(evidence / (browser_name + "-mobile-occupancy.png")),full_page=True)
            assert not errors, "Unexpected browser script error"
            print("PASS: " + browser_name + " resident browser acceptance (AJAX lookup, facility filtering, check-in, both departures, status/history).")
        finally:
            browser.close()
