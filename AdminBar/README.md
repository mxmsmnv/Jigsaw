# AdminBar

Frontend admin bar for ProcessWire.

Injects a lightweight bar into frontend pages for logged-in users with quick access to edit the current page and navigate the admin panel.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).  

## Features

- "Edit" button linking directly to the current page in the admin
- Permission-aware admin section links: Pages, Access, Modules, Setup
- Page info strip: publish status, template name, parent, child count, last modified
- User dropdown with avatar (optional), role, profile link, logout
- Top or bottom placement
- Colors inherit from ProcessWire's design system (`--pw-*` variables) automatically
- Icons from Heroicons 2.x outline set — inlined, no HTTP requests
- Role-based access control — bar is hidden until at least one role is configured
- Custom CSS field for overrides
- No external dependencies

## Requirements

- ProcessWire 3.0.x
- PHP 8.0+

## Installation

1. Copy the `AdminBar` folder to `/site/modules/AdminBar/`
2. Go to Modules > Refresh in the admin
3. Find **AdminBar** and click Install
4. Go to the module settings and select at least one role

## Configuration

Navigate to Modules > AdminBar:

**Display**

- Show "Edit" button
- Show admin section links (Pages, Access, Modules, Setup)
- Show page info (status, template, parent, children, modified)

**Position**

- Placement: Top or Bottom
- CSS position: Fixed, Absolute, Sticky

**Roles**

At least one role must be selected — the bar is hidden for everyone otherwise.

**Custom CSS**

Override any CSS variable or add your own rules.

## CSS Variables

```css
:root {
  --admin-bar--height: 44px;
  --admin-bar--z-index: 9999;
  --admin-bar--font-family: system-ui, sans-serif;
  --admin-bar--base-font-size: 13px;
  --admin-bar--avatar-size: 28px;
  --admin-bar--border-radius: 4px;
  --admin-bar--icon-size: 16px;
  --admin-bar--padding: 0 12px;
}
```

Colors are inherited from ProcessWire's `--pw-*` design system variables with fallbacks:

```css
--admin-bar--color           → --pw-text-color
--admin-bar--background-color → --pw-blocks-background
--admin-bar--border-color    → --pw-border-color
```

### Offset a fixed header

If your site has a fixed header at the top, offset it when the bar is present:

```css
.site-header {
  position: fixed;
  top: var(--admin-bar--height, 0);
}
```

For bottom placement no offset is needed.

## Avatar Support

If the User template has an Image field named `profile_photo`, the bar will display the user's avatar (cropped to 64×64). Without it the bar renders without an avatar — no errors.

## Security

- Bar is never shown to guests or unauthenticated users
- Roles field is required — leaving it empty disables the bar for everyone
- Edit button only appears if the user has `page-edit` permission for the current page
- Admin links are filtered per-permission (`page-edit`, `user-edit`, `module-admin`, `template-admin`)

## License

MIT