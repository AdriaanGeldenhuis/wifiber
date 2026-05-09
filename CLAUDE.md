# Project notes for Claude

## Workflow preferences

- **Migrations**: whenever a migration file is created or modified, paste its
  full SQL content into the chat reply so the operator can copy/paste it
  straight into the DB. The file in `data/migrations/` is the source of truth;
  the chat paste is a convenience.

## Roles and access

- Alignment (`/admin/align.php`), installs dashboard (`/admin/installs.php`)
  and the per-job workflow (`/admin/install-view.php`) are gated behind
  `admin_can_write()` which allows `super_admin`, `admin`, and `technician`.
  When adding new tech-facing pages, use the same gate so admins are never
  locked out.
