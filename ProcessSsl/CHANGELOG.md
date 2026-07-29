# Changelog

All notable changes to SSL Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] - 2026-05-22

### Added
- Daily email expiry alerts via the core LazyCron module, throttled per domain by a configurable re-alert interval.
- Mailer selection in settings — use the site default or any installed `WireMail*` module.
- Admin notice (once per session) flagging certificates that need attention.
- **Last checked** column in the certificates table.
- Domain detail page (click a domain) with SANs, SHA-256 fingerprint, serial, signature algorithm, TLS protocol/cipher, certificate chain and self-signed flag.
- **Decode Certificate** tool — paste a `.crt` PEM to inspect it.
- **Self-signed** certificate generator for local/development use.
- Bulk add (paste multiple hosts) and **Import from this site** (`$config->httpHosts`).
- **Export CSV** of the domain list with current status.
- Copy-to-clipboard and download buttons for generated CSR / private key blocks.

### Changed
- Split the monolith: OpenSSL/TLS logic moved to `src/SslService.php`; styles and scripts moved to `assets/ssl.css` and `assets/ssl.js`.
- Certificates table restyled with native UIkit classes and theme-aware variables; status uses `uk-label`.
- Module is now autoloaded (lightweight `init()`) so alerts and notices can run.

### Fixed
- CSRF validation for GET action links (delete, import) now checks the GET token instead of POST.
- `Check CSR` now reads short-name subject keys, so CN/Organization/etc. display correctly.
- Host normalization no longer passes an array to `Sanitizer::name()`.

## [1.0.0] - 2026-05-22

### Added
- Monitor SSL certificates with live expiry resolution, status badges and summary cards.
- Add / delete monitored domains with custom port and notes.
- Cached certificate checks with a manual re-check action.
- Generate CSR (CN, SAN, key size, organization fields) with private key output.
- Configurable settings: warn days, cache lifetime, connection timeout, CSR defaults.
- Admin page under Setup, `ssl-manager` permission and the `process_ssl_domains` table.
