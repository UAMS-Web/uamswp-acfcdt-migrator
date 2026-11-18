Written against v0.3.0.

The production migration repeats the rehearsal on the live network, inside an edit freeze, with one extra check first: that ACF Custom Database Tables is actually reading its tables. Rehearse at least twice on Local first ([Rehearsing on Local](Rehearsing-on-Local.md)).

## Before the window: make the plugin read its tables

ACF Custom Database Tables decides at runtime which tables it reads from its table map cache, `assets/json/acf-tables/.cache/_table_map.php`, not from the `table_*.json` files. The committed cache has named `physicians` and `locations` since 2021, so right after a deploy the plugin reads **no** custom table, and the site serves postmeta until someone clicks *Create/Update Tables* ([UAMSWP-Find-a-Doc#813](https://github.com/UAMS-Web/UAMSWP-Find-a-Doc/issues/813), [UAMSWP-Find-a-Doc#814](https://github.com/UAMS-Web/UAMSWP-Find-a-Doc/issues/814)).

- The inventory reports, per table, whether the plugin is reading it, and shows a red warning at the top of the page when it is not.
- In that state a dry run reports about 0 changes. That does **not** mean the work is done: the table data is not being looked at.
- A real run is refused in that state unless the override is checked (`--allow-stale-cache` on the command line).
- So click *Create/Update Tables* (or ship UAMSWP-Find-a-Doc#813) first, then take `before` and do the dry run.

Review every **overwrite** row in the dry run's Changes file; each row carries the post's `modified` date. An overwrite is a field where postmeta disagrees with the table, most likely an edit made while the cache was stale, and it **may be newer** than the table value. The backfill keeps the table value (what the site shows) and backs up the postmeta value. Fix by hand, before the real run, any value that should be kept.

## The window

1. **Freeze edits** to providers and locations, including any importer that writes them. A save made while the plugin is still active goes to the custom table only.
2. **Back up the database** in the host's control panel, and download the archives (step 2 of [Rehearsing on Local](Rehearsing-on-Local.md)).
3. Run steps 3 to 8 of the rehearsal on each site with tables. Read the production dry run carefully before the real run, because production can differ from Local (for example, the committed `_table_map.php` cache differs from Local's).
4. Deploy the `UAMSWP-Find-a-Doc` change to `uamswp_custom_table_query()`. It is safe at any time: it keeps using the custom table while the plugin is active.
5. Run one last **dry run** per site. It should show 0. If it does not, run the real backfill again to catch late edits.
6. Network-deactivate the plugin, then take snapshot `after-off` on each site and compare it with `before`, and click through the pages and rebuild the FacetWP index (steps 10 and 11 of the rehearsal).
7. Lift the freeze.

**Warning:** If something is wrong, reactivate ACF Custom Database Tables. The tables were never modified, so the site reads exactly what it did before, except that any edit saved after the plugin was deactivated is in postmeta only and is hidden again while the plugin is active. See [Rolling back](Rolling-back.md) to restore postmeta too.

## Reference

- [Admin screens](Admin-screens.md)
- [WP-CLI commands](WP-CLI-commands.md): `backfill --allow-stale-cache`
- [Backfill results](Backfill-results.md)
