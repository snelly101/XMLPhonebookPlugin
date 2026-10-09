# Site Phonebooks for WordPress

Centrally manage multiple site-specific phonebooks in one WordPress installation and serve each one as a Yealink-compatible XML remote phonebook feed.

- One phonebook per site (customer, building or office), each with a unique name and its own permanent `.xml` feed address.
- Add, edit, search and delete contacts in WordPress; changes are published on the next feed request.
- Import CSV (comma, semicolon or tab; UTF-8 with or without BOM) and the existing Yealink XML format, with column mapping, a full preview and merge/replace modes. Imports are atomic.
- Export XML and CSV, bounded recovery snapshots with preview and restore.
- Per-phonebook secret URL keys by default, optional public feeds, key rotation, conditional GET (`ETag` / `Last-Modified`), HEAD support, no theme output in feeds.
- Diagnostics screen with environment checks and a server self-test.

The installable ZIP is in [`dist/site-phonebooks-1.0.0.zip`](dist/site-phonebooks-1.0.0.zip).

## Requirements

| | Minimum | Tested |
|---|---|---|
| WordPress | 6.4 | 7.1.3 |
| PHP | 7.4 (`dom`, `libxml`, `mbstring`, `json` extensions; `intl` optional) | 8.3.6 |
| Database | MySQL 5.7+ / MariaDB 10.3+ with InnoDB (required for atomic imports) | MariaDB 10.11 |

The minimums are the oldest versions the code is written for (no PHP 8-only syntax, WordPress APIs available since 6.4). Only the "Tested" column was actually exercised; see [docs/test-results.md](docs/test-results.md).

## Installation

1. In WordPress go to **Plugins → Add New → Upload Plugin**, choose `site-phonebooks-1.0.0.zip` and click **Install Now**, then **Activate**.
   Or unzip it into `wp-content/plugins/` so you have `wp-content/plugins/site-phonebooks/site-phonebooks.php`, then activate it from the Plugins screen.
2. A **Phonebooks** menu appears for administrators. No other configuration is required.
3. If feeds return 404 after activation on a site with pretty permalinks, open **Settings → Permalinks** and click **Save Changes** once to rebuild rewrite rules.

No Composer, Node or build step is needed on the server; the ZIP contains everything.

## Quick start

1. **Phonebooks → Add Phonebook**, enter the site name (for example `Frickley Mews`) and create it.
2. On the **Import** tab upload a CSV (template available on the same screen) or add contacts by hand on the **Contacts** tab.
3. On the **Feed & access** tab copy the feed URL, for example
   `https://example.com/phonebooks/frickley-mews.xml?key=…`
4. Enter that URL in the handset's remote phonebook settings. See the [user guide](docs/user-guide.md) for Yealink notes.

Feed address forms:

| Situation | Address |
|---|---|
| Protected (default), query key | `https://example.com/phonebooks/{site}.xml?key={key}` |
| Protected, key in path (if a handset drops query strings) | `https://example.com/phonebooks/{key}/{site}.xml` |
| Public phonebook | `https://example.com/phonebooks/{site}.xml` |
| Plain (non-pretty) permalinks | `https://example.com/?spb_feed={site}.xml&key={key}` |
| WordPress in a subdirectory | `https://example.com/blog/phonebooks/{site}.xml?key={key}` |

The site name can be changed at any time without changing the address. The file name (slug) can only be changed deliberately, with a confirmation, because handsets must then be reconfigured.

## XML format

Feeds reproduce the owner's known-working file exactly:

```xml
<?xml version="1.0" encoding="utf-8"?>
<XXXIPPhoneDirectory clearlight="true">
  <Title>Frickley Mews</Title>
  <Prompt>Prompt</Prompt>
  <DirectoryEntry>
    <Name>Reception</Name>
    <Telephone>1001</Telephone>
  </DirectoryEntry>
</XXXIPPhoneDirectory>
```

`Title` is the site name, `Prompt` defaults to `Prompt` (editable per phonebook), one name and one telephone per entry, UTF-8 without BOM, entries ordered by name then telephone. Telephone values are stored and emitted verbatim (`0001`, `+441234567890`).

## Updates

Upgrading the plugin (replacing the folder or uploading a newer ZIP) keeps all phonebooks, contacts, keys and URLs. Schema changes run automatically on the next page load when the stored schema version differs from the plugin's; rewrite rules are flushed only when the route version changes.

## Data retention and uninstall

- **Deactivating** never removes data.
- **Uninstalling** (deleting the plugin) keeps all tables and settings **unless** *Phonebooks → Settings → Delete all data on uninstall* has been enabled, in which case the four `spb_*` tables, the plugin options and the `manage_site_phonebooks` capability are removed.

## Security summary

- Only users with the `manage_site_phonebooks` capability (granted to administrators on activation) can see or change anything. Every mutating action is a nonce-protected POST; nothing changes state on GET.
- Feeds never require a WordPress login. Protected feeds require a 160-bit random key compared in constant time; wrong or missing keys get a 403 with no contact data, disabled or unknown feeds get a 404. Keys are masked in lists, logs and diagnostics and can be rotated.
- Authentication happens before any cached output or `304` response. Protected feeds are sent `Cache-Control: private, no-cache` and `X-Robots-Tag: noindex`. Exclude `/phonebooks/*` from page caches, CDNs and security plugins.
- Uploads are staged in the database (never as public files), scoped to the uploading user, and deleted after commit, cancel or expiry.
- XML imports reject DOCTYPE/entity declarations, use `LIBXML_NONET`, and are size-limited before parsing. CSV exports neutralise spreadsheet formulas without altering telephone text.
- No telemetry, external services or licence checks. Feed logs record only the time, HTTP status and user agent of the last request.

## Documentation

- [User guide](docs/user-guide.md) – workflows and Yealink setup notes
- [Developer notes](docs/developer-notes.md) – architecture, schema, security decisions, running the tests
- [Test results and known limitations](docs/test-results.md)
- [Changelog](CHANGELOG.md)
- Example files in [`examples/`](examples/) (fictional contacts only)

## Multisite

Not tested. Activate the plugin per site; network activation installs tables for the current site only. Each site then has its own phonebooks, keys and feeds.

## Licence

GPL-2.0-or-later.
