# Changelog

## 2.0.0 - 2026-07-30

- Merged the complete Context exporter, AI gateway, CLI, prompts, archives,
  configuration UI and auto-update workflow into the main Jigsaw module.
- Preserved `wire('context')`, Context CLI flags and the existing export folder.
- Added one-time migration of standalone Context configuration into Jigsaw.
- Moved Context administration to **Setup → Jigsaw → Context**.
- Removed Context as a separately installed bundled module.

## 1.1.0 - 2026-07-30

- Added ProcessJigsawDiagnostics with a read-only page tree and site overview.
- Added normalized exact-title duplicate detection.
- Added detection for numeric page-name suffixes that are not reflected in
  page titles.
- Limited scans and rendered results to keep diagnostics bounded on large sites.

## 1.0.0 - 2026-07-29

- Added the Jigsaw toolkit dashboard and environment inventory.
- Bundled ProcessDbBackup from its development branch.
- Bundled Editor, AdminBar, Uninstaller, WireAnnouncementBar, ProcessSsl,
  LanguageAccessManager, WarmUp, ProcessFieldAudit, and Context.
- Added a reproducible component source lock.
