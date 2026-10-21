# Employee names on incident notes

After applying migrations 001 and 002, apply migration 003 once:

```bash
mariadb -u shelter_app -p shelter_management < database/migrations/003_employee_profiles.sql
```

Until an employee profile editor is added, an administrator can populate employee fields in MariaDB:

```sql
UPDATE users SET first_name='Jane', last_name='Smith', job_title='Shelter Staff' WHERE badge_number='10001';
```

Use the actual employee name and badge number. Incident notes display the employee's full name and job title; when no name is set, they display the email address. Badge numbers are not shown on incident notes. This is an interim setup procedure for development; a role-restricted profile management UI is planned.
