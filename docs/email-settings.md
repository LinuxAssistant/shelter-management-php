# Administrator email settings

Administrators (including a director assigned that role) can open Administration → Email settings to set the password-reset sender address and enable or disable reset messages. Changes require administrator access, CSRF protection, and an audit entry. Saving does not send a test message.

Messages use the server mail service. This screen does not configure an SMTP relay or store SMTP passwords. Delivery must be configured separately on the server; accepting a message locally does not establish delivery. APP_URL must remain an HTTPS address accessible to recipients. Keep sending disabled until delivery is verified.

Apply migration 024_email_settings.sql once before deploying this version to an existing database. It adds settings only and preserves existing records. Until settings are saved, existing MAIL_FROM and PASSWORD_RESET_EMAIL_ENABLED environment settings remain the defaults. Do not reimport the consolidated schema into a populated installation.

The platform also uses external JavaScript assets and a policy blocking inline executable scripts, plus no-referrer responses. Automated tests cover both browsers, permissions, sender validation, saved settings, and captured reset messages; they do not verify external email delivery. Independent organizational privacy/security review remains outstanding.
