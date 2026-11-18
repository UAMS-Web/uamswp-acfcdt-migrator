Written against v0.3.0.

Fields and columns in the provider and location data that the migration treats differently, and why.

## Orphaned columns

These custom-table columns have no ACF field. They are archived (the *orphaned columns* CSV, or `archive` on the command line) and not migrated:

- `physician_title`
- `physician_conditions`
- `physician_treatments`
- `physician_researcher_bio`
- `location_conditions`
- `location_treatments`

## Fields already in postmeta

`location_hours`, `location_holiday_hours`, `location_24_7` and `location_after_hours` are nested inside the `location_hours_group` group. The plugin never intercepted them, so they already live in postmeta and a backfill finds nothing to do for them.

## Integer columns

Integer columns (for example `physician_npi`) lost any leading zeros through the plugin's integer cast at save time. The migration keeps exactly what the site shows today, so those zeros are not restored.

## Corrupted IDs

An ID field holding something that is not an ID (for example `"10522]"`) is flagged `invalid` and never written. See [Backfill results](Backfill-results.md#flags-on-a-record).
