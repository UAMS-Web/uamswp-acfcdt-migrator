# UAMSWP ACF Custom Database Tables Migrator

Retires **ACF Custom Database Tables** (1.1.0-beta3) on the uamshealth.com multisite without losing data. It runs from **wp-admin**, so you don't need WP-CLI or shell access. WP-CLI commands are included too, for use on Local.

**Why we need it:** `UAMSWP-Find-a-Doc` turns off `store_acf_values_in_core_meta`. That means every mapped provider/location value lives **only** in `wp_*_uamswp_physicians` and `wp_*_uamswp_locations`. If you deactivate the plugin before moving the data, those fields go blank.

**How it works:** for each post and each top-level ACF field, the tool reads the value two ways:

- **through the plugin** — what the site shows today
- **from postmeta with the plugin's hooks switched off** — what the site will show once the plugin is gone

Where the two differ, it backs up the field's postmeta rows, writes the plugin's value into postmeta through ACF, and reads it back to confirm the write. It never writes to the custom tables. It's idempotent: a second run reports 0 changes.

## Install

1. Deploy `uamswp-acfcdt-migrator/` to `wp-content/plugins/` the same way you deploy other plugins.
2. **Network Admin → Plugins → Network Activate.** It does nothing on the front end. On first use it creates one table, `wp_acfcdt_migration`, which holds snapshots, run logs and backups.
3. **Network Admin → Settings → ACFCDT Migration** lists the sites that have custom tables, with links to each one.
4. The work happens per site in **Tools → ACFCDT Migration** on that site. Only super admins can see it.

### How it copes with host time limits

Long jobs run as a chain of short AJAX requests: 25 posts per request for snapshots, and a setting you choose for backfill (default 10). Progress is saved after every batch, and every batch is safe to repeat.

- **Brief failure** (timeout, 502/504): the page waits and retries automatically.
- **Tab closed or browser crashed:** the job shows as *running* with a **Resume** button. Resume continues from the last saved batch.
- **Still hitting timeouts:** lower *Posts per request*.

## Local rehearsal (do this at least twice)

On each site that has the custom tables:

1. **Back up the database.** On Local that's `wp db export`, or Local's own backup feature.
2. **1. Inventory & archive:** review the inventory, then download *all rows* (JSONL) and the *orphaned columns* (CSV) for each table.
3. **2. Snapshots:** take `before` in mode **effective**.
4. **3. Backfill:** run with **Dry run ✓**, then open *By field* / *Changes* on the run and review:
   - `fill` — postmeta was empty (expected for most rows)
   - `overwrite` — postmeta held an old value from before the bypass
   - `clear` — the table is empty but postmeta held an outdated value
5. **3. Backfill:** untick **Dry run** and run it for real. **Mismatches must be 0.**
6. Run another **dry run**. It must show **0 / 0**. The only exception is fields flagged **invalid**: a corrupted ID in the custom table (e.g. `"10522]"`). Those are never written. Fix the post by hand with the plugin active (re-select the value and save), then run the backfill again.
7. **2. Snapshots:** take `after-meta` in mode **meta**.
8. **4. Compare:** `before` vs `after-meta` must show **✔ Identical**.

Then, once for the whole network:

9. **Network Admin → Plugins:** network-deactivate *ACF Custom Database Tables*.
10. On each site, take snapshot `after-off`, then compare `before` vs `after-off`. It must show **✔ Identical**.
11. Click through provider profiles, location pages, and the Providers/Locations blocks filtered by location and area of expertise. Rebuild the FacetWP index.
12. Practise going back:
    - reactivate the plugin, then use **Roll back** on the real run (it restores the original postmeta exactly), **or**
    - restore the DB backup.

Rehearsal 2: restore the backup and repeat. You're ready for production when steps 5–8 and 10 all come out clean.

## Production

