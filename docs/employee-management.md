# Employee management (development)

No new database migration is required if migrations 001, 002 and 003 have already been applied.

Visit `/admin/employees` as an administrator. Create a fictional employee with a unique email, badge number and initial password of at least 12 characters. New accounts have no assigned roles, so an administrator must provision roles separately before the employee can use protected incident features.

Edit the employee's name and job title, and verify the changes appear on their incident notes. Deactivate the account and verify login fails. Re-activate it to restore login. The administrator cannot deactivate their own account through this interface.

**Not production ready:** Administrative account creation requires stronger audit logs, login throttling, secure password setup/reset flows, role management, and protection against disabling the last active administrator. Do not use real staff or resident information during development.
