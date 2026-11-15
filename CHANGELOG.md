# Changelog

## 0.3.0 (2026-11-15)
- Requires WordPress 6.8 or later (`Requires at least` in the plugin header) and PHP 8.2 or later. Queries bind table names with the `%i` placeholder, which needs WordPress 6.2 or later.
- Fix: a backfill now backs up every other postmeta row a field write touches (for example the reference row ACF Extended's bidirectional relationships rewrite on the related post), so a rollback restores it.
- The plugin header names UAMS Web Services as the author and carries a Plugin URI; the README lists the developers.
- The plugin version is read from the header, so the header is the only place it is set.
- A GitHub download of the plugin holds only the files the plugin runs.
- Internal: Pest unit and integration suites, mutation testing, PHPStan at level 10, Rector and Pint.

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
