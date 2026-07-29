# Changelog

All notable changes to ProcessDbBackup are documented in this file.

## 2.2.1 — 2026-07-29

- Resolve the CLI script path from the installed module file so commands work
  when ProcessDbBackup is nested inside a module package

## 2.2.0 — 2026-06-21

- Moved runtime migration and schema snapshot storage to `site/assets/ProcessDbBackup/` so generated files survive module updates
- Refined the Migrations UI with a compact status bar and collapsed create/upload tools
- Added a DB Backup → Migrations section for Git-tracked PHP deployment migrations
- Added a GUI migration generator for common schema/deployment operations
- Added Git-tracked schema snapshots and latest-snapshot diff summaries
- Added starter migration generation from added schema items in the latest snapshot diff
- Added migration file preview before running generated or hand-written migrations
- Added PHP syntax preflight checks and blocked execution for invalid migration files
- Added migration impact preview with detected schema references and destructive-operation warnings
- Added environment-aware production confirmation before running migrations
- Added applied migration details with execution metadata and checksum drift warnings
- Added migration locking to prevent concurrent migration runs
- Added GUI downloads for migration PHP files and schema snapshot JSON files
- Added GUI deletion for pending migration files and schema snapshots
- Added GUI upload for existing PHP migration files with filename and syntax validation
- Added a `process_db_backup_migrations` execution log with filename, checksum, user, timestamp, pre-backup, and message
- Added one-click pending migration execution with optional pre-migration backup
- Added migration folder documentation and workflow notes

## 2.1.2 — 2026-06-07

- Fixed a first-run deprecation warning when schedule settings have not been saved yet
- Updated PDO MySQL buffered-query handling to prefer `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` on newer PHP versions

## 2.1.1 — 2026-06-07

- Added a bottom-of-page link from the DB Backup dashboard to the module settings

## 2.1.0 — 2026-06-07

- Added a dashboard section that lists database table sizes from `information_schema`
- Shows estimated rows, data size, index size, total size, and whether each table is included in backups
- Added a quick link from the table-size section to the module's excluded tables setting

## 2.0.2 — 2026-06-07

- Fixed CLI backup/restore command handling to use `pipefail` and keep diagnostic output out of `.sql.gz` files
- Added safer CLI binary resolution for `mysqldump`, `mysql`, `gzip`, and `gunzip`
- Ensured partial restore re-enables foreign key checks after PDO errors
- Marked metadata migration complete after successful migration writes

## 2.0.1 — 2026-03-28

- Maintenance release with metadata and dashboard stability improvements

## 2.0.0 — 2026-03-28

- Three independent backup types: regular, weekly, monthly
- Per-type LazyCron schedule and retention
- Admin home widget with per-type status and Create now buttons
- Chunked upload (2MB chunks, no upload_max_filesize limit)
- Streaming PDO restore (line-by-line, flat memory usage)
- AJAX progress bar for Create Backup
- Cron lock file to prevent concurrent backups
- Inline label editing in backup table
- Sort by filename / date / size
- Filter table by backup type (tabs)
- `create_typed` action for widget buttons
- Weekly outdated threshold: 7 days
- Monthly outdated threshold: 28 days

## 1.0.0 — 2026-03-28

- Initial release
- Manual and LazyCron scheduled backups
- Local storage with `.htaccess` protection
- Backblaze B2 upload via API v3
- Configurable keep local copy option
- B2-only storage mode tracked in meta
- Restore and delete from Admin UI
- Download as `.sql.gz`
- mysqldump with PHP PDO fallback
- mysql CLI restore with PHP PDO fallback
- Verify backup integrity
- Partial restore (select tables)
- Pre-restore auto-backup
- Exclude tables from backup
- Retention policy per type
- Native UIkit Admin UI
- Module log (`db-backup`)
- `db-backup` permission
