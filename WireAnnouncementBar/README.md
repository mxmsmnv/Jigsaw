# WireAnnouncementBar

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

- **Release Date:** March 8, 2026

WireAnnouncementBar is a ProcessWire module that displays a dismissible
announcement banner for site visitors. Supports **Tailwind CSS**,
**Bootstrap 5**, and **UIkit 3** out of the box. The dismissed state
is stored in a lightweight browser cookie.

---

## Features

- **Multi-framework support**: Ships with Tailwind CSS, Bootstrap 5, and UIkit 3 themes.
- **Color variants**: Choose between `warning`, `info`, `success`, and `danger` styles.
- **Call-to-action link**: Add an optional link with custom label and new-tab support.
- **Dismissible banner**: Visitors can close the banner — the preference is saved in a cookie.
- **Cookie control**: Configure the cookie name and lifetime to control when the banner reappears.
- **Alpine.js transitions**: Smooth enter/leave animations powered by Alpine.js.
- **Inline override**: Override any setting directly in the template without touching the admin panel.
- **Shorthand helper**: Use `$announcementBar->show()` for quick one-liner usage in templates.
- **Extensible themes**: Add a custom theme by extending the `ThemeBase` abstract class.
- **Template variable**: Automatically available as `$announcementBar` in every template.

---

## Installation

1. Copy the `WireAnnouncementBar` folder into `site/modules/`.
2. In the ProcessWire admin, go to **Modules → Refresh**.
3. Find **Wire Announcement Bar** and click **Install**.

---

## Configuration

1. Go to **Modules → Wire Announcement Bar** in the admin panel.
2. Enable the banner using the **Enable banner** checkbox.
3. Select a **CSS framework** (`tailwind`, `bootstrap`, `uikit`).
4. Select a **Color variant** (`warning`, `info`, `success`, `danger`).
5. Fill in the **Banner title** and **Banner message** fields.
6. Optionally add a **call-to-action link** — set the URL, label, and whether to open in a new tab.
7. Optionally enable the dismiss button and configure the **cookie name** and **lifetime**.

| Field | Description |
|---|---|
| **Enable banner** | Master switch — uncheck to hide without deleting content |
| **CSS framework** | `tailwind` · `bootstrap` · `uikit` |
| **Color variant** | `warning` · `info` · `success` · `danger` |
| **Banner title** | Short, bold headline |
| **Banner message** | Supporting text shown after the title |
| **Link URL** | Optional call-to-action URL — leave empty to hide the link |
| **Link label** | Anchor text for the link (default: `Learn more`) |
| **Open in a new tab** | Opens the link in a new browser tab |
| **Allow dismiss** | Show a close (×) button |
| **Cookie lifetime** | Days to remember that a visitor dismissed the banner |
| **Cookie name** | Change after editing content so it reappears for all visitors |

---

## How to Use

1. After installation, open any template file where you want the banner to appear.
2. Place the render call at the desired location (e.g. below the `<header>` tag).
3. The banner reads its content and settings from the admin panel automatically.
4. To override settings per-template, pass an options array to `render()`.
5. To dismiss the banner, visitors click the (×) button — the state is stored in a cookie.
6. To reset the banner for all visitors, change the **Cookie name** in the module settings.

### Option A — settings from the admin panel

```php
<?= $announcementBar->render() ?>
```

### Option B — override options inline

```php
echo $announcementBar->render([
    'banner_title'       => 'February 12–14: Fully booked.',
    'banner_message'     => 'We are no longer accepting orders for these dates.',
    'banner_type'        => 'warning',     // warning | info | success | danger
    'banner_framework'   => 'tailwind',    // tailwind | bootstrap | uikit
    'banner_link_url'    => '/contact',
    'banner_link_label'  => 'Contact us',
    'banner_link_newtab' => false,
    'banner_closeable'   => true,
    'banner_cookie_days' => 1,
    'banner_cookie_name' => 'feb_booking_v1',
]);
```

### Option C — shorthand helper

```php
// show(title, message, type, framework, linkUrl, linkLabel)
echo $announcementBar->show(
    'Flash sale — today only!',
    '20% off all orders.',
    'success',
    'bootstrap',
    '/shop',
    'Shop now'
);
```

### Option D — store in a variable, render anywhere

```php
// site/templates/_init.php
$banner = $announcementBar->render([
    'banner_title'   => $page->banner_title,
    'banner_message' => $page->banner_message,
    'banner_link_url' => $page->banner_link,
]);

// site/templates/_main.php
<?= $banner ?>
```

---

## Color Variants

| Key | Tailwind | Bootstrap | UIkit |
|---|---|---|---|
| `warning` | yellow | `alert-warning` | `uk-alert-warning` |
| `info` | blue | `alert-info` | `uk-alert-primary` |
| `success` | green | `alert-success` | `uk-alert-success` |
| `danger` | red | `alert-danger` | `uk-alert-danger` |

---

## Adding a Custom Theme

1. Create `site/modules/WireAnnouncementBar/themes/MyTheme.php`.
2. Extend `ThemeBase` and implement all abstract methods including `renderLink()`.
3. Register the class in `getThemeRegistry()` inside the main module file.

```php
// themes/MyTheme.php
<?php namespace ProcessWire;

class MyTheme extends ThemeBase {

    public function getVariants(): array {
        return [
            'warning' => [
                'wrapper'     => 'my-banner my-banner--warning',
                'icon_color'  => 'my-icon--warning',
                'title_color' => 'my-title--warning',
                'text_color'  => 'my-text--warning',
                'link_color'  => 'my-link--warning',
                'btn_color'   => 'my-btn--close',
            ],
        ];
    }

    public function renderBanner(array $variant, string $titleHtml,
        string $messageHtml, string $linkHtml, string $spacer,
        string $alpineData, string $closeButton): string { /* … */ }

    public function renderCloseButton(array $variant, string $action): string { /* … */ }

    public function renderLink(string $url, string $label, array $variant, bool $newTab): string { /* … */ }

    public function renderIcon(string $type): string { /* … */ }
}
```

```php
// WireAnnouncementBar.module.php → getThemeRegistry()
protected function getThemeRegistry(): array {
    return [
        'tailwind'  => TailwindTheme::class,
        'bootstrap' => BootstrapTheme::class,
        'uikit'     => UIkitTheme::class,
        'mytheme'   => MyTheme::class,  // ← add your theme here
    ];
}
```

---

## JavaScript Dependencies

Add **Alpine.js** before the closing `</body>` tag:

```html
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
```

Add the `x-cloak` style to prevent a flash of unstyled content:

```html
<style>[x-cloak] { display: none !important; }</style>
```

---

## File Structure

```
site/modules/WireAnnouncementBar/
├── WireAnnouncementBar.module.php   Main module class
├── themes/
│   ├── ThemeBase.php                Abstract base class for all themes
│   ├── TailwindTheme.php            Tailwind CSS theme
│   ├── BootstrapTheme.php           Bootstrap 5 theme
│   └── UIkitTheme.php               UIkit 3 theme
└── README.md                        This file
```

---

## License

MIT License — Free to use in personal and commercial projects.
