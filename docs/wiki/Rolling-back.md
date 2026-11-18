Written against v0.3.0.

A backfill never writes to the custom tables, so going back is always possible while the tables and the plugin are still there. Choose one of the two routes below.

## Reactivate the plugin and roll back the run

1. **Network Admin → Plugins:** network-activate *ACF Custom Database Tables*. The site reads the tables again, exactly as before.
2. On each site, in **Tools → ACFCDT Migration**, use **Roll back** on the real run. It restores the original postmeta exactly, including the rows ACF rewrites on related posts through bidirectional relationships.
3. Confirm from the run: the rollback reports how many fields it restored, and the run's Rollback column shows it complete. A snapshot compared with `before` cannot confirm it: with the plugin active, both read the custom tables, which the backfill never changed.

From the command line, `rollback <stamp> --network` does step 2 on every site that has the run. A `backfill --network` from the command line gives every site the same stamp, so one command covers the network. A run started in wp-admin has a different stamp on each site, so roll each one back on its own site.

**Note:** Any edit saved after the plugin was deactivated is in postmeta only, and is hidden again while the plugin is active.

**Note:** A rollback refuses a dry run (there is nothing to restore) and refuses a run that was already rolled back unless `--force` is given.

## Restore the database backup

Restore the backup taken before the run. This returns everything, including the migration's own records in `wp_acfcdt_migration`.

## Reference

- [WP-CLI commands](WP-CLI-commands.md#rollback)
- [Stored data](Stored-data.md): where the backups a rollback reads are kept
