UAMSWP ACF Custom Database Tables Migrator copies every value that ACF Custom Database Tables serves from its custom tables into core postmeta, so the plugin can be retired from a multisite without any provider or location field going blank. It runs from wp-admin, with WP-CLI commands for a local install.

This wiki holds the long-form documentation: the rehearsal walkthrough, the production and recovery guides, the reference, and troubleshooting. The [README](https://github.com/UAMS-Web/uamswp-acfcdt-migrator#readme) keeps the overview, the requirements and installation.

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/uamswp-acfcdt-migrator#install): deploy and network-activate the plugin.
- [Rehearsing on Local](Rehearsing-on-Local.md): a full migration on a local copy, from backup to a clean compare.

## Guides

- [Migrating production](Migrating-production.md): the production run, with the freeze and the checks that gate it.
- [Rolling back](Rolling-back.md): going back after a backfill, with or without the plugin.
- [Cleaning up after retention](Cleaning-up-after-retention.md): what to remove once the retention period ends.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): one entry per symptom, with its cause and fix.

## Reference

- [Admin screens](Admin-screens.md): the network overview and the four sections of the per-site screen.
- [WP-CLI commands](WP-CLI-commands.md): every `acfcdt-migrate` subcommand and option.
- [Backfill results](Backfill-results.md): the change actions, the checks a run reports, and what each means.
- [Stored data](Stored-data.md): the `wp_acfcdt_migration` table and what each row kind holds.
- [Known data notes](Known-data-notes.md): fields and columns that behave differently, and why.

## Project

- [Project](Project.md): how the plugin was tested, where decisions are recorded, and how to report a problem.
