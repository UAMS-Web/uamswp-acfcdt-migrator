# Decision log

The canonical record of why the project is the way it is: the reasoning and the rejected alternatives behind decisions that have closed. It guards against silently reopening a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question or picking an approach, check here first. The path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record, or make the case to revisit it.

**When a decision closes, record the verdict in both places.** Capture the what, the why and the rejected alternatives here (the cross-ticket index) and in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two consistent. Put the full reasoning in the relevant `docs/` companion when the decision touches a documented area. The issue tracker stays the system of record for a single ticket's status.

## How to add an entry

Entries run oldest first, one per closed decision, so a new entry is appended to the end of the file. Keep it terse; the companion or the issue carries the depth.

Every entry heading is `## YYYY-MM-DD`, a space, and a short title. Leave exactly one blank line above each heading. The check (`decision-log` skill) fails on a heading dated earlier than the one before it, and on a heading that sits directly under the previous line.

```markdown
## YYYY-MM-DD <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the docs/ companion or the GitHub issue holding the full reasoning>
```

---

## 2026-11-14 Run the integration suite against the real plugin stack (#45)

**Decision.** The Integration suite (PHPUnit 9.6 over `wp-phpunit`, its own Composer setup in `tests/Integration/`) loads the real plugins this migrator works against: ACF Pro, ACF Extended and ACF Custom Database Tables. It loads them from a plugins directory named by `WP_TESTS_PLUGINS_DIR`, which defaults to the surrounding install's `wp-content/plugins`, and checks them against a manifest of versions and sha256 hashes, failing on a mismatch. The suite is built and run locally first. The CI job is a separate ticket, blocked on deciding where CI obtains the three plugins.

**Why.** The plugin exists to migrate values across exactly this stack. The rollback defect fixed in #57 lived in the interplay of ACF's `acf_update_value`, ACF Extended's bidirectional rewrite and the postmeta rows, and no stand-in for ACF reproduces that. Taking the directory as input keeps one loader for the local install and for CI, and the manifest means a matching version label cannot hide different files.

**Rejected.** ACF free from wordpress.org in CI with the real stack only locally: it runs without a secret, but tests a different ACF than production runs and leaves CI and local runs without parity. No ACF, with a fixture standing in: cheapest, but nothing in the suite would exercise ACF, so it cannot catch a defect like #57. ACF Pro with a license secret in the same slice: deferred, because the secret and a source for the Custom Database Tables beta are not decided.

