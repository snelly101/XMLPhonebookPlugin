# Site Phonebooks – Developer notes

## Layout

```
site-phonebooks.php            Plugin header, constants, autoloader, activation hooks
uninstall.php                  Uninstall policy (keeps data unless the setting is enabled)
includes/
  autoload.php                 PSR-4-ish autoloader: SitePhonebooks\Feed\Foo_Bar -> includes/feed/class-foo-bar.php
  class-plugin.php             Boot: hooks router, admin, housekeeping cron, textdomain
  class-installer.php          dbDelta schema, capability, upgrade + rewrite-version flush
  class-settings.php           Single small autoloaded option with sanitisation
  class-db.php                 Transactions (savepoint-aware), error surfacing
  class-phonebook-repository.php / class-contact-repository.php / class-revision-repository.php
  class-contact-validator.php  Normalisation, validation, dedupe hash
  class-slug.php, class-token.php
  class-diagnostics.php
  csv/   Csv_Parser (decode, detect delimiter, fgetcsv), Csv_Exporter (formula-safe)
  xml/   Yealink_Xml_Generator (DOMDocument), Yealink_Xml_Parser (hardened import)
  feed/  Feed_Router (rewrites, query vars, parse_request), Feed_Controller (auth, 304, cache), Feed_Cache, Feed_Response
  import/ Import_Staging (DB-staged uploads), Import_Planner (validate + diff), Import_Committer (atomic apply)
  admin/ Admin (menus, assets), screens, list tables, Actions (admin-post handlers), Notices
assets/css/admin.css, assets/js/admin.js   Progressive enhancement only
languages/site-phonebooks.pot
tests/unit, tests/integration, tests/fixtures
examples/                      Fictional CSV/XML samples
bin/build-zip.sh               Builds dist/site-phonebooks-<version>.zip using .distignore
```

Slug: `site-phonebooks`. Namespace: `SitePhonebooks\`. Prefix for options/tables/hooks/CSS: `spb_` / `spb-`. Text domain: `site-phonebooks`. Capability: `manage_site_phonebooks`.

## Storage model

Custom InnoDB tables, created with `dbDelta` and versioned by the `spb_db_version` option (`SPB_DB_VERSION`). Why not posts/postmeta: phonebooks need thousands of rows each with database-level uniqueness, cheap paginated/sorted queries, batched inserts and transactional replace imports. Posts would mean one post per contact with meta joins, or serialised blobs; neither gives constraints or atomicity. Nothing large is stored in options; the only option is a few settings (autoloaded, small).

```
wp_spb_phonebooks   id, name (UNIQUE, ci collation), slug (UNIQUE), description, prompt,
                    access_mode token|public, access_token (40 hex), enabled,
                    revision, contact_count, content_updated_at,
                    last_request_at, last_request_status, last_request_agent, created_at, updated_at
wp_spb_contacts     id, phonebook_id, name, telephone, notes, dedupe_hash (sha1 name\ntelephone),
                    created_at, updated_at; UNIQUE(phonebook_id, dedupe_hash); KEY(phonebook_id, name(100))
wp_spb_revisions    id, phonebook_id, revision, reason, contact_count, payload (JSON), created_by, created_at
wp_spb_imports      id, phonebook_id, user_id, status uploaded|preview|committed, source_type, filename,
                    raw_content (LONGBLOB), options (JSON), base_revision, plan (JSON), created_at, expires_at
