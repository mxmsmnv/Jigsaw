# Changelog

**Author:** [Maxim Semenov](https://smnv.org) · [github.com/mxmsmnv/Editor](https://github.com/mxmsmnv/Editor)

---

## 1.2.0 — 2026-04-08

Initial public release.

**Core**
- ProcessWire 3.x Process module with superuser-only access
- PHP 8.2+: `#[AllowDynamicProperties]`, fully typed class properties
- Module config via `getModuleConfigData()` merged in `init()` — correct defaults on first install

**File tree**
- Collapsible tree with lazy per-directory AJAX loading
- Chevrons with CSS rotation animation, depth-aware indentation (fixed root-level offset)
- Heroicons 2.2.0 inline SVG — no external icon CDN
- Module's own directory hidden from listing and blocked at `assertPathAllowed()`
- In-flight guard on directory load — double-click no longer fires two AJAX calls

**Editor**
- CodeMirror 5.65.16 bundled locally in `vendor/codemirror/` — no CDN dependency, works offline
- Syntax highlighting: PHP, JS, CSS, HTML, JSON, XML, Markdown
- Dracula theme, line numbers, tab indentation
- Ctrl+S / Cmd+S save; Ctrl+N / Cmd+N new file
- `_loading` flag prevents false "unsaved changes" on file open
- Save button hidden for binary/preview files; Ctrl+S blocked when modal is open

**Image & binary preview**
- `executeRead` returns `serveUrl` for binary files — no base64 in JSON
- `executeServe` streams file with correct `Content-Type` via `readfile()`
- Images displayed via `<img src="...serve/...">` — works for any file size
- PNG/GIF/WebP/ICO shown on checkerboard background (transparency)
- Non-image binaries (fonts, PDFs) show info card with Download link
- File size shown in toolbar; `fe-preview-transparent` class correctly reset on each preview

**File operations**
- Create file or folder — sidebar button, Ctrl+N, or context menu
- Root picker modal when multiple root directories are enabled
- New file opens in editor automatically
- Rename with full filename including extension
- Upload via button or drag-and-drop onto sidebar
- Multiple files per upload; conflicts get auto-numeric suffix (`file_1.php`)
- XHR upload with progress bar showing percent and byte counters
- Meaningful PHP upload error messages (`UPLOAD_ERR_INI_SIZE` etc.)
- `post_max_size` exceeded detected before CSRF validation (prevents silent empty response)

**Safe delete**
- Moves to `/site/assets/cache/.editor-trash/YYYYMMDD_HHMMSS/` — nothing permanently removed by default
- Falls back to `copy()` + `unlink()` if `rename()` fails across filesystems
- Info notice shown on module page when safe delete is enabled

**Security hardening**
- `executeSave` uses raw `$_POST['content']` — PW sanitizers strip HTML tags and corrupt template code
- `executeSave` blocks overwriting binary files via crafted POST
- `jsonResponse` uses `JSON_INVALID_UTF8_SUBSTITUTE` — safe for latin-1 encoded PHP files
- `assertWritable` checks `is_writable()` — clear error instead of silent `file_put_contents` failure
- Null-byte sanitization on all user-supplied filenames
- `rrmdir()` checks `scandir()` return value before iterating
- `showModal` escapes `defaultValue` to prevent HTML injection via filenames with `"` characters
- Stale `_uploadDir` cleared after upload completes or fails
- Modal stacking prevented — new modal closes existing one
- `$out` variable initialized before conditional blocks in `execute()`
- Duplicate `id=` attribute removed from Close button

**UI & UX**
- Responsive layout: mobile shows tree or editor full-screen, never both squeezed
- Back button in toolbar on mobile
- Notifications stack (top-right), auto-dismiss with fade-out
- Upload progress bar inserted above file tree
- AdminThemeUikit `--pw-*` CSS variables throughout — zero hardcoded colors
- light/dark mode and accent color fully supported
