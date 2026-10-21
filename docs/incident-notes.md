# Incident notes and people involved

This feature adds a detail page at `/incidents/{id}`. Click an incident ID in the incident list to open it.

## Upgrade existing installations

Apply the second migration **once**, after the initial migration:

```bash
mariadb -u shelter_app -p shelter_management < database/migrations/002_incident_notes_people.sql
```

The migration adds notes, people-involved records, and role permissions for Administrator, Manager, and Staff. The original incident description is preserved. Notes have author IDs and timestamps, with no edit/delete UI.

## Manual verification

1. Open an existing incident from the list.
2. Add a fictional person involved and verify it appears.
3. Add a note and verify its author and timestamp.
4. Log in with another staff account and add a second note.
5. Confirm that the original report and earlier notes remain unchanged.

**Privacy:** Do not enter actual resident names or sensitive details in development. This first version uses a free-text person label, not a resident-record relationship. Before production, implement facility-scoped authorization, restricted-note visibility, auditing, login throttling, and retention controls.
