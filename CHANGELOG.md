# Changelog

## 0.2.2 — 2026-10-06
- Inventory reports, per table, whether ACF Custom Database Tables is actually reading it, based on its runtime table map cache (`.cache/_table_map.php`). Red warning in wp-admin when it isn't (see Find-a-Doc #813).
- A real backfill is refused while the plugin isn't reading a table, unless explicitly overridden (`--allow-stale-cache` / checkbox). Dry runs are still allowed.
- Change records include the post's `modified` date, to help review `overwrite` rows.

## 0.2.1 — 2026-10-05
- Fix: values containing backslashes lost one level of escaping when written to postmeta (`update_metadata()` unslashes). Values are now `wp_slash()`ed before `acf_update_value()`.
- ID fields (taxonomy, relationship, post object, gallery, user, image, file): ID `0` and empty entries are ignored when comparing, and array-key gaps don't count as differences.
- ID fields holding something that isn't an ID (e.g. a corrupted `"10522]"`) are flagged `invalid` and not written; fix by hand and re-run.

## 0.2.0 — 2026-10-05
- wp-admin screens (Tools → ACFCDT Migration per site, Network Admin → Settings overview), so the migration runs on hosts without WP-CLI.
- Jobs run as chained, retryable AJAX batches with saved progress and Resume.
- All state (snapshots, runs, change log, postmeta backups) stored in one table, `wp_acfcdt_migration`. Downloads for archives, snapshots, changes, backups and compare reports.
- WP-CLI commands share the same engine and storage; added `status`.

## 0.1.0 — 2026-10-05
- Initial WP-CLI tool: inventory, archive, snapshot, compare, backfill (dry run, backup, read-back verify), rollback.
