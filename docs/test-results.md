# Test results, environment and known limitations

Date: 2026-10-07. Plugin version 1.0.0.

## Environment actually used

| Component | Version |
|---|---|
| PHP | 8.3.6 CLI (extensions: dom, libxml, xmlwriter, mbstring, intl, json, mysqli) |
| WordPress | 7.1.3 (core from wordpress.org; test library from the `wordpress-develop` 7.1.3 tag) |
| Database | MariaDB 10.11.14, InnoDB, utf8mb4 |
| Web server for live checks | PHP built-in server with a router script emulating front-controller rewrites |
| Browser | Chromium (Playwright), 1280×900 and 390×844 viewports |
| OS | Linux (Ubuntu 24.04 container, 4 vCPU) |

Nothing was deployed to a live website and no physical handset was involved.

## Automated verification

### Unit tests (no WordPress) – `vendor/bin/phpunit --testsuite unit`

41 tests, 154 assertions, all passing.

- XML generator reproduces the supplied `yealink_phonebook.xml` structure byte for byte (fixture round-trip); escapes `& < > " '`; preserves accents and non-Latin text; preserves `0001` and `+441234567890`; rejects control characters and invalid UTF-8; empty phonebook is well-formed.
- XML parser: accepts BOM and CRLF, decodes entities/CDATA, rejects DOCTYPE/ENTITY (XXE), malformed XML, wrong root, empty/binary/non-UTF-8 input, oversized input before parsing; warns on unexpected elements.
- CSV parser: BOM stripping, line endings, non-UTF-8 detection with guidance, explicit Windows-1252 conversion, UTF-16 BOM conversion, delimiter detection (quoted commas ignored), quoted commas and doubled quotes, blank rows, literal backslashes, row limit, header/mapping guesses.
- CSV exporter: formula neutralisation (`=`, `@`, `+cmd|…`, `-…`, tab) with telephone values untouched; full round-trip through the parser; template round-trip.
- Contact validation: normalisation policy, required fields, control characters, lengths (multibyte-aware), dial-string warnings, exact case-sensitive duplicate hashing.
- Slugs (collisions, transliteration, fallback) and tokens (format, constant-time comparison, cleaning, masking).

### WordPress integration tests – `vendor/bin/phpunit --testsuite integration`

39 tests, 386 assertions, all passing. Mapped to the brief's acceptance list:

| # | Acceptance item | Test(s) |
|---|---|---|
| 1 | Two sites → distinct feeds, correct title, isolated contacts | `PhonebookTest::test_two_sites_produce_distinct_isolated_feeds` |
| 2 | Name/slug collisions handled without overwriting | `test_duplicate_names_are_blocked_and_slug_collisions_resolved`, `test_non_latin_name…`, `test_explicit_slug_change…` |
| 3 | Rename updates title, keeps URL | `test_renaming_updates_title_but_keeps_url`; XML import rename case in `ImportTest` |
| 4 | XML structure + special characters parse | unit `YealinkXmlGeneratorTest`; every feed assertion parses the body with DOMDocument |
| 5 | `0001` / `+441234567890` survive storage, import, export | `test_telephone_strings_survive_storage_and_export`, `ImportTest`, unit exporter round-trip |
| 6 | Duplicate names with different numbers; exact duplicates per policy | `test_contacts_are_scoped_and_duplicates_follow_policy`, `ImportTest::test_preview_leaves_live_data_unchanged…` |
| 7 | CSV mapping, delimiters, BOM, quoted commas, blank rows, validation | `ImportTest::test_csv_mapping_delimiters_bom_and_encoding`, `test_invalid_rows_block…`, unit `CsvParserTest` |
| 8 | Preview leaves live data unchanged; commit applies exactly once | `test_preview_leaves_live_data_unchanged_and_commit_applies_once` |
| 9 | Replace removes intended records, merge retains, failures roll back | `test_replace_removes_only_missing_contacts…`, `test_storage_failure_rolls_back_everything` (sabotaged INSERT → full rollback incl. snapshot) |
| 10 | Edit after preview detected | `test_edit_after_preview_is_detected` |
| 11 | Malformed/unsafe/oversized XML rejected without changes | `test_unsafe_or_malformed_xml_is_rejected_without_changes`, `test_row_limit_is_enforced_before_commit`, unit parser tests |
| 12 | Anonymous/unauthorised users cannot manage or read protected feeds | `InstallerAndSecurityTest::test_capability…`, `FeedTest::test_protected_feed_requires_valid_token`, staging scoped per user |
| 13 | Tokens scoped; rotation/disablement effective despite cache | `test_tokens_are_scoped_to_one_phonebook`, `test_rotation_and_disablement_take_effect_despite_cache` |
| 14 | Changes invalidate stale output; conditional requests don't bypass auth | `test_content_changes_invalidate_cached_output_and_validators`, `test_conditional_requests_never_bypass_authentication` |
| 15 | CSV export formula safety + round-trip | unit `CsvExporterTest` |
| 16 | Restore updates contacts and feed | `RevisionTest::test_restore_updates_contacts_and_feed` |
| 17 | Activation, reactivation, upgrade, uninstall policy | `InstallerAndSecurityTest`, `ZzUninstallTest` |
| 18 | Subdirectory and plain-permalink URLs | `FeedTest::test_urls_for_pretty_plain_and_subdirectory_installs`, `test_rewrite_routing_dispatches_to_controller` (pretty, path-key, `?spb_feed=`, `index.php?spb_feed=`) |
| 19 | Admin keyboard navigation, labels, empty states, error feedback | browser checks below |

