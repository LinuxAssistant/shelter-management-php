"""Browser acceptance against the disposable password integration fixture."""
import re
from pathlib import Path

def run_browser(base, capture, sql, password_hash, browser_name):
    from playwright.sync_api import sync_playwright, expect
    sql("USE shelter_test; DELETE FROM auth_rate_limits; DELETE FROM password_reset_tokens;"
        "UPDATE users SET email='employee@example.invalid',active=1,password_hash='" + password_hash + "' WHERE id=2;")
    with sync_playwright() as playwright:
        browser = getattr(playwright, browser_name).launch(headless=True)
        admin = browser.new_context(base_url=base, viewport={"width":1280,"height":900})
        staff = browser.new_context(base_url=base, viewport={"width":1280,"height":900})
        recovery = browser.new_context(base_url=base, viewport={"width":1280,"height":900})
        for context in (admin, staff, recovery):
            context.route("**/*", lambda route: route.continue_() if route.request.url.startswith(base + "/") else route.abort())
        page, other, reset = admin.new_page(), staff.new_page(), recovery.new_page()
        errors = []
        for target in (page, other, reset):
            target.on("pageerror", lambda error: errors.append(str(error)))
        def login(target, name, password):
            target.goto(base + "/login")
            target.locator('[name="identifier"]').fill(name + "@example.invalid")
            target.locator('[name="password"]').fill(password)
            target.get_by_role("button",name="Sign in",exact=True).click()
        def request_reset():
            page.goto(base + "/admin/employees/2")
            page.get_by_role("button",name="Send password reset email",exact=True).click()
            expect(page.get_by_role("heading",name="Reset requested",exact=True)).to_be_visible()
            return re.search(r"token=([a-f0-9]{64})",capture.read_text())[1]
        def fill_reset(token, password):
            response = reset.goto(base + "/reset-password?token=" + token)
            assert response.headers.get("cache-control") == "no-store"
            assert response.headers.get("referrer-policy") == "no-referrer"
            reset.locator('[name="password"]').fill(password)
            reset.locator('[name="confirmation"]').fill(password)
            reset.get_by_role("button",name="Reset password",exact=True).click()
        try:
            login(page,"admin","fictional-test-password")
            expect(page.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            login(other,"employee","fictional-test-password")
            expect(other.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            token = request_reset()
            sql("USE shelter_test; UPDATE password_reset_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE);")
            fill_reset(token,"fictional-browser-reset")
            expect(reset.get_by_role("heading",name="Reset link expired",exact=True)).to_be_visible()
            token = request_reset()
            fill_reset(token,"fictional-browser-reset")
            expect(reset.get_by_role("heading",name="Password reset",exact=True)).to_be_visible()
            fill_reset(token,"fictional-browser-reuse")
            expect(reset.get_by_role("heading",name="Reset link expired",exact=True)).to_be_visible()
            other.goto(base + "/")
            expect(other.get_by_role("heading",name="Employee login",exact=True)).to_be_visible()
            login(other,"employee","fictional-test-password")
            expect(other.get_by_role("heading",name="Login failed",exact=True)).to_be_visible()
            login(other,"employee","fictional-browser-reset")
            other.get_by_role("link",name="Change my password",exact=True).click()
            other.locator('[name="current_password"]').fill("wrong-password")
            other.locator('[name="new_password"]').fill("fictional-browser-change")
            other.locator('[name="confirm_password"]').fill("fictional-browser-change")
            other.get_by_role("button",name="Change password",exact=True).click()
            expect(other.get_by_role("heading",name="Password unchanged",exact=True)).to_be_visible()
            other.goto(base + "/account/password")
            other.locator('[name="current_password"]').fill("fictional-browser-reset")
            other.locator('[name="new_password"]').fill("fictional-browser-change")
            other.locator('[name="confirm_password"]').fill("fictional-browser-change")
            other.get_by_role("button",name="Change password",exact=True).click()
            expect(other.get_by_role("heading",name="Password updated",exact=True)).to_be_visible()
            login(other,"employee","fictional-browser-change")
            expect(other.get_by_role("heading",name="Staff dashboard",exact=True)).to_be_visible()
            reset.goto(base + "/login")
            reset.get_by_role("link",name="Forgot password?",exact=True).click()
            reset.locator('[name="email"]').fill("employee@example.invalid")
            reset.get_by_role("button",name="Request reset link",exact=True).click()
            expect(reset.get_by_role("heading",name="Check your email",exact=True)).to_be_visible()
            token = re.search(r"token=([a-f0-9]{64})",capture.read_text())[1]
            fill_reset(token,"fictional-browser-recovery")
            expect(reset.get_by_role("heading",name="Password reset",exact=True)).to_be_visible()
            evidence = Path.home() / "shelter-browser-evidence" / "pr4"
            evidence.mkdir(parents=True,exist_ok=True)
            page.goto(base + "/admin/employees/2")
            page.screenshot(path=str(evidence / (browser_name + "-employee.png")),full_page=True)
            page.set_viewport_size({"width":390,"height":844})
            assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), "Mobile employee form must fit screen"
            page.screenshot(path=str(evidence / (browser_name + "-mobile-employee.png")),full_page=True)
            assert not errors, "Unexpected browser script error"
            print("PASS: " + browser_name + " password browser acceptance (admin reset, expiry/reuse, session revocation, self-service change, recovery).")
        finally:
            browser.close()
