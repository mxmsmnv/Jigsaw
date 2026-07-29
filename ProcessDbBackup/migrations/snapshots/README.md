# Schema snapshots

This folder is kept for documentation/examples only.

Runtime JSON snapshots created from **DB Backup -> Migrations** are stored in:

```text
site/assets/ProcessDbBackup/snapshots/
```

Snapshots are Git-friendly records of ProcessWire schema state:

- fields
- templates and assigned fields
- permissions
- roles

They intentionally do not include page content, field values, users, sessions, caches, or uploads.