### Browser checks (Playwright + Chromium against a local WordPress 7.1.3)

66/66 checks passed, no JavaScript console errors, empty `debug.log` (WP_DEBUG on). Covered: list screen and empty/seeded states; Add Phonebook; duplicate-name error; add/edit/delete contact with notices and form retention after a validation error; feed URL from the screen returns the edited contact; CSV upload → mapping (auto-detected header, columns, semicolon delimiter) → preview counts and rows → merge apply; replace apply with confirmation and removal counts; snapshot list, preview and restore reflected in the feed; XML and CSV downloads; XML import with duplicate skipping; bulk delete confirmation panel; single row delete; search; key rotation (old URL 403, new 200); disable (404) and public mode; rename keeps URL; slug collision blocked and slug change with warning; plugin settings save; diagnostics with no error rows and no tokens in page source; server self-test; keyboard tab order reaches search, bulk action and form controls; every form control has a label; anonymous users are redirected and `admin-post` actions rejected; plugin assets absent from unrelated admin pages; no horizontal overflow at 390 px width.

### Packaged ZIP verification

`dist/site-phonebooks-1.0.0.zip` was installed with `wp plugin install … --activate` on a second fresh WordPress 7.1.3 site: tables and capability created; feed served with plain permalinks (`/?spb_feed=zip-site.xml&key=…`) and pretty permalinks; deactivate/reactivate preserved data and URL; uninstall kept all data by default (phonebook present after reinstall); uninstall with *Delete all data on uninstall* enabled dropped all four tables. `debug.log` stayed empty.

### Live feed checks (curl)

200 with correct headers and byte-exact XML (no BOM), 403 without/with wrong key (77-byte generic body), 200 via path-key URL and fallback URL, 404 unknown slug, 304 on matching `ETag` only when the key is valid (403 otherwise), HEAD 200 without body.

## Performance benchmark (backend only)

Dataset: 100 phonebooks × 1,000 contacts (100,000 contacts) on the environment above, measured with WP-CLI (no opcode cache warmup, non-persistent object cache):

| Operation | Result |
|---|---|
| Seed 100 × 1,000 contacts (batched inserts) | 2.65 s |
| Render one 1,000-contact feed (111 KB) | 7.5 ms cold, 0.6 ms from object cache |
| Conditional GET → 304 | 0.3 ms |
| Contacts page 10 (50 per page) | 2.2 ms |
| Contact search in 1,000 | 3.2 ms |
| Phonebook list search across 100 | 0.6 ms |
| Import preview, 1,500-row CSV vs 1,000 existing (Replace) | 28.9 ms |
| Import commit (snapshot + 500 deletes + 1,000 inserts, transactional) | 43.5 ms |
| Peak PHP memory during that import | 57 MB (55 MB baseline for WP-CLI) |
| Render all 100 feeds cold | 0.67 s |

This is a backend benchmark, not a handset capacity claim; Yealink models have their own remote phonebook limits.

## Not verified / pending

- **Physical Yealink handsets**: no phone fetched a feed. Query-string key support, path-key support, HTTPS/TLS trust, refresh behaviour and contact limits must be confirmed per model and firmware on the owner's phones. The format itself matches the owner's known-working file byte for byte.
- **Production hosting**: Apache/Nginx rewrite behaviour, page caches, CDNs and security plugins were not tested (guidance is in the user guide and Help screen). The server self-test and rewrite checks in Diagnostics help confirm a deployment.
- **PHP 7.4–8.2 and WordPress 6.4–7.0**: the code avoids PHP 8-only syntax and newer WordPress APIs, but only PHP 8.3 / WordPress 7.1.3 were executed.
- **MySQL (non-MariaDB)** and hosts whose default engine is not InnoDB: imports would not be atomic on MyISAM; Diagnostics flags the engine.
- **WordPress Multisite**: not tested; per-site activation only.
- **Browser matrix**: only Chromium was driven automatically. Firefox and Safari were not tested; the UI uses standard WordPress admin markup and the `form` attribute on buttons (supported by all current browsers).
- **Concurrency under real parallel requests**: serialisation relies on InnoDB row locks and was tested with sequential stale-revision and double-commit scenarios, not with truly concurrent processes.

## Known limitations

- One name and one telephone per entry (the compatibility profile); additional numbers in imported XML are ignored with a warning.
- Merge mode does not detect number changes for an existing name (documented; use Replace or edit manually).
- Duplicate detection is exact and case-sensitive after whitespace normalisation.
- Phonebook names in scripts the server cannot transliterate (no `intl`) get `phonebook-N` file names; rename the file name on the Settings tab if a readable one is wanted.
- The import preview table shows the first 500 rows (counts cover all rows); snapshot previews show up to 1,000 contacts.
- The CSV export convention adds a leading apostrophe to formula-like text cells; the importer reverses it, other tools will show the apostrophe.
