# Language Access Manager

Language Access Manager is a ProcessWire module for managing language edit permissions through `page-edit-lang-*` permissions.

It lets an administrator choose which languages are controlled by language permissions and which roles may edit each controlled language.

It can also hide inactive content languages in the page editor, so a site can start as German-only and later expose more languages without removing the multilingual setup.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

Version: 100

## Requirements

- ProcessWire with `LanguageSupport`
- ProcessWire with `LanguageSupportFields`
- Administrator access to install modules and manage permissions

## Installation

1. Copy this module folder to `site/modules/LanguageAccessManager/`.
2. In ProcessWire admin, go to `Modules > Refresh`.
3. Install `Language Access Manager`.
4. Open `Modules > Configure > Language Access Manager`.

The module creates a configuration permission named `language-access-manager` during install. Superusers can always configure the module. Non-superusers need this permission in addition to whatever ProcessWire admin access your site requires for module configuration.

## How It Works

ProcessWire uses permissions named `page-edit-lang-{language_name}` to restrict page editing by content language.

Important: the ProcessWire language named `default` is the base content language. On many sites its title is `EN`, but its internal name remains `default`. This is different from the admin/profile language a user can select for their own interface.

## Active Content Languages

Use `Active content languages shown in page editor` to choose which language tabs are visible while editing pages.

Examples:

- German-only editing: select `DE`.
- German and English editing: select `DE` and `EN (default / base content language)`.
- Full multilingual editing: select all required languages.
- No selection: all installed languages are shown.

Hidden languages stay installed in ProcessWire. The module marks multi-language fields in Page Edit/Page Add forms via `ProcessPageEdit::buildFormContent` and `ProcessPageAdd::buildForm`, then hides inactive language tabs/panels in that editor UI.

When at least one language is selected in the module configuration:

- the module creates missing `page-edit-lang-*` permissions for selected languages;
- the `default` base content language is automatically added to the controlled language list;
- roles with `page-edit` automatically receive `page-edit-lang-default`;
- selected role/language combinations receive the matching language permission;
- unselected role/language combinations have the matching language permission removed.

When no languages are selected:

- all `page-edit-lang-*` permissions are removed from roles;
- all `page-edit-lang-*` permissions are deleted;
- language-specific edit restrictions are disabled.

## Important Notes

- Superusers are not managed by this module and retain access to all languages.
- The `guest` role is not managed.
- The module intentionally does not delete language edit permissions on uninstall, except for its own `language-access-manager` configuration permission. To disable the language permission system, uncheck all languages and save before uninstalling.
- Be careful on production sites: changing these settings affects real role permissions immediately.
- Active content language filtering is an admin editing convenience. It does not remove language values from pages and does not replace frontend language availability checks.

## Recommended Workflow

1. Select the languages you want to control.
2. Save once to create the required permissions.
3. Review the role matrix.
4. Assign each role only the languages it should edit.
5. Save again and test with a non-superuser account.

## Troubleshooting

If editors cannot create or delete pages, make sure the role has:

- `page-edit`;
- `page-edit-lang-default`;
- the relevant template/page permissions required by your site.

The module automatically grants `page-edit-lang-default` to roles with `page-edit` whenever language permission control is active. This permission controls editing the base content language, not the user's selected admin interface language.

## Frontend Language Switchers

The admin UI filter does not automatically change your frontend templates. Use the module helper methods in your language switcher:

```php
$lam = $modules->get('LanguageAccessManager');

foreach($languages as $language) {
	if(!$lam->isLanguageActive($language)) continue;
	// Render this language in your switcher.
}
```

Or get the active language names directly:

```php
$activeLanguageNames = $modules->get('LanguageAccessManager')->getActiveLanguageNames();
```
