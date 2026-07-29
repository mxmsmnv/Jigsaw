# SSL Manager

A ProcessWire admin module to monitor SSL certificate expiry, manage the domains you watch, and work with CSRs and certificates — all from **Setup → SSL**.

- **Version:** 1.1.0

![Ssl](assets/Ssl.png)

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

- **License:** MIT
- **Requires:** ProcessWire >= 3.0.0, PHP OpenSSL extension

## Features

### Certificate monitoring

- Live expiry resolution — opens a TLS connection to each domain, parses the peer certificate and shows the real expiration date, days left, issuer and status.
- Status badges: **Valid**, **Expiring** (within the warning window), **Expired**, **Error** (DNS / connection problems are shown inline).
- Summary cards: monitored / valid / expiring soon / problem.
- Sortable table (click any column header) with a **Last checked** column.
- Results are cached for a configurable lifetime; **Re-check now** clears the cache and re-resolves immediately.

### Domain management

- Add a single domain with a custom **port** (defaults to 443 — useful for mail/IMAPS/SMTPS or panels on `8443`) and an optional note.
- **Add multiple** — paste a list of hosts (one per line), each added on port 443.
- **Import from this site** — one click adds every host in `$config->httpHosts`.
- **Export CSV** — download the domain list with current status, expiry and issuer.
- Delete with confirmation (CSRF-protected).

### Domain detail page

Click a domain name to see full live certificate info: Subject Alternative Names, SHA-256 fingerprint, serial, signature algorithm, negotiated TLS protocol / cipher, the certificate chain, and whether it is self-signed.

### Email alerts

- Daily check via the core **LazyCron** module emails you about certificates within the warning window or already expired.
- Throttled per domain by a configurable **re-alert interval** so you are not spammed.
- **Mailer** selection — use the site default or any installed `WireMail*` module (SMTP, etc.).
- An admin notice (once per session) flags certificates that need attention.

### Tools

- **Generate CSR** — create a Certificate Signing Request and private key (CN, SAN, key size, organization fields). Copy or download each block. The private key is shown once and is never stored.
- **Check CSR** — decode a CSR (subject, key type/size) and optionally verify that a private key matches it.
- **Decode Certificate** — paste a `.crt` PEM to inspect its details.
- **Self-signed** — generate a self-signed certificate + key for local / development use.

## Settings

Found under the module config (a **Settings** link sits at the bottom of the certificates list).

- **Monitoring** — warn days before expiry, cache lifetime, connection timeout.
- **Email alerts** — enable, recipient (defaults to the site admin email), re-alert interval, mailer.
- **CSR defaults** — key size, country, state, locality, organization, OU, email pre-filled in the Generate CSR form.

## Installation

1. Copy this folder to `/site/modules/Ssl/`.
2. In the admin go to **Modules → Refresh**, then install **SSL Manager**.
3. Open **Setup → SSL**.

Installation creates the `process_ssl_domains` table, the admin page under Setup, and the `ssl-manager` permission.

To enable email alerts, install the core **LazyCron** module, then turn alerts on in the module settings.

## Project structure

```
Ssl/
├── ProcessSsl.module.php   Process: routing, forms, rendering, data access, config, alerts
├── src/
│   └── SslService.php      OpenSSL / TLS logic (certificate inspection, CSR & self-signed generation)
└── assets/
    ├── ssl.css             Admin styles (theme-aware via --pw-* variables)
    └── ssl.js              Table sorting + PEM copy/download helpers
```

## Notes

- TLS certificate inspection and all CSR/certificate operations require the PHP **OpenSSL** extension.
- SAN entries are not extracted from a *CSR* in the Check CSR tool (PHP has no native API for it); the subject DN and key details are shown. Live domains and decoded certificates do show SANs.
- Clipboard copy uses `navigator.clipboard` on secure origins (HTTPS / localhost) and falls back to `execCommand` elsewhere.
