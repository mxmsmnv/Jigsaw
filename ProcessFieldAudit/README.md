# ProcessFieldAudit

`ProcessFieldAudit` is a ProcessWire admin module that gives you a complete overview of fields used in your project.

It is especially useful for projects with complex `RepeaterMatrix` setups and `FieldtypeMatrixType` fields.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).  

## Features

- Lists all fields with:
  - field name
  - field type
  - label
  - assigned templates
- Shows `FieldtypeMatrixType` metadata:
  - matrix type identifier (slug)
  - display name
- Audits every `FieldtypeRepeaterMatrix` field and displays:
  - all matrix types
  - type IDs
  - display names
  - fields attached to each matrix type
- Cross-references where fields are used inside matrix types
- Includes filtering tools in the admin UI:
  - text search by field name/type
  - Matrix-only mode
  - hide system fields toggle

## Installation

1. Copy the module folder to:
   - `/site/modules/ProcessFieldAudit/`
2. In ProcessWire admin, go to:
   - `Modules -> Refresh`
3. Install module:
   - `Field Audit`

## Usage

After installation, open:

- `Admin -> Setup -> Field Audit`

You will see:

- a summary of all `RepeaterMatrix` fields and their types
- a full table of all fields in the system

## Requirements

- ProcessWire with support for:
  - `FieldtypeRepeaterMatrix`
  - `FieldtypeMatrixType` (from InputfieldMatrixType package)

## Author

- **Maxim Semenov**
- [smnv.org](https://smnv.org)

## Repository

- [https://github.com/mxmsmnv/ProcessFieldAudit](https://github.com/mxmsmnv/ProcessFieldAudit)

## License

MIT (or same license as this repository, if different).
