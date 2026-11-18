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

## Documentation

The [wiki](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki) holds the rest:

- [Rehearsing on Local](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Rehearsing-on-Local): the full migration on a local copy, at least twice before production.
- [Migrating production](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Migrating-production), [Rolling back](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Rolling-back) and [Cleaning up after retention](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Cleaning-up-after-retention).
- [WP-CLI commands](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/WP-CLI-commands), [Admin screens](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Admin-screens), [Backfill results](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Backfill-results), [Stored data](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Stored-data) and [Known data notes](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Known-data-notes).
- [Troubleshooting](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/wiki/Troubleshooting), including host time limits and resuming a job.

The pages are kept in [`docs/wiki/`](docs/wiki/) and published by the *Publish wiki* workflow; change them through a pull request.

## Running the tests

The unit suite uses Pest 5 with Brain Monkey, so it needs no database and no WordPress checkout. The plugin needs WordPress 6.8 or later (`Requires at least` in the plugin header; its queries bind table names with the `%i` placeholder) and PHP 8.2 or later (`Requires PHP` in the plugin header, `require.php` in `composer.json`). The tests run on PHP 8.4 (`config.platform.php` in `composer.json`), and every shipped file must parse on PHP 8.2 (`php -l` on that version).

```bash
composer install
composer test
```

Before a pull request, run the suite locally; remote CI runs the checks on every pull request:

```bash
vendor/bin/pest
```

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Todd McKee

## Code quality

The code is checked with Rector, PHPStan (with the WordPress, WP-CLI and ACF Pro stubs) and Pint with the `laravel` preset, all development dependencies. PHPStan runs at level 0, the highest level the code passes today, and rises one level per change.

```bash
composer test:pint
composer test:refactor
composer stan
```

`composer pint` and `composer refactor` apply what the first two report. Tell `git blame` to skip the formatting commit, once per clone:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```