> **The table map cache decides what "what the site shows" means (Find-a-Doc #813 / #814).**
> ACF Custom Database Tables reads `assets/json/acf-tables/.cache/_table_map.php`, not the `table_*.json` files. The committed cache has said `physicians` / `locations` since 2021, so right after a deploy the plugin reads **no** custom table. The site serves postmeta until someone clicks *Create/Update Tables*.
>
> - The inventory now reports, per table, whether the plugin is actually reading it. If it isn't, there's a red warning at the top of the page.
> - In that state a dry run reports ~0 changes. That does **not** mean you're done: the table data just isn't being looked at.
> - Real runs are refused in that state unless you tick the override.
> - **Before migrating on production:** click *Create/Update Tables* (or ship #813) so the plugin reads the tables. Then take `before` and do the dry run.
> - Review every **overwrite** row in the dry run's Changes file (each row includes the post's `modified` date). An overwrite is a field where postmeta disagrees with the table. Those are most likely edits made during a stale-cache window, and they **may be newer** than the table value. The backfill keeps the table value (what the site shows) and backs up the postmeta value. Fix any you want to keep by hand before the real run.

1. **Freeze edits** to providers and locations for the window, including any importer that writes them. A save made while the plugin is still active goes to the custom table only.
2. **Back up the database** in the host's control panel, and download the archives (step 2 above).
3. Run steps 3–8 on each site with tables. Read the production dry run carefully before the real run, because production can differ from Local (e.g. the committed `_table_map.php` cache differs from Local's).
4. Deploy the `UAMSWP-Find-a-Doc` change to `uamswp_custom_table_query()`. It's safe at any time: it keeps using the custom table while the plugin is active.
5. Run one last **dry run** per site. It should show 0. If it doesn't, run the real backfill again to catch late edits.
6. Network-deactivate the plugin, then do step 10 (snapshot `after-off`, compare) and step 11 (spot-checks, FacetWP reindex).
7. Lift the freeze.

**If something's wrong:** reactivate ACF Custom Database Tables. The tables were never modified, so the site reads exactly what it did before. Optionally roll back the run to restore postmeta too.

## WP-CLI (optional, Local only)

These use the same engine and the same `wp_acfcdt_migration` table, so CLI and admin results can be mixed and compared.

```bash
alias acfm='wp --require=wp-content/plugins/uamswp-acfcdt-migrator/uamswp-acfcdt-migrator.php acfcdt-migrate'
acfm inventory --network
acfm snapshot before --network
acfm backfill --network --dry-run
acfm backfill --network --stamp=r1
acfm snapshot after-meta --mode=meta --network
acfm compare before after-meta --network
acfm status --network
acfm rollback r1 --network
```

`acfm archive --network` writes JSONL/CSV files one level above the web root (override with `--dir`).

## Cleanup (after a retention period, e.g. 90 days)

- Remove the `store_acf_values_in_core_meta` filter and the `ACFCDT_JSON_DIR` define from `UAMSWP-Find-a-Doc/includes/class.fad-acf-functions.php`.
- Remove `assets/json/acf-tables/` and the `acfcdt_*` keys from `group_uamswp_physicians.json`.
- Simplify `uamswp_custom_table_query()` to the postmeta branch only.
- Drop the `wp_*_uamswp_physicians` / `wp_*_uamswp_locations` tables (keep the archive + DB backup).
- Delete the migrator plugin, then drop `wp_acfcdt_migration`. Download any backups you want to keep first.

## Known data notes

- **Orphaned columns**, archived and not migrated: `physician_title`, `physician_conditions`, `physician_treatments`, `physician_researcher_bio`, `location_conditions`, `location_treatments`.
- `location_hours`, `location_holiday_hours`, `location_24_7` and `location_after_hours` are nested inside the `location_hours_group` group. The plugin never intercepted them, so they already live in postmeta.
- Integer columns (e.g. `physician_npi`) lost any leading zeros through the plugin's integer cast at save time. The migration keeps exactly what the site shows today.

## Tested

Sandbox (separate DB, not your Local site): WP 7.0.6 subdirectory multisite, ACF Pro 6.8.9, ACF Custom Database Tables 1.1.0-beta3, and the real FAD field groups and table JSON.

**WP-CLI flow and the wp-admin flow** (clicked through in headless Chromium against a real web server):

- snapshot → dry run → real backfill: 0 write mismatches
- second dry run: 0 changes
- `before` vs `after-meta`: identical
- plugin network-deactivated, then `before` vs `after-off`: identical

**Recovery and downloads:**

- interrupted backfill → **Resume** finished from the saved batch
- **Roll back** restored the outdated pre-migration postmeta exactly
- all downloads (changes, backups, snapshot, table archive, orphan CSV) worked
