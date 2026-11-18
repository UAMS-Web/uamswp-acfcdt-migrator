Written against v0.3.0.

What a backfill run records for each field it looks at, and how to read the counts on a run. A run looks at every top-level ACF field of every provider and location post, reads the value twice, and compares the two after normalizing them:

- **effective:** through ACF Custom Database Tables, what the site shows today.
- **postmeta:** with the plugin's hooks switched off, what the site will show once the plugin is gone.

A field whose two values match is left alone and recorded nowhere. Every other field gets a change record, which the run's **Changes** download lists with the post ID, post type, title, `modified` date, field name, key and type, the action (`category`), and both values (`effective`, `meta_before`), plus the flags below and `meta_after` when they apply.

## Actions

| Action | Means | A real run does |
| --- | --- | --- |
| `fill` | Postmeta is empty and the plugin serves a value. Expected for most fields. | Writes the plugin's value into postmeta. |
| `overwrite` | Postmeta and the plugin both hold a value, and they differ: usually an old value from before the bypass, possibly a newer edit (see [Migrating production](Migrating-production.md)). | Writes the plugin's value. The postmeta value is kept in the run's backups. |
| `clear` | The plugin serves an empty value and postmeta still holds one. | Writes the empty value, unless *Don't clear stale postmeta* (`--no-clear`) is set, in which case the field is skipped. |

## Flags on a record

| Flag | Means |
| --- | --- |
| `invalid` | An ID field (taxonomy, relationship, post object, gallery, user, image, file) holds something that is not an ID in the custom table, for example `"10522]"`. It is never written. Fix the post by hand with the plugin active (re-select the value and save), then run the backfill again. |
| `skipped` | A real run left the field alone: it was `invalid`, or a `clear` under `--no-clear`. |
| `write_mismatch` | After the write, postmeta read back differently from the plugin's value. The record carries the value read back. **A run with any mismatch is not clean.** |

## Before each write

A real run backs up exactly the postmeta rows the field owns, and every other row the write touches (such as the reference row ACF Extended's bidirectional relationships rewrite on the related post), so a [rollback](Rolling-back.md) restores them all. If a batch is repeated after its write already happened, the first backup is the one kept. A field is always written, never deleted, because a missing postmeta row would make ACF fall back to the field's default value. Values are written with their backslashes preserved (`wp_slash()` before `acf_update_value()`), so content holding backslashes survives the write.

## What clean looks like

- A real run: **Mismatches 0**.
- The dry run after it: **0 / 0** changes, apart from `invalid` fields.
- `before` compared with `after-meta`, and later with `after-off`: **Identical**.

## How values are compared

- ID fields ignore an ID of `0`, empty entries, and gaps in the array keys.

## Reference

- [Admin screens](Admin-screens.md#3-backfill-postmeta)
- [WP-CLI commands](WP-CLI-commands.md#backfill)
- [Stored data](Stored-data.md)
