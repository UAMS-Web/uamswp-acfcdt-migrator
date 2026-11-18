Written against v0.3.0.

- [A red warning says the plugin is NOT reading a table](#a-red-warning-says-the-plugin-is-not-reading-a-table)
- [A dry run reports about 0 changes on a first run](#a-dry-run-reports-about-0-changes-on-a-first-run)
- [A real run is refused](#a-real-run-is-refused)
- [A request fails with a timeout, 502 or 504](#a-request-fails-with-a-timeout-502-or-504)
- [A job stopped partway, or the tab was closed](#a-job-stopped-partway-or-the-tab-was-closed)
- [A second dry run still shows changes](#a-second-dry-run-still-shows-changes)
- [A run reports mismatches](#a-run-reports-mismatches)
- [Not allowed, with a note about an expired nonce](#not-allowed-with-a-note-about-an-expired-nonce)
- [A snapshot or run of that name already exists](#a-snapshot-or-run-of-that-name-already-exists)

## A red warning says the plugin is NOT reading a table

In wp-admin the warning begins **Plugin is not reading the custom table:**, followed by `The plugin is NOT reading <table> for "<post type>" posts`, or, when the cache file is missing altogether, `No table map cache at <path>`.

**Cause:** ACF Custom Database Tables chooses the tables it reads from its table map cache, `assets/json/acf-tables/.cache/_table_map.php`, and the cache names other post types (the committed cache has said `physicians` and `locations` since 2021). The site is serving postmeta for those posts, not the table.

**Fix:** click *Create/Update Tables* (or ship [UAMSWP-Find-a-Doc#813](https://github.com/UAMS-Web/UAMSWP-Find-a-Doc/issues/813)) so the plugin reads the tables, then take `before` again and redo the dry run. See [Migrating production](Migrating-production.md).

## A dry run reports about 0 changes on a first run

**Cause:** usually the stale table map cache above: the plugin and postmeta agree because the plugin is serving postmeta.

**Fix:** check the inventory for the red warning, and fix that first. A dry run of 0 is only a finished migration when the plugin is reading every table.

## A real run is refused

The message is one of:

- `The plugin is NOT reading ...` or `No table map cache at ...`, ending `(Override only if you have decided postmeta is the current copy.)`: the stale or missing cache above. Fix the cache, or check *Real run anyway* (`--allow-stale-cache`) only after deciding that postmeta is the current copy.
- `ACF Custom Database Tables intercepts not found`: the plugin is not active on the site, so there is nothing to backfill from, and any backfill, dry or real, is refused. Activate it. In wp-admin the *Run backfill* button is disabled instead, with *Needs ACF Custom Database Tables active.* If the message ends `was the plugin deactivated mid-run?`, the plugin went away during the run: reactivate it and **Resume**.

## A request fails with a timeout, 502 or 504

The log shows `HTTP <status>` and says the response was not JSON, which is likely a timeout or a PHP error.

**Cause:** the host's time limit. Long jobs run as a chain of short requests: 25 posts per request for a snapshot, and the *Posts per request* setting for a backfill (default 10). Progress is saved after every batch, and every batch is safe to repeat.

**Fix:** a brief failure is retried automatically. If timeouts keep coming, lower *Posts per request*.

## A job stopped partway, or the tab was closed

**Cause:** the browser drives the chain of requests, so closing the tab or a browser crash stops it.

**Fix:** reopen the screen. The job shows as *running* with a **Resume** button, which continues from the last saved batch. A rollback that stopped shows **Resume rollback**.

## A second dry run still shows changes

**Cause:** an edit landed after the real run, or a field is flagged `invalid`.

**Fix:** run the real backfill again for late edits. An `invalid` field is never written: fix the post by hand with the plugin active (re-select the value and save), then run the backfill again. See [Backfill results](Backfill-results.md#flags-on-a-record).

## A run reports mismatches

The log reads `Post <id> field <name>: postmeta does not match after write.`

**Cause:** the value read back from postmeta after the write differs from the plugin's value.

**Fix:** open the run's **Changes** download, find the records flagged `write_mismatch`, and compare `effective` with `meta_after`. The migration is not clean until a run reports 0 mismatches; [report a problem](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues) if one persists.

## Not allowed, with a note about an expired nonce

The message begins `Not allowed (permission or expired nonce` and says to reload the page.

**Cause:** the screen was open long enough for its security token to expire, or the account is not a super admin.

**Fix:** reload the page. Only super admins (or `manage_options` on a single site) can use the screens.

## A snapshot or run of that name already exists

**Cause:** names are unique per site.

**Fix:** pick another name, or check *overwrite if it exists* (`--force` on the command line) for a snapshot. A backfill run name cannot be reused.
