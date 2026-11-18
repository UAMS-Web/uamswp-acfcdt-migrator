Written against v0.3.0.

After a retention period (for example 90 days) with ACF Custom Database Tables deactivated and nothing found wrong, remove what the migration left behind. Each step below removes a way back, so keep the archives and the database backup.

1. Remove the `store_acf_values_in_core_meta` filter and the `ACFCDT_JSON_DIR` define from `UAMSWP-Find-a-Doc/includes/class.fad-acf-functions.php`.
2. Remove `assets/json/acf-tables/` and the `acfcdt_*` keys from `group_uamswp_physicians.json`.
3. Simplify `uamswp_custom_table_query()` to the postmeta branch only.
4. Drop the `wp_*_uamswp_physicians` and `wp_*_uamswp_locations` tables (keep the archive and the database backup).
5. Download any backups to keep, then delete the migrator plugin and drop `wp_acfcdt_migration`.

**Warning:** Dropping `wp_acfcdt_migration` deletes every snapshot, run log and postmeta backup, so a rollback is no longer possible afterward.

## Reference

- [Stored data](Stored-data.md)
- [Known data notes](Known-data-notes.md): the orphaned columns kept only in the archive
