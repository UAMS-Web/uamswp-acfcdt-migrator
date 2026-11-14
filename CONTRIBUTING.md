# Contributing to uamswp-acfcdt-migrator

<!-- uams-claude-skills:begin -->
## Branches and environments

Every change starts as an issue, is built on a branch linked to that issue, and reaches production through a pull request to `main`. This section covers that path and what each pull request's test plan has to cover.

| Branch | Role | Where its test plan runs |
| --- | --- | --- |
| `main` | Default branch, production | The local environment, before the pull request merges |

## From issue to merge

1. **File or pick up an issue.** The work is described there, with acceptance criteria.
2. **Create a branch from `main`, linked to the issue.** Use the issue's **Create a branch** link, or:

   ```sh
   gh issue develop <number> --base main --name <number>-<short-slug> --checkout
   ```

   The link shows the branch, and later its pull requests, on the issue.
3. **Open a pull request from the branch to `main`.** It carries the full description: what changed, why, and how it was verified. Its test plan is run on the local environment, and its boxes are checked before review.

## Test plans

Every pull request ends with a `## Test plan` checklist. Its first line names the environment it was run on, and every item is something a reviewer can check there:

- **Pull request to `main`:** "Run on the local environment." Items are things you checked yourself there: a page, a role, a save, a command. Check a box only for a check you actually ran.

The [pull request template](.github/pull_request_template.md) fills in the skeleton when a pull request is opened on GitHub.

## Changing code that has no tests

Before changing code that has no tests, add a test that captures what the code does now, whether or not that is what it should do, and see it pass. Commit that test on its own, then make the change. A test that now fails shows a behavior that changed: update the test if the change was intended, and fix the code if it was not.

The [`tests` skill](.claude/skills/tests/SKILL.md#pinning-legacy-behavior-before-a-change) has the detail: what to capture, how to show a test catches a change, and how to name it.

## Agents

Coding agents follow the same path. Their instructions are in [`AGENTS.md`](AGENTS.md) and the shared skills and rules under `.claude/`, which are synced from `UAMS-Web/uams-claude-skills`.
<!-- uams-claude-skills:end -->

## Running the Integration suite

The Unit suite (`composer test:unit`, Pest 5) needs no WordPress and no database. The Integration suite (`composer test:integration`, PHPUnit 9.6 over `wp-phpunit`) boots a real WordPress and loads the real plugins this migrator works against: ACF Pro, ACF Extended and ACF Custom Database Tables ([`docs/DECISIONS.md`](docs/DECISIONS.md), #45). `composer test` runs both, as two processes.

One-time setup:

```sh
composer install
composer setup:integration   # PHPUnit 9.6, wp-phpunit and the polyfills, in tests/Integration/vendor
mysql -u root -h 127.0.0.1 -e 'CREATE DATABASE uamswp_acfcdt_tests'
```

The test database is dropped and rebuilt on every run. Never point the suite at a database anything else uses: the bootstrap refuses to run when the name matches the surrounding install's `DB_NAME`.

| Variable | Default | What it sets |
| --- | --- | --- |
| `WP_TESTS_ABSPATH` | The install this checkout sits in (five levels above `tests/Integration`) | The WordPress core tree to boot. Point it at a core copy (`wp core download --path=<dir>`) when the checkout is not inside an install. |
| `WP_TESTS_PLUGINS_DIR` | `<WP_TESTS_ABSPATH>/wp-content/plugins` | The directory holding `advanced-custom-fields-pro`, `acf-extended` and `acf-custom-database-tables`. |
| `WP_TESTS_DB_NAME` | `uamswp_acfcdt_tests` | The test database. |
| `WP_TESTS_DB_USER` | `root` | The database user. |
| `WP_TESTS_DB_PASSWORD` | empty | The database password. |
| `WP_TESTS_DB_HOST` | `127.0.0.1` | The database host. |

The bootstrap checks the three plugins against `tests/Integration/plugin-stack.json` before it boots anything, and exits non-zero naming the plugin when a version or the fingerprint of its files differs. When the stack is meant to change, regenerate the manifest from the plugins directory and commit the result:

```sh
php tests/Integration/bin/write-plugin-manifest.php > tests/Integration/plugin-stack.json
```

The manifest holds versions and hashes only, never plugin source. Where CI obtains the three plugins is not decided yet, so the suite runs locally.
