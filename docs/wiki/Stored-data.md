Written against v0.3.0.

All migration state lives in one network-wide table, `wp_acfcdt_migration`, created on first use. Keeping it in the database means the migration works on a host with no shell and no writable private directory, survives request timeouts, and makes every batch safe to repeat. The WP-CLI commands and the wp-admin screens read and write the same table.

## Columns

| Column | Holds |
| --- | --- |
| `id` | Row ID. |
| `blog_id` | The site the row belongs to. |
| `kind` | What the row is (see below). |
| `ref` | The snapshot name or run stamp. |
| `post_id` | The post, or `0` for a header row. |
| `field` | The ACF field name, or empty. |
| `data` | The row's content. |
| `created` | When it was written. |

At most one row exists per `blog_id`, `kind`, `ref`, `post_id` and `field`.

## Row kinds

| `kind` | `ref` | `post_id` | `field` | Holds |
| --- | --- | --- | --- | --- |
| `snapinfo` | snapshot name | 0 | empty | Snapshot header: mode, time, totals |
| `snapshot` | snapshot name | post | empty | Normalized field values for one post |
| `run` | run stamp | 0 | empty | Backfill run header: dry run, no-clear, status |
| `change` | run stamp | post | field | What differed and was written (see [Backfill results](Backfill-results.md)) |
| `backup` | run stamp | post | field | The postmeta rows before the write; the first write wins |
| `rollback` | run stamp | 0 | empty | Rollback header |

A real run's `backup` rows are the only per-field copy of the old postmeta, which is why deleting such a run asks for confirmation. A database backup restores the table along with everything else.

## Removing it

Drop the table only after the retention period, and after downloading any backups to keep ([Cleaning up after retention](Cleaning-up-after-retention.md)).