**Companion.** [#45](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/45).

## 2026-11-14 Extract the plugin file's load-time branching and exclude the thin shim from coverage (#55)

**Decision.** The branching in `uamswp-acfcdt-migrator.php` (the `WP_CLI` command registration, `Admin::init()` under `is_admin()` and the require sequence) moves into one class under `includes/`, behavior unchanged and `wp --require` loading preserved. The plugin file is left as the double-load guard, the two `define()` calls, one require and one call into that class, and that thin file is excluded from the coverage source with the reason recorded in `phpunit.xml`. The class is in the measured population and gets unit tests. The build is #67 and the exclusion is #68.

**Why.** A Unit test reaches the file's body once per process, because the constants make a second load return at the guard, and per-test process isolation fails on this build (`#[RunInSeparateProcess]` dies in the child on a missing PHPUnit class). With one fresh load and one guard load, a measured Unit run produced 31 mutants, 26 tested, 1 untested and 4 uncovered (83.87%), so the file cannot reach full coverage as it stands. In a class under `includes/`, every branch is reachable in one process through the namespace shims the suite already uses.

**Rejected.** Exclude the whole file as it stands: leaves four branches of real behavior unmeasured and unpinned. Cover it in the Integration suite (#45, built in #62): measures it only where the real plugin stack runs, and the Unit population would still list the file at 0.0%. Include it in the Unit suite with Brain Monkey expectations: measured at 83.87%, and the remainder cannot be reached without process isolation.

**Companion.** [#55](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/55).


## 2026-11-14 Where CI obtains the three integration-suite plugins (#76)

**Decision.** ACF Pro comes from the vendor's authenticated download, with the license key held as a repository secret and the download made under a development-format site URL so the CI job does not use a production activation. ACF Custom Database Tables 1.1.0-beta3 comes from a private store fetched with a read token. ACF Extended is the free 0.9.2.6 the manifest pins, downloaded from `downloads.wordpress.org` with no key (decided 2026-11-15 after a search found no Pro-only feature in the migrator's code, tests or plugin list: the bidirectional relationship setting and the `ACFE` class both ship in the free edition). Every file is checked against the manifest's sha256 hashes, and none is uploaded as a workflow artifact (this repository is public). A fork pull request skips the `integration` job with a notice that says why, and `CI` still requires it on trusted runs. Creating or storing any secret waits on the operator.

**Why.** The Integration suite needs the real plugin stack, and none of it is on Packagist. The vendors' license terms were read before building: ACF documents the key-authenticated download and classifies activations by URL, and the Hookturn beta is not publicly distributed and its terms are silent on private copies and CI use.

**Rejected.** ACF Extended Pro with a key secret: nothing the migrator uses is Pro-only, and it would add a secret for no coverage. A private store for all three plugins: ACF Pro's terms are silent on keeping copies and its grant requires destroying copies on termination. No CI integration job: it leaves CI without parity with local runs.

**Companion.** [#76](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/76).


## 2026-11-15 Table names reach `wpdb::prepare()` as `%i` placeholders, and the plugin requires WordPress 6.8 (#31)

**Decision.** Every `wpdb::prepare()` template binds its table name through a `%i` identifier placeholder instead of interpolating it, and the plugin header declares `Requires at least: 6.8`, the floor the stubs already assume (`%i` needs 6.2 or later, so 6.8 covers it). The two fragments that stay interpolated are built only from string literals (`Site::meta_where()` and the `$verb`, `$op`, `$order` and `$in` pieces), and `Store::count()` types its `$where_sql` argument `literal-string`, so PHPStan accepts every template at level 10 without a suppression.

**Why.** PHPStan level 10 types the `wpdb::prepare()` query as a literal string, and a table name read from `$wpdb` or `Store::table()` is not one. An identity helper that asserted `literal-string` would have satisfied the check without proving anything; `%i` lets the check hold on the real code.

**Rejected.** An identity helper declaring `@return literal-string` around the 18 templates: it passes the check by assertion and weakens it for every later query. A WordPress floor below 6.2: `%i` does not exist before it. A floor of 6.2: the operator set the floor at 6.8.

**Companion.** [#31](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/31).

## 2026-11-15 Where the Custom Database Tables beta is kept, and how plugin files are fingerprinted (#63)

**Decision.** The ACF Custom Database Tables 1.1.0-beta3 files are kept in a private repository, `UAMS-Web/uamswp-acf-custom-database-tables`, with the plugin's files at the repository root and a `.gitattributes` that leaves everything but the plugin out of an archive. The `integration` job clones that repository over SSH with a read-only deploy key (the `ACFCDT_DEPLOY_KEY` Actions secret), pins GitHub's published SSH host key, and archives the clone into the plugins directory with `git archive`, so the `.gitattributes` applies. ACF Pro is downloaded through ACF's Composer repository with the `ACF_PRO_LICENSE_KEY` secret and a development-format site URL (`http://localhost`). ACF Extended is the free 0.9.2.6 from `downloads.wordpress.org`. The plugin fingerprint reads every CRLF as LF and every backslash in a path as a slash, and the ACF Pro pin in `tests/Integration/plugin-stack.json` was recomputed under that rule. A fork pull request gets no secrets, so the job reports success with a notice and does not fetch the licensed plugins; `CI` needs the job on every run.

**Why.** #76 decided "a private store fetched with a read token", but no store existed: the beta is not publicly distributed and the only copy is the one on the production site. A private repository holding that one plugin's files is the smallest place a trusted run can read it from. The CRLF rule is there because the published ACF Extended zip ships CRLF in 199 of its 218 files where the copy used to write the pin carries LF, so a byte-exact fingerprint failed the same code on every download. The separator rule is there because a Windows machine fingerprinted the same 154 CDT files to a different value from macOS (it hashed `src\file.php` where macOS hashes `src/file.php`); with separators normalized the files match the pin.

**Rejected.** A personal fine-grained token: it belongs to one person's account, expires on a date, and stops working if that person's organization access changes; a deploy key belongs to the repository, is read-only and does not expire. A GitHub App: sturdier for several private repositories, but it needs an organization owner and more setup than one read-only file warrants. FTP from the production site in CI: it puts production credentials into a public repository's CI. An encoded zip in a secret: likely over the 48 KB secret limit. A zip in the private repository: it needs a zip built and unpacked, and shows no history. Leaving the plugin out of CI: loses parity with local runs, rejected in #76. Normalizing line endings after download instead of in the fingerprint: it rewrites files before the check reads them.

**Residual.** The beta's terms are silent on private copies and on CI use. The key-authenticated ACF Pro download and the file layout of ACF's Composer package are checked only by the first run with real secrets. The private repository is retired when the author publishes a stable download of the same files: point the job there and keep the fingerprint check.

**Companion.** [#63](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/63), [#76](https://github.com/UAMS-Web/uamswp-acfcdt-migrator/issues/76).
