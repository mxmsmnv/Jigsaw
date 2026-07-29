# Uninstaller

ProcessWire module for safely uninstalling a module together with its associated submodules, fields, templates, and directories — all from a single admin UI.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).  

## Features

- Select any installed module from a dropdown
- Scan and detect associated resources automatically:
  - **Submodules** — modules sharing the same name prefix or declared via `installs` in `getModuleInfo()`
  - **Fields** — fields with a matching snake_case prefix (e.g. `wire_nps_*`)
  - **Templates** — templates with a matching prefix
  - **Directories** — `site/modules/<ModuleName>/`
- Review everything before deleting — uncheck items to keep
- Correct deletion order: submodules → fields → templates → main module → directories
- Safety: directory deletion is restricted to `site/modules/` only

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+

## Installation

1. Copy `Uninstaller/` to `site/modules/`
2. Admin → Modules → Refresh → Install **Uninstaller**
3. Access via Admin → Module Uninstaller

## How it works

### Prefix detection

The module derives a snake_case prefix from the module name and scans for matching fields and templates.

Examples:
- `WireNPS` → prefix `wire_nps_`
- `FieldtypeBookmarks` → prefix `fieldtype_bookmarks_`
- `CloudCache` → prefix `cloud_cache_`

If your module uses a custom prefix, or creates fields/templates under different names, uncheck auto-detected items and manage those manually.

### Deletion order

1. Submodules (uninstalled first, while the main module is still available)
2. Fields (removed from all fieldgroups, then deleted)
3. Templates (pages using the template are deleted first)
4. Main module
5. Directories (last, after module files are no longer needed)

## Notes

- The main module checkbox is always checked and cannot be unchecked — it is always uninstalled
- Directory deletion only works for paths inside `site/modules/` as a safety measure
- `___uninstall()` of the target module is called normally, so its own cleanup logic runs

## Changelog

### 1.0.0 — 2026-04-03
- Initial release
