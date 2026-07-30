# Jigsaw

Private ProcessWire operations and developer toolkit maintained by Maxim Semenov.

Jigsaw is a single installable package containing a dashboard and a curated set
of independently useful ProcessWire modules. The package vendors exact upstream
revisions so a deployment does not depend on Git submodules or network access.

## Components

- ProcessDbBackup — database backups, restore, migrations and schema snapshots
- ProcessJigsawDiagnostics — read-only page tree, duplicate-title and URL checks
- Editor — template file editor
- AdminBar — frontend administration shortcuts
- Uninstaller — dependency-aware module removal
- WireAnnouncementBar — frontend announcement bar
- ProcessSsl — certificate monitoring and tools
- LanguageAccessManager — language editing permissions
- WarmUp and ProcessWarmUp — email warmup coordinator
- ProcessFieldAudit — field and Repeater Matrix inventory
- Context — complete, independently configurable AI-ready site context exporter

`ProcessDbBackup` is sourced from its `dev` branch. Context is maintained in
this toolkit after retirement of its standalone repository. The remaining
components are sourced from their default stable branches. Exact revisions are recorded in
[`components.lock.json`](components.lock.json).

## Requirements

- ProcessWire 3.0.200 or newer
- PHP 8.2 or newer

Individual components may have additional optional requirements. For example,
scheduled database backups use LazyCron and cloud backups require cURL.

## Installation

1. Copy the complete `Jigsaw` directory to `site/modules/Jigsaw`.
2. In ProcessWire, open **Modules → Refresh**.
3. Install **Jigsaw**.
4. Open **Setup → Jigsaw**.

Installing Jigsaw installs its bundled components. Component configuration and
permissions remain independent, including Context's own settings, admin page,
CLI commands, and `context-admin` permission.

## Updating

Component sources are vendored deliberately. Review upstream changes, update
one component at a time, refresh `components.lock.json`, and test the complete
package before deployment.

`ProcessJigsawDiagnostics` is maintained directly in this repository and is
therefore not listed in the external component lock.

## lqrs-utils

The Jigsaw dashboard's lightweight inventory and `ProcessJigsawDiagnostics`
were informed by the useful read-only ideas in `mxmsmnv/lqrs-utils`, especially
`stat.php`, `duplicates.php`, and `digits.php`. The original scripts were not
copied because they are tied to LQRS data, contain destructive operations, and
are not safe as reusable module code.

## Security

Jigsaw is intended for trusted administrators. Assign powerful component
permissions—especially backup/restore, file editing, SSL, and uninstall
permissions—only to trusted roles.
