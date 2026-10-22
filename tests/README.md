# Resident regression checks

Run from the repository root on Linux: composer install; node tests/resident-search.cjs; python3 tests/integration.py.

Requires PHP 8.4+ with mysqli and mbstring, Python 3, Node.js, and MariaDB server/client tools (mariadb-install-db, mariadbd, mariadb). Run as an unprivileged user.

The integration suite starts a temporary MariaDB instance with networking disabled and a temporary PHP HTTP server bound to loopback. It copies application source and vendor files, creates an empty .env, imports the consolidated schema into the disposable instance, and uses only fictional fixtures. It never reads the deployment .env or database backup. Processes and temporary files are cleaned up on success or failure.

Tests cover resident admission, cross-facility rejection, occupied-bed rejection, both checkout types, returning residents, resident status/history, search API limits and escaping, authentication, permission denial, and CSRF. The Node harness covers debounce/cancellation, stale responses, safe option text, facility bed filtering, and failure/empty/session states. Concurrent double-booking and browser acceptance remain separate TODO items.
