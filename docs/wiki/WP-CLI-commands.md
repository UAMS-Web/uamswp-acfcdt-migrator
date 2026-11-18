Written against v0.3.0.

The `acfcdt-migrate` command runs the same engine as the [Admin screens](Admin-screens.md) and stores its results in the same `wp_acfcdt_migration` table, so command-line and wp-admin results can be mixed and compared. It is meant for a local install; production runs from wp-admin.

Every data subcommand accepts `--network`, which runs it once per site that has custom tables, each in its own WP-CLI process (the plugin caches per-request state that is not site-aware).

## Example

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

The usual order is: `inventory`, `archive`, `snapshot before`, `backfill --dry-run`, `backfill`, `snapshot after-meta --mode=meta`, `compare before after-meta`, deactivate the plugin, `snapshot after-off`, `compare before after-off`.

## inventory

Reports what the custom tables hold and how they map to ACF fields. Read-only.

| Option | Effect |
| --- | --- |
| `--network` | Every site that has custom tables. |
| `--json-dir=<dir>` | Where the `table_*.json` definitions are read from. Default: `ACFCDT_JSON_DIR`. |
| `--format=<format>` | `table` or `json`. Default: `table`. |

## archive

Exports every custom table row (all columns, raw) to JSONL, plus a CSV of the orphaned columns. Read-only. Keep it in addition to a `wp db export`: it is the readable per-site copy that remains after the tables are dropped. Files go to `acfcdt-migration/archive/<stamp>/` one level above the WordPress root, or under `ACFCDT_MIGRATION_DIR` when it is defined, or under `--dir`. **Warning:** if that parent directory is not writable they fall back to `wp-content/acfcdt-migration`, which the web server can serve.

| Option | Effect |
| --- | --- |
| `--network` | Every site that has custom tables. |
| `--stamp=<stamp>` | Folder name under `archive/`. Default: the current UTC timestamp. |
| `--dir=<dir>` | Base output directory. |
| `--json-dir=<dir>` | Table JSON directory. |

## snapshot

Records every top-level ACF value for every provider and location post.

| Option | Effect |
| --- | --- |
| `<label>` | Snapshot name, for example `before`, `after-meta`, `after-off`. |
| `--mode=<mode>` | `effective` (through the plugin, what the site shows now) or `meta` (postmeta with the plugin bypassed). Default: `effective`. |
| `--network` | Every site that has custom tables. |
| `--ids=<ids>` | Comma-separated post IDs, for testing. |
| `--force` | Overwrite an existing snapshot of the same name. |
| `--json-dir=<dir>` | Table JSON directory. |

## compare

Compares two snapshots field by field, per site. Exits `1` if anything differs.

| Option | Effect |
| --- | --- |
| `<label-a>` | Baseline snapshot, usually `before`. |
| `<label-b>` | Snapshot to check. |
| `--network` | Every site that has custom tables. |
| `--ignore-fields=<names>` | Comma-separated field names to ignore. |
| `--limit=<n>` | How many differences to print. Default: 25. |

## backfill

Copies every value the plugin is serving into postmeta, where it differs. A second run changes nothing. Exits `1` when any write mismatch is found. [Backfill results](Backfill-results.md) explains what it reports.

| Option | Effect |
| --- | --- |
| `--dry-run` | Report what would change; write nothing. |
| `--no-clear` | Do not clear stale postmeta where the plugin serves an empty value. |
| `--network` | Every site that has custom tables. |
| `--ids=<ids>` | Comma-separated post IDs, for testing. |
| `--stamp=<stamp>` | Run name. Default: the UTC time as `Ymd-His`, with `-dry` added for a dry run. A network run gives every site the same stamp, so `rollback <stamp> --network` works. |
| `--allow-stale-cache` | Do a real run even though the plugin's table map cache means it is not reading a table. |
| `--json-dir=<dir>` | Table JSON directory. |

## rollback

Restores postmeta to exactly what it was before a backfill run. Does not touch the custom tables.

| Option | Effect |
| --- | --- |
| `<stamp>` | The run name. |
| `--network` | Every site that has this run. |
| `--force` | Roll back again even if already rolled back. |

## status

Lists the snapshots and backfill runs stored for a site: type, name, detail (mode, or fields, mismatches and rollback status), status and time.

| Option | Effect |
| --- | --- |
| `--network` | Every site that has custom tables. |
