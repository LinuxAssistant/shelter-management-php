# Shelter Management PHP

Laravel-inspired PHP/MariaDB shelter management starter.

## Implemented
- Employee email or badge login with passwords and PHP sessions
- Role-based incident permissions
- Incident submission and listing
- CSRF protection and prepared SQL statements

## Local setup
1. Install PHP 8.4+, mysqli, Composer, MariaDB.
2. Run `composer install`; copy `.env.example` to `.env` and configure credentials.
3. Create database `shelter_management` and import `database/migrations/001_initial.sql`.
4. Create an administrator with `php scripts/create-admin.php admin@example.org 10001` (password prompted).
5. Run `php -S 127.0.0.1:8000 -t public public/router.php`.

**Development only:** Not ready for real resident information. Production requires security review, HTTPS, login throttling, operational backups and privacy controls.