```

All times are GMT (`gmdate`). Contact IDs are stable; Replace imports only delete rows whose hash is absent from the file, so unchanged contacts keep their IDs and notes.

`revision` increments on every content change (contact add/edit/delete, import, restore, clear, title/prompt change). It drives the `ETag`, the object-cache validation and the stale-preview check (`base_revision`).

### Migrations

`Installer::maybe_upgrade()` runs on `init` when `spb_db_version` differs: `dbDelta` with the full schema (idempotent) and capability re-grant. Add new columns/indexes to the `CREATE TABLE` strings and bump `SPB_DB_VERSION`; add data migrations as explicit steps keyed on the old version. Bump `SPB_REWRITE_VERSION` when rewrite rules change; rules are flushed on activation and once after a route version change, never per request.

### Transactions

`Db::transaction()` wraps `START TRANSACTION … COMMIT/ROLLBACK`. The WordPress test suite already holds a transaction per test, so the `spb_db_in_outer_transaction` filter switches the plugin to `SAVEPOINT`s there; semantics are identical. `Db::check()` throws if `$wpdb->last_error` is set after a write, so a failed statement aborts and rolls back the unit of work. Import commits and restores lock the phonebook row (`SELECT … FOR UPDATE`) and, for imports, the staging row, so concurrent commits serialise and a staged import can be applied exactly once.

## Feed delivery

- Rewrite rules (priority `top`): `^phonebooks/([a-f0-9]{20,128})/([a-z0-9-]+)\.xml$` and `^phonebooks/([a-z0-9-]+)\.xml$` → `index.php?spb_feed=…[&spb_key=…]`. The query key is read from `?key=`. Base path filterable with `spb_feed_base`.
- `Feed_Router::maybe_serve()` on `parse_request` priority 1: builds a `Feed_Response` through `Feed_Controller::handle()`, records the request, fires `spb_feed_response`, discards any output buffers, sends headers + body and exits. Canonical redirects are suppressed for feed requests.
- `Feed_Controller::handle()` order: method check → slug lookup (404 if missing/disabled) → token check (403) → validators → 304 → object cache (validated by revision) → render. No cache read or 304 before authentication; the cache key is per phonebook and the entry is only used when its stored revision equals the current one, so cross-phonebook or stale serving is impossible.
- Headers: `Content-Type: application/xml; charset=utf-8`, `Content-Disposition: inline; filename="slug.xml"`, `ETag`, `Last-Modified`, `Cache-Control: private, no-cache, max-age=N, must-revalidate` (protected) or `public, max-age=N, must-revalidate`, `X-Robots-Tag: noindex, nofollow`, `X-Content-Type-Options: nosniff`. Errors are a tiny generic XML document.
- The last-request log is one `UPDATE` of three columns on the phonebook row (no IP, no token, user agent truncated to printable ASCII).

## XML

`Yealink_Xml_Generator` uses `DOMDocument` with `formatOutput` to reproduce the sample byte for byte (`encoding="utf-8"` lowercase, two-space indent, `&amp; &lt; &gt;` escaping only). Values are checked for valid UTF-8 and the XML 1.0 character range; violations throw `InvalidArgumentException` (surfaced as a validation error at save/import time, and as a 500 with a generic body if stored data is somehow invalid). `PROFILE_ID` identifies this profile for future manufacturer formats.

`Yealink_Xml_Parser` strips BOM, requires UTF-8, rejects any `<!DOCTYPE`/`<!ENTITY`, counts `<DirectoryEntry` before parsing to enforce the row limit, loads with `LIBXML_NONET`, `resolveExternals=false`, `substituteEntities=false`, requires the exact root element, and warns about unexpected elements and extra `<Telephone>` elements (only the first is used in this profile).

## Imports

1. **Upload** (`Actions::import_upload`): size check against the setting, file read, type detection (extension or leading `<`), XML pre-parse or CSV decode probe, staging row with raw bytes (`Import_Staging::create`). Temp file deleted.
2. **Map** (`import_map`): options saved, rows extracted (`Import_Planner::rows_from_csv/rows_from_xml`), plan computed (`Import_Planner::plan`) against the current dedupe hashes, stored with `base_revision`.
3. **Commit** (`Import_Committer::commit`): transaction, lock phonebook + staging row (`FOR UPDATE`), verify status `preview` and `base_revision == revision`, snapshot for Replace, delete by hashes, batch insert (200 rows per statement), optional rename, bump revision, mark committed; then cache delete, staging row delete, snapshot prune. Any exception → rollback and `WP_Error`; the staging row stays in `preview` so the user can retry after re-previewing.

Row statuses: `add`, `duplicate_existing`, `duplicate_in_file`, `invalid`. Blocking conditions: no rows, invalid rows without `valid_only`, Replace producing an empty list, Merge with nothing to add, rename collision.

## Admin

Menus under `Phonebooks` (`add_menu_page`, `dashicons-phone`). Screens render with escaped output; all mutations go through `admin-post.php?action=spb_*` handlers in `Admin\Actions` which check `current_user_can( 'manage_site_phonebooks' )`, `check_admin_referer()` with per-phonebook nonces, and load the phonebook by ID from the request so cross-phonebook operations are impossible (every repository query is scoped by `phonebook_id`). Feedback is passed across the redirect as per-user transient notices.

Assets are enqueued only on the plugin's own hook suffixes. JavaScript adds copy-to-clipboard, confirm dialogs, double-submit protection and a bulk-action guard; every flow works with JavaScript disabled (bulk delete uses a server-rendered confirmation panel, row deletes use `<button form="…">`).

`WP_List_Table` is used for both lists. The contacts table overrides `display_tablenav()` to avoid WordPress's own `_wpnonce` field, which would override the surrounding form's nonce, and renders the bulk selector as `bulk_action` so it does not collide with admin-post's `action` parameter.

## Security decisions

- Capability: dedicated `manage_site_phonebooks`, granted to `administrator` on activation/upgrade; never to editors or subscribers.
- Tokens: `random_bytes(20)` → 40 hex chars, compared with `hash_equals`, masked (`abcd••••••••••••wxyz`) outside the URL fields on the Feed tab, never written to logs or diagnostics. Rotation replaces the value in place; all previous URLs fail immediately.
- Feeds return 403/404 without contact data; error bodies carry no SQL, paths or stack traces. `WP_DEBUG_DISPLAY` output cannot leak because output buffers are discarded before the XML is sent (debug output printed *before* `parse_request` by other code would still be removed).
- Uploads live in `wp_spb_imports.raw_content`, not on disk; `.htaccess` is not relied upon. The temp upload file is deleted immediately.
- CSV export: `Csv_Exporter::protect_cell()` prefixes `'` to cells starting with `=`, `@`, tab, CR, or `+`/`-` that are not telephone-like (`/^[+\-]?[0-9 ()\-.#*]*$/`). `unprotect_cell()` removes one leading `'` only when followed by `=+-@`; round-trip is unit tested, including `+44…` and `0001`.
- No remote fetching of import URLs; no telemetry.
- Multisite: per-site tables; network activation is not special-cased beyond installing for the current site. Untested.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `spb_feed_base` | filter | Change the `/phonebooks/` path segment (lowercase a-z0-9-). Bump rewrite flush (Settings → Permalinks → Save) after changing. |
| `spb_feed_response` | action | Observe the `Feed_Response` before it is sent (used by tests). |
| `spb_db_in_outer_transaction` | filter | Return true when the caller already holds a transaction (test suites). |

## Running the tests

```bash
composer install                       # dev dependencies only (PHPUnit 9, Yoast polyfills)
composer test:unit                     # no WordPress or database needed

