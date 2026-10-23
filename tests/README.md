# Isolated password integration tests

Run from this branch on Linux:

    composer install
    python3 tests/password-integration.py

Requires PHP 8.4+ with mysqli, Python 3, and MariaDB client/server tools (mariadb, mariadbd, mariadb-install-db). Run as an unprivileged user.

The suite creates a temporary MariaDB instance with networking disabled, applies branch migrations to its empty database, copies app/bootstrap/public/vendor into a temporary application, and creates an empty .env. PHP runs on loopback with a separate session directory and a local mail-capture program. No external email is sent. Deployment credentials, resident records, and backups are not loaded. Temporary processes/files are cleaned up on success or failure.

Coverage includes administrator reset controls, authorization, CSRF, password validation, token expiration/replacement/reuse, simultaneous token issuance and consumption, old-password rejection, session invalidation across clients, inactive accounts, recovery-address changes, throttling, and audit events.

The suite passed locally on Dell. GitHub Actions now installs MariaDB test tools, generates the autoloader, lints PHP, and runs the isolated suite on PHP 8.4.

## Optional browser acceptance

With Python Playwright and its Chromium and Firefox browsers installed, run:

    python3 tests/password-integration.py --browser

This repeats the isolated backend suite, then verifies administrator reset, expired/reused links, old-session rejection, self-service changes, and public recovery through both browsers. It blocks non-loopback browser requests and captures email locally. Desktop/mobile screenshots contain fictional fixtures only and are saved under ~/shelter-browser-evidence/pr4. Browser acceptance passed on Dell; CI currently runs the backend suite without browser dependencies.
