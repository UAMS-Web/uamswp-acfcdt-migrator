Written against v0.3.0.

The plugin adds two screens. Both need `manage_network_options` on a multisite (a super admin), or `manage_options` on a single site. Nothing is added to the front end.

## Network Admin → Settings → ACFCDT Migration

The network overview. It lists only the sites that have custom tables, with, for each site:

| Column | Shows |
| --- | --- |
| Site | The site ID and its address |
| Tables | The custom tables defined for that site |
| Snapshots | Every snapshot name, with *(incomplete)* beside one that has not finished |
| Latest backfill | The newest real run: its name, status, field count and any mismatches |

**Open** goes to that site's own screen. Each site is migrated from its own screen.

## Tools → ACFCDT Migration (per site)

Four sections, worked in order. A progress bar and a log sit under them while a job runs.

### 1. Inventory & archive

The section is collapsed; click its heading to open it. One block per table definition: the post type, whether the plugin is reading it, and every column with its ACF name, type, number of non-empty rows, and a note. A table that does not exist on the site says so and has nothing else. The downloads:

- **Download all rows (JSONL)**: every row, every column, raw.
- **Download orphaned columns (CSV)**, shown only when the table has them: the columns with no ACF field (see [Known data notes](Known-data-notes.md)).

A red warning at the top of the page means the plugin is not reading a table ([Troubleshooting](Troubleshooting.md#a-red-warning-says-the-plugin-is-not-reading-a-table)).

### 2. Snapshots

Records every top-level ACF value for every provider and location post.

| Setting | Effect |
| --- | --- |
| Name | Letters, numbers, dot, dash and underscore, up to 60 characters. The screen suggests `before`, then `after-meta`, then `after-off`. |
| Mode | `effective` reads through the plugin (what the site shows now); `meta` reads postmeta with the plugin bypassed (what the site will show once it is gone). |
| overwrite if it exists | Replaces a snapshot of the same name. |

Each stored snapshot has **Download**, **Delete**, and **Resume** while it is incomplete. A snapshot runs 25 posts per request.

### 3. Backfill postmeta

For each field where the plugin's value differs from postmeta: back up the postmeta rows, write the plugin's value into postmeta, and read it back to confirm. The custom tables are never modified, and a second run changes nothing.

| Setting | Default | Effect |
| --- | --- | --- |
| Dry run (write nothing) | checked | Records what would change and writes nothing. |
| Don't clear stale postmeta where the plugin serves an empty value | unchecked | Skips the `clear` action. |
| Real run anyway | unchecked | Shown only while the plugin is not reading a table; allows a real run in that state. |
| Posts per request | 10 | 1 to 100. Lower it when requests time out. |

Each run lists its type, status, posts and fields changed, the `fill` / `overwrite` / `clear` counts, mismatches and rollback state, with **Changes** (download), **Backups** (download, real runs), **Roll back** or **Resume rollback**, **Resume** while it is incomplete, **Delete**, and a *By field* breakdown once complete. [Backfill results](Backfill-results.md) explains the counts.

**Warning:** Deleting a real run that has not been rolled back asks for confirmation, because it holds the only per-field backups of the old postmeta. Download the backups first.

### 4. Compare snapshots

Compares two complete snapshots field by field, with an optional comma-separated list of fields to ignore. The result is **Identical**, or a list of differences (the first 200 on screen) with a download of the full report.
