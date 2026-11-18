Written against v0.3.0.

UAMSWP ACF Custom Database Tables Migrator is developed by UAMS Web Services in [UAMS-Web/uamswp-acfcdt-migrator](https://github.com/UAMS-Web/uamswp-acfcdt-migrator). The [README](https://github.com/UAMS-Web/uamswp-acfcdt-migrator#readme) covers running the tests, the code-quality tools and the credits.

## Report a problem

Open an issue in the [repository's tracker](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues), with the site, the step, and the exact message or the run's Changes download.

## Releases

[Releases](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/releases) list what each version changed, and the [changelog](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/blob/main/CHANGELOG.md) keeps the same history in the repository.

## Decisions

Closed design decisions, with the alternatives they rejected, are in the [decision log](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/blob/main/docs/DECISIONS.md).

## How the plugin was tested

In a sandbox with its own database (not a working local site): WordPress 7.0.6 subdirectory multisite, ACF Pro 6.8.9, ACF Custom Database Tables 1.1.0-beta3, and the real Find-a-Doc field groups and table JSON.

The WP-CLI flow and the wp-admin flow (clicked through in headless Chromium against a real web server):

- snapshot, dry run, real backfill: 0 write mismatches
- second dry run: 0 changes
- `before` against `after-meta`: identical
- plugin network-deactivated, then `before` against `after-off`: identical

Recovery and downloads:

- an interrupted backfill finished from the saved batch with **Resume**
- **Roll back** restored the outdated pre-migration postmeta exactly
- every download (changes, backups, snapshot, table archive, orphan CSV) worked

The repository's own Pest unit suite and the integration suite over the real plugin stack run on every pull request from a branch of this repository; a pull request from a fork receives no secrets and runs the unit suite only.

## Editing this wiki

These pages live in [`docs/wiki/`](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/tree/main/docs/wiki) and are published by the *Publish wiki* workflow on each release and by hand. Change them through a pull request; an edit made in the wiki is overwritten by the next publish.