# Integration tests need the WordPress test library and a MySQL/MariaDB database:
git clone --depth 1 --branch 7.1.3 https://github.com/WordPress/wordpress-develop /path/to/wordpress-develop
cp /path/to/wordpress-develop/wp-tests-config-sample.php /path/to/wp-tests-config.php   # edit DB settings; ABSPATH = /path/to/wordpress-develop/src/
WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit \
WP_TESTS_CONFIG_FILE_PATH=/path/to/wp-tests-config.php \
vendor/bin/phpunit --testsuite integration
```

The integration bootstrap installs the plugin tables and truncates them before the run; each test runs inside the WordPress test suite's transaction. `ZzUninstallTest` runs last and recreates the real tables after exercising `uninstall.php`.

Browser-level checks were run with Playwright against a local WordPress (script not shipped; see `docs/test-results.md` for what was covered).

## Building the ZIP

```bash
bin/build-zip.sh      # -> dist/site-phonebooks-<version>.zip
```

`.distignore` excludes tests, vendor, docs, examples, bin and dev config. The script lints every PHP file in the staged copy before zipping.

## Extending

- **Another manufacturer format**: add `includes/xml/class-<vendor>-xml-generator.php` with its own `PROFILE_ID`, and a `profile` column on phonebooks selecting the generator in `Feed_Controller::render()`. Keep the Yealink profile untouched.
- **Per-site permissions**: add a `manage_site_phonebook_{id}` style capability check in `Actions::phonebook()` and the screens.
- **Scheduled imports**: reuse `Import_Planner` + `Import_Committer` from a cron handler with an allow-listed source; everything below the upload step is already headless.
