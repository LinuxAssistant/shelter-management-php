# Resident regression checks

Run from the repository root on Linux: composer install; node tests/resident-search.cjs; python3 tests/integration.py.

Requires PHP 8.4+ with mysqli and mbstring, Python 3, Node.js, and MariaDB server/client tools (mariadb-install-db, mariadbd, mariadb). Run as an unprivileged user.

The integration suite starts a temporary MariaDB instance with networking disabled and a temporary PHP HTTP server bound to loopback. It copies application source and vendor files, creates an empty .env, imports the consolidated schema into the disposable instance, and uses only fictional fixtures. It never reads the deployment .env or database backup. Processes and temporary files are cleaned up on success or failure.

Tests cover resident admission, cross-facility rejection, occupied-bed rejection, both checkout types, returning residents, resident status/history, search API limits and escaping, authentication, permission denial, and CSRF. The Node harness covers debounce/cancellation, stale responses, safe option text, facility bed filtering, and failure/empty/session states. Concurrent double-booking and browser acceptance remain separate TODO items.

## Optional browser acceptance

With Python Playwright and its Chromium and Firefox browsers installed, run:

    python3 tests/integration.py --browser

This uses the same disposable database, a separate PHP session directory, fictional accounts/residents, and loopback-only browser requests. It verifies AJAX lookup by partial name/reference, facility bed filtering, check-in, both departures, resident status/history, and mobile form width. Desktop/mobile screenshots are saved under ~/shelter-browser-evidence/pr6. Browser acceptance passed on Dell. CI runs the backend and Node suites without browser dependencies.

## Occupancy constraints and concurrency

The default integration run also exercises migration 017, direct unique-key violations, closed history, concurrent independent SQL writers, and simultaneous PHP admission processes. The CLI worker refuses to connect unless a disposable test-instance marker and socket are supplied. Legacy fixture databases validate that duplicate open residents/beds block the migration before any columns are added and that a reconciled upgrade succeeds. No production configuration or database is read.
