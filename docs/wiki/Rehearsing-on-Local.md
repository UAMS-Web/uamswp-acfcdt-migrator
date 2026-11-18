Written against v0.3.0.

At the end of this walkthrough every value ACF Custom Database Tables serves on a local copy of the multisite is in postmeta, the plugin is deactivated, and a compare shows the site reading exactly what it read before. Rehearse at least twice before [Migrating production](Migrating-production.md).

Every step happens in **Tools → ACFCDT Migration** on a site that has custom tables, except where it says Network Admin. The [Admin screens](Admin-screens.md) page describes each section. The same steps run from the command line with the [WP-CLI commands](WP-CLI-commands.md).

## On each site that has the custom tables

1. **Back up the database.** On Local that is `wp db export`, or Local's own backup feature.
2. **1. Inventory & archive:** review the inventory, then download *all rows* (JSONL) and the *orphaned columns* (CSV) for each table. The inventory says, per table, whether the plugin is reading it; a red warning at the top of the page means it is not (see [Troubleshooting](Troubleshooting.md#a-red-warning-says-the-plugin-is-not-reading-a-table)).
3. **2. Snapshots:** take `before` in mode **effective**.
4. **3. Backfill:** run with **Dry run** checked, then open *By field* and *Changes* on the run and review each action ([Backfill results](Backfill-results.md) lists them):
   - `fill`: postmeta was empty (expected for most rows)
   - `overwrite`: postmeta held an old value from before the bypass
   - `clear`: the table is empty but postmeta held an outdated value
5. **3. Backfill:** uncheck **Dry run** and run it for real. **Mismatches must be 0.**
6. Run another **dry run**. It must show **0 / 0**. The only exception is fields flagged **invalid**: a corrupted ID in the custom table (for example `"10522]"`). Those are never written. Fix the post by hand with the plugin active (re-select the value and save), then run the backfill again.
7. **2. Snapshots:** take `after-meta` in mode **meta**.
8. **4. Compare:** `before` against `after-meta` must show **Identical**.

## Once for the whole network

9. **Network Admin → Plugins:** network-deactivate *ACF Custom Database Tables*.
10. On each site, take snapshot `after-off`, then compare `before` against `after-off`. It must show **Identical**.
11. Click through provider profiles, location pages, and the Providers and Locations blocks filtered by location and area of expertise. Rebuild the FacetWP index.
12. Practice going back, as [Rolling back](Rolling-back.md) describes: reactivate the plugin and roll back the real run, or restore the database backup.

## The second rehearsal

Restore the backup and repeat. The rehearsal is clean when steps 5 to 8 and step 10 all come out clean, and then production is ready.

**Note:** Long jobs run as a chain of short requests and can be resumed. If a step stops partway, see [Troubleshooting](Troubleshooting.md#a-job-stopped-partway-or-the-tab-was-closed).

## Going further

- [Migrating production](Migrating-production.md)
- [Rolling back](Rolling-back.md)
- [Backfill results](Backfill-results.md)
