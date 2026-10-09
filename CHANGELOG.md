# Changelog

## 1.0.0 – 2026-10-07

First release.

- Multiple independent phonebooks with unique names and permanent `.xml` feed slugs.
- Yealink `XXXIPPhoneDirectory` XML feed reproducing the supplied working format byte for byte.
- Contact management: add, edit, search, paginate, delete, bulk delete with confirmation, clear phonebook.
- CSV import (delimiter and header detection, encoding handling, column mapping, preview, merge/replace, valid-rows-only, stale-preview detection, atomic commit).
- XML import through the same pipeline, with optional rename from the XML title.
- XML and CSV export with formula-safe cells and a documented round-trip convention.
- Bounded snapshots before destructive operations, with preview and restore.
- Secret-URL keys (query or path form), public mode, key rotation, enable/disable, conditional GET, HEAD, request logging.
- Admin screens: All Phonebooks, phonebook detail (Contacts, Import, Export & snapshots, Feed & access, Settings), plugin Settings, Help & Diagnostics with server self-test.
- Dedicated `manage_site_phonebooks` capability, uninstall policy setting, translation-ready strings.
