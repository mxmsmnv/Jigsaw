# Changelog

## 2.0.2 - 2026-07-30

- Clarified that Jigsaw and its bundled components are not private modules;
  only the GitHub repository is private during development.
- Removed development-repository visibility wording from module metadata,
  dashboard copy, and documentation.

## 2.0.1 - 2026-07-30

- Corrected the Context integration model: Context remains a complete,
  independently configurable module bundled inside the Jigsaw toolkit.
- Removed Context behavior and configuration from the Jigsaw dashboard module.
- Updated the vendored Context snapshot to its final standalone release, 2.2.0.

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
