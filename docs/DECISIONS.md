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
