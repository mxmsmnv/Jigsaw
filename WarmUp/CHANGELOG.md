# Changelog

All notable changes to WarmUp are documented here.

## [1.0.0] - 2026-06-19

### Added

- First public release of WarmUp for ProcessWire.
- Daily warmup limits with a stable random value selected per day.
- Optional linear ramp-up from the minimum daily limit to the maximum daily limit.
- Default hourly send window for controlling when sending is allowed.
- Weekly schedule overrides for enabling, disabling or changing send windows per day.
- Randomized interval handling between counted sends.
- File-backed daily counter with locking for safer concurrent updates.
- Compact daily log with automatic aggregation by date.
- Admin page under Setup with today's status, ramp-up status, chart, daily log and actions.
- CSRF-protected admin actions for resetting today's counter, clearing the log and starting ramp-up.
- Dedicated `warmup-admin` permission.
- Built-in ProMailer integration that does not modify ProMailer files.
- Integration API through `registerIntegration()` for other mailer modules.
- Manual send recording API through `recordSend()`.
- Active integration display in the admin status card.
- Migration support for earlier `ProMailerWarmup` cache files, configuration and permission names.

### Changed

- Renamed the module from `ProMailerWarmup` to `WarmUp`.
- Renamed the admin process module from `ProcessProMailerWarmup` to `ProcessWarmUp`.
- Removed the hard dependency on ProMailer so WarmUp can run as a generic coordinator.
- Replaced external Chart.js loading with a local canvas chart in the admin page.

### Fixed

- Prevented admin actions from running without a valid CSRF token.
- Avoided using ProMailer `skip` results for warmup deferral, so subscribers are not accidentally advanced.
- Added configuration normalization for invalid limits, intervals, hours and schedules.
- Avoided changing global random generator state when selecting the daily limit.
- Preserved default send-window behavior when all weekly schedule days are disabled.
