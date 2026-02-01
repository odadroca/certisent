# Localization (i18n)

Sentryficate supports basic UI localization via a minimal translation catalog and a per-user locale preference.

## Concepts

- **Locale**: language tag like `en` or `pt`. Stored per user in `users.locale`.
- **Translation key**: string in dot-notation, e.g. `nav.dashboard`.
- **Catalog**: `app/locales/<locale>.php` returning an associative array: `['key' => 'Text']`.
- **Fallback**: selected locale → `en` → key itself.

## How locale is selected

1. `$_SESSION['locale']` (cached)
2. `users.locale` (when logged in and the column exists)
3. Default: `en`

Note: on login, Sentryficate resets the cached session locale to the user's saved `users.locale` so a previously cached guest locale does not override the preference.

## Adding a new locale

1. Add a new catalog file:
   - Copy `app/locales/en.php` to `app/locales/<new>.php`
2. Add the locale code to `supported_locales()` in `app/i18n.php`
3. (Optional) expose it in the Settings UI dropdown (`public/settings.php`)
4. Run the audit tool to find missing keys:
   - `php tools/i18n_audit.php`

## Locale-aware date/number formatting (opt-in)

Config flag (default off):

- `I18N_FORMAT_DATES=false`

When enabled (`true`, `1`, `yes`, `on`), UI helper functions will attempt to format:
- datetimes via `IntlDateFormatter`
- numbers via `NumberFormatter`

If the PHP `intl` extension is not available, formatting falls back to the original string/number output.

### Helpers

- `ui_dt($dbDatetime)` for DB datetime strings (UTC)
- `ui_num($n)` for numbers

Pages should still HTML-escape the returned string (e.g. `h(ui_dt(...))`).

## Required keys

There is no hard “required list”; required keys are defined by usage in code (`t('...')`).
Use the audit tool output as the source of truth for missing keys per locale.
