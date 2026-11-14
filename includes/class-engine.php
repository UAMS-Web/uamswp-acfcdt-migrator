<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

/**
 * The migration logic, shared by the wp-admin screen and WP-CLI. Everything works on the CURRENT blog
 * in small, cursor-based batches so each web request stays short and any batch can be retried safely.
 */
class Engine
{
    const SNAPSHOT_MODES = ['effective', 'meta'];

    /** @var Site */
    public $site;

    public function __construct(?Site $site = null)
    {
        $this->site = $site ?: new Site;
        Store::install();
    }

    public static function acf_ready(): bool
    {
        return function_exists('acf_get_value') && function_exists('acf_get_field_groups');
    }

    /**
     * @param  int|string  $ref
     */
    public static function clean_ref($ref): string
    {
        $ref = (string) $ref;
        // An empty name fails the pattern as well, so the comparison only makes the intent explicit.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if ($ref === '' || ! preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $ref)) {
            throw new \InvalidArgumentException("Names may only contain letters, numbers, dot, dash and underscore (max 60): '$ref'");
        }

        return $ref;
    }

    /* ================================================================== posts */

    /**
     * @param  array<int, int|string>|null  $ids  Restrict to these post IDs.
     */
    public function total_posts($ids = null): int
    {
        return count($this->site->posts($this->site->post_types(), $ids));
    }

    /**
     * How many posts are at or below a cursor (for progress after a resume).
     *
     * @param  int|string  $cursor
     */
    public function posts_upto($cursor): int
    {
        global $wpdb;
        $types = $this->site->post_types();
        if (! $types || ! $cursor) {
            return 0;
        }

        // The first index array_fill() numbers from is discarded by implode().
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $in = implode(',', array_fill(0, count($types), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','inherit') AND ID <= %d",
            array_merge($types, [(int) $cursor])
        ));
    }

    /**
     * Posts with ID > $cursor, ascending.
     *
     * @param  int|string  $cursor
     * @param  int|string  $limit
     * @param  array<int, int|string>|null  $ids
     * @return array<int, \stdClass>|null
     */
    private function next_posts($cursor, $limit, $ids = null)
    {
        global $wpdb;
        $types = $this->site->post_types();
        if (! $types) {
            return [];
        }

        // The first index array_fill() numbers from is discarded by implode().
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $in = implode(',', array_fill(0, count($types), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID, post_type, post_status, post_title, post_modified FROM {$wpdb->posts}
			 WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','inherit') AND ID > %d",
            array_merge($types, [(int) $cursor])
        );
        if ($ids) {
            $sql .= ' AND ID IN ('.implode(',', array_map(absint(...), $ids)).')';
        }

        return $wpdb->get_results($sql.' ORDER BY ID ASC LIMIT '.max(1, (int) $limit));
    }

    /* ================================================================== inventory */

    /**
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        global $wpdb;
        $guard = new HookGuard;
        $out = [
            'blog_id' => $this->site->blog_id,
            'url' => $this->site->url,
            'json_dir' => $this->site->json_dir(),
            'plugin_loaded' => HookGuard::plugin_loaded(),
            'intercepts' => $guard->callback_count(),
            'hooks' => $guard->hook_names(),
            'core_meta' => (bool) apply_filters('acfcdt/settings/store_acf_values_in_core_meta', true),
            'cache' => $this->site->cache_status(),
            'cache_problems' => $this->site->cache_problems(),
            'tables' => [],
        ];

        foreach ($this->site->table_defs() as $def) {
            $table = $this->site->prefixed($def['name']);
            $t = ['table' => $table, 'name' => $def['name'], 'post_type' => $def['post_type'], 'def_file' => basename($def['file']), 'exists' => $this->site->table_exists($def['name'])];
            if (! $t['exists']) {
                $out['tables'][] = $t;

                continue;
            }

            $t['rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            $t['missing_post'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` t LEFT JOIN {$wpdb->posts} p ON p.ID = t.post_id WHERE p.ID IS NULL");
            $t['wrong_type'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` t JOIN {$wpdb->posts} p ON p.ID = t.post_id WHERE p.post_type <> %s",
                $def['post_type']
            ));
            $t['no_row'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN `$table` t ON t.post_id = p.ID
				 WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft','inherit') AND t.post_id IS NULL",
                $def['post_type']
            ));

            $db_cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
            $def_cols = array_column($def['columns'], 'name');
            $t['extra_cols'] = array_values(array_diff($db_cols, $def_cols));
            $t['missing_cols'] = array_values(array_diff($def_cols, $db_cols));
            $t['columns'] = [];

            foreach ($def['columns'] as $col) {
                if (! $col['identifier'] || ! in_array($col['name'], $db_cols, true)) {
                    continue;
                }

                $c = esc_sql($col['name']);
                $non_empty = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM `$table` WHERE `$c` IS NOT NULL AND `$c` NOT IN ('', '[]', '{}', 'null', '\"\"')"
                );
                $field = function_exists('acf_get_field') ? acf_get_field($col['identifier']) : false;
                $note = '';
                if (! $field) {
                    $note = 'ORPHAN: no ACF field by this name — archive only';
                } elseif (! empty($field['parent']) && is_string($field['parent']) && str_starts_with($field['parent'], 'field_')) {
                    $parent = acf_get_field($field['parent']);
                    $note = 'nested inside '.($parent ? $parent['type'].' "'.$parent['name'].'"' : $field['parent']).' — plugin likely never intercepted it';
                } elseif (in_array($field['type'], ['group', 'clone', 'flexible_content'], true)) {
                    $note = $field['type'].' — unsupported by plugin, values likely in postmeta';
                } elseif ($field['type'] === 'repeater') {
                    $note = 'repeater (encoded column)';
                }

                $t['columns'][] = [
                    'column' => $col['name'],
                    'acf_name' => $col['identifier'].($col['identifier'] !== $col['name'] ? ' *' : ''),
                    'acf_type' => $field ? $field['type'] : '-',
                    'non_empty' => $non_empty,
                    'note' => $note,
                ];
            }

            $t['orphans'] = $this->orphan_columns($def);
            $out['tables'][] = $t;
        }

        return $out;
    }

    /**
     * DB columns whose identifier has no ACF field, or that aren't in the JSON at all.
     *
     * @param  array<string, mixed>  $def  A table definition, as `Site::table_defs()` returns it.
     * @return list<string>
     */
    public function orphan_columns(array $def): array
    {
        global $wpdb;
        $table = $this->site->prefixed($def['name']);
        $def_cols = array_column($def['columns'], 'identifier', 'name');
        $orphans = [];
        foreach ($wpdb->get_col("SHOW COLUMNS FROM `$table`") as $col) {
            if (in_array($col, ['id', 'post_id'], true)) {
                continue;
            }

            $ident = $def_cols[$col] ?? '';
            if (! $ident || ! acf_get_field($ident)) {
                $orphans[] = $col;
            }
        }

        return $orphans;
    }

    /**
     * Stream every raw row of a custom table to a callback (archive). Read-only.
     *
     * @param  string  $table_name
     * @param  callable  $emit  function( array $row )
     * @return int rows
     */
    public function each_table_row($table_name, callable $emit): int
    {
        global $wpdb;
        $table = $this->site->prefixed($table_name);
        $has_id = in_array('id', $wpdb->get_col("SHOW COLUMNS FROM `$table`"), true);
        $count = 0;
        $last = 0;
        $offset = 0;
        do {
            $batch = $has_id
                ? $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE id > %d ORDER BY id ASC LIMIT 500", $last), ARRAY_A)
                : $wpdb->get_results("SELECT * FROM `$table` LIMIT 500 OFFSET $offset", ARRAY_A);
            foreach ($batch as $row) {
                $emit($row);
                $count++;
                $last = $has_id ? (int) $row['id'] : $last;
            }

            $offset += 500;
        } while (count($batch) === 500);

        return $count;
    }

    /* ================================================================== snapshot */

    /**
     * @param  int|string  $label
     * @param  string  $mode  One of `SNAPSHOT_MODES`.
     * @param  bool  $overwrite  Replace an existing snapshot with the same label.
     */
    public function snapshot_start($label, $mode, $overwrite = false): string
    {
        $label = self::clean_ref($label);
        if (! in_array($mode, self::SNAPSHOT_MODES, true)) {
            throw new \InvalidArgumentException('Mode must be effective or meta.');
        }

        if (Store::get('snapinfo', $label) !== null) {
            if (! $overwrite) {
                throw new \RuntimeException("A snapshot named '$label' already exists on this site.");
            }

            Store::delete(['snapinfo', 'snapshot'], $label);
        }

        $guard = new HookGuard;
        Store::put('snapinfo', $label, 0, '', [
            'mode' => $guard->has_intercepts() ? $mode : 'meta (plugin absent)',
            'post_types' => $this->site->post_types(),
            'total' => $this->total_posts(),
            'status' => 'running',
            'started' => gmdate('c'),
            'url' => $this->site->url,
            'cache_problems' => $this->site->cache_problems(),
        ]);

        return $label;
    }

    /**
     * @param  string  $label
     * @param  int|string  $cursor  Last post ID already handled.
     * @param  int  $limit
     * @param  array<int, int|string>|null  $ids
     * @return array{processed: int, cursor: int|string, done: bool, values: int}
     */
    public function snapshot_batch($label, $cursor, $limit = 25, $ids = null): array
    {
        $info = Store::get('snapinfo', $label);
        if (! $info) {
            throw new \RuntimeException("Snapshot '$label' was not started.");
        }

        $guard = new HookGuard;
        if ($guard->has_intercepts() && str_starts_with($info['mode'], 'meta')) {
            $guard->detach();
        }

        $posts = $this->next_posts($cursor, $limit, $ids);
        $values = 0;
        foreach ($posts as $p) {
            $guard->reset_caches();
            $out = [];
            foreach ($this->site->fields_for_post((int) $p->ID, $p->post_type) as $name => $field) {
                $norm = Normalizer::value($field, acf_get_value((int) $p->ID, $field));
                if ($norm !== null) {
                    $out[$name] = ['t' => $field['type'], 'v' => $norm];
                    $values++;
                }
            }

            Store::put('snapshot', $label, (int) $p->ID, '', [
                'post_id' => (int) $p->ID,
                'post_type' => $p->post_type,
                'post_status' => $p->post_status,
                'post_title' => $p->post_title,
                'fields' => $out,
            ]);
            $cursor = (int) $p->ID;
        }

        $guard->attach();

        $done = count($posts) < $limit;
        $info['cursor'] = $cursor;
        if (! $done) {
            Store::put('snapinfo', $label, 0, '', $info);
        } else {
            $info['status'] = 'complete';
            $info['finished'] = gmdate('c');
            $info['posts'] = Store::count('snapshot', $label);
            Store::put('snapinfo', $label, 0, '', $info);
        }

        return ['processed' => count($posts), 'cursor' => $cursor, 'done' => $done, 'values' => $values];
    }

    /* ================================================================== compare */

    /**
     * @param  int|string  $a
     * @param  int|string  $b
     * @param  array<int, int|string>  $ignore  Field names to leave out of the comparison; only an exact match counts.
     * @return array{summary: array<string, int|bool>, diffs: list<array<string, mixed>>}
     */
    public function compare($a, $b, array $ignore = []): array
    {
        $a = self::clean_ref($a);
        $b = self::clean_ref($b);
        $ia = Store::get('snapinfo', $a);
        $ib = Store::get('snapinfo', $b);
        if (! $ia || ! $ib) {
            throw new \RuntimeException('Both snapshots must exist on this site.');
        }

        foreach ([$a => $ia, $b => $ib] as $ref => $info) {
            // The default never equals 'complete', whatever its value.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            if (($info['status'] ?? '') !== 'complete') {
                throw new \RuntimeException("Snapshot '$ref' is incomplete — resume or re-run it first.");
            }
        }

        $pa = Store::by_post('snapshot', $a);
        $pb = Store::by_post('snapshot', $b);

        $summary = ['posts_a' => count($pa), 'posts_b' => count($pb), 'only_in_a' => 0, 'only_in_b' => 0, 'posts_with_diffs' => 0, 'fields_differ' => 0];
        $diffs = [];
        foreach ($pa + $pb as $post_id => $_) {
            if (! isset($pa[$post_id]) || ! isset($pb[$post_id])) {
                $key = isset($pa[$post_id]) ? 'only_in_a' : 'only_in_b';
                $summary[$key]++;
                $diffs[] = ['post_id' => $post_id, 'title' => isset($pa[$post_id]) ? $pa[$post_id]['post_title'] : $pb[$post_id]['post_title'], 'field' => '(whole post)', 'a' => $key === 'only_in_a' ? 'present' : null, 'b' => $key === 'only_in_b' ? 'present' : null];

                continue;
            }

            $fa = $pa[$post_id]['fields'];
            $fb = $pb[$post_id]['fields'];
            $dirty = false;
            foreach (array_keys($fa + $fb) as $name) {
                if (in_array($name, $ignore, true)) {
                    continue;
                }

                $va = isset($fa[$name]) ? $fa[$name]['v'] : null;
                $vb = isset($fb[$name]) ? $fb[$name]['v'] : null;
                if (Normalizer::same($va, $vb)) {
                    continue;
                }

                $dirty = true;
                $summary['fields_differ']++;
                $diffs[] = ['post_id' => $post_id, 'title' => $pa[$post_id]['post_title'], 'field' => $name, 'a' => $va, 'b' => $vb];
            }

            if ($dirty) {
                $summary['posts_with_diffs']++;
            }
        }

        $summary['identical'] = ! $diffs;

        return ['summary' => $summary, 'diffs' => $diffs];
    }

    /* ================================================================== backfill */

    /**
     * @param  bool|int|string  $dry  Read as a boolean.
     * @param  bool|int|string  $no_clear  Read as a boolean.
     * @param  int|string|null  $stamp  The run name; one is generated when empty.
     * @param  bool  $allow_stale_cache
     */
    public function backfill_start($dry, $no_clear, $stamp = null, $allow_stale_cache = false): string
    {
        $guard = new HookGuard;
        if (! $guard->has_intercepts()) {
            throw new \RuntimeException('ACF Custom Database Tables intercepts not found — the plugin must be active to backfill from it.');
        }

        $problems = $this->site->cache_problems();
        if ($problems && ! $allow_stale_cache && ! $dry) {
            throw new \RuntimeException(implode(' ', $problems).' (Override only if you have decided postmeta is the current copy.)');
        }

        $stamp = $stamp ? self::clean_ref($stamp) : gmdate('Ymd-His').($dry ? '-dry' : '');
        if (Store::get('run', $stamp) !== null) {
            throw new \RuntimeException("A backfill run '$stamp' already exists on this site.");
        }

        Store::put('run', $stamp, 0, '', [
            'dry' => (bool) $dry,
            'no_clear' => (bool) $no_clear,
            'total' => $this->total_posts(),
            'status' => 'running',
            'started' => gmdate('c'),
            'url' => $this->site->url,
        ]);

        return $stamp;
    }

    /**
     * @param  string  $stamp
     * @param  int|string  $cursor  Last post ID already handled.
     * @param  int  $limit
     * @param  array<int, int|string>|null  $ids
     * @return array{processed: int, cursor: int|string, done: bool, written: int, mismatches: int, warnings: list<string>}
     */
    public function backfill_batch($stamp, $cursor, $limit = 10, $ids = null): array
    {
        $run = Store::get('run', $stamp);
        if (! $run) {
            throw new \RuntimeException("Backfill run '$stamp' was not started.");
        }

        $dry = ! empty($run['dry']);
        $no_clear = ! empty($run['no_clear']);
        $guard = new HookGuard;
        if (! $guard->has_intercepts()) {
            throw new \RuntimeException('ACF Custom Database Tables intercepts not found — was the plugin deactivated mid-run?');
        }

        $posts = $this->next_posts($cursor, $limit, $ids);
        $written = 0;
        $mismatch = 0;
        $warnings = [];

        foreach ($posts as $p) {
            $post_id = (int) $p->ID;
            $fields = $this->site->fields_for_post($post_id, $p->post_type);

            // 1. what the site serves today (through the plugin)
            $guard->attach();
            $guard->reset_caches();
            $eff = [];
            foreach ($fields as $name => $field) {
                $eff[$name] = acf_get_value($post_id, $field);
            }

            // 2. what postmeta holds (plugin off) — all writes happen in this mode
            $guard->detach();
            wp_cache_delete($post_id, 'post_meta');
            $changed = false;

            foreach ($fields as $name => $field) {
                $n_eff = Normalizer::value($field, $eff[$name]);
                $n_meta = Normalizer::value($field, acf_get_value($post_id, $field));
                if (Normalizer::same($n_eff, $n_meta)) {
                    continue;
                }

                $cat = $n_meta === null ? 'fill' : ($n_eff === null ? 'clear' : 'overwrite');
                $record = [
                    'post_id' => $post_id,
                    'post_type' => $p->post_type,
                    'title' => $p->post_title,
                    'modified' => $p->post_modified,
                    'field' => $name,
                    'key' => $field['key'],
                    'type' => $field['type'],
                    'category' => $cat,
                    'effective' => $n_eff,
                    'meta_before' => $n_meta,
                ];

                $invalid = Normalizer::has_invalid_ids($field, $n_eff);
                if ($invalid) {
                    $record['invalid'] = true; // corrupted ID value in the custom table — fix by hand, then re-run
                }

                if ($dry || $invalid || ($cat === 'clear' && $no_clear)) {
                    if (! $dry) {
                        $record['skipped'] = true;
                    }

                    Store::put('change', $stamp, $post_id, $name, $record);

                    continue;
                }

                // Back up exactly the rows this field owns. INSERT IGNORE: if this batch is retried after
                // the write already happened, the original (pre-write) backup is kept.
                $complex = Normalizer::is_complex($field);
                Store::put('backup', $stamp, $post_id, $name, [
                    'post_id' => $post_id,
                    'field' => $name,
                    'complex' => $complex,
                    'rows' => $this->site->meta_rows_for_field($post_id, $name, $complex),
                ], 'ignore');

                // Always write, never delete: a missing meta row would make ACF fall back to the field's
                // default value, which can differ from the empty value the site serves today.
                // wp_slash(): update_metadata() runs wp_unslash() on every value, which would strip one level
                // of backslashes from any content that contains them (e.g. \&quot; inside saved HTML).
                // Every field has an effective value by this point, so the default is never used.
                // @pest-mutate-ignore: EmptyStringToNotEmpty
                $this->write_with_trail($stamp, $post_id, $name, $field, $complex, $eff[$name] ?? '');
                $written++;
                $changed = true;

                HookGuard::flush_acf_values();
                wp_cache_delete($post_id, 'post_meta');
                $n_after = Normalizer::value($field, acf_get_value($post_id, $field));
                if (! Normalizer::same($n_eff, $n_after)) {
                    $mismatch++;
                    $record['write_mismatch'] = true;
                    $record['meta_after'] = $n_after;
                    $warnings[] = sprintf('Post %d field %s: postmeta does not match after write.', $post_id, $name);
                }

                Store::put('change', $stamp, $post_id, $name, $record);
            }

            if ($changed) {
                clean_post_cache($post_id);
            }

            $cursor = $post_id;
        }

        $guard->attach();

        $done = count($posts) < $limit;
        $run['cursor'] = $cursor;
        if (! $done) {
            Store::put('run', $stamp, 0, '', $run);
        } else {
            $run['status'] = 'complete';
            $run['finished'] = gmdate('c');
            Store::put('run', $stamp, 0, '', $run + Store::change_summary($stamp));
        }

        return ['processed' => count($posts), 'cursor' => $cursor, 'done' => $done, 'written' => $written, 'mismatches' => $mismatch, 'warnings' => $warnings];
    }

    /**
     * Writes one field's value through ACF, first backing up every OTHER postmeta row the write is about to touch.
     *
     * ACF Extended's bidirectional relationships answer a write on one post by rewriting the related post's value and
     * its `_<field>` reference row, and the reference there is the related field's key, which can differ from the one
     * stored. Those rows belong to a post the batch never visits as the owner of that field, so the backup of "the
     * rows this field owns" misses them and a rollback would leave them changed. The first backup of a row wins
     * (INSERT IGNORE), so the state before the run is the one a rollback restores.
     *
     * @param  array<string, mixed>  $field
     * @param  bool  $complex  whether the field owns sub-field rows, which the backup of its own rows already holds
     */
    private function write_with_trail(string $stamp, int $post_id, string $name, array $field, bool $complex, mixed $value): void
    {
        $trail = function ($check, $object_id, $meta_key) use ($stamp, $post_id, $name, $complex) {
            $object_id = (int) $object_id;
            $touched = ltrim((string) $meta_key, '_');
            $own = $object_id === $post_id && ($touched === $name || ($complex && str_starts_with($touched, $name.'_')));
            if (! $own) {
                Store::put('backup', $stamp, $object_id, $touched, [
                    'post_id' => $object_id,
                    'field' => $touched,
                    'complex' => false,
                    'rows' => $this->site->meta_rows_for_field($object_id, $touched, false),
                ], 'ignore');
            }

            return $check;
        };

        $hooks = ['update_post_metadata', 'add_post_metadata', 'delete_post_metadata'];
        foreach ($hooks as $hook) {
            add_filter($hook, $trail, 1, 3);
        }

        try {
            acf_update_value(wp_slash($value), $post_id, $field);
        } finally {
            foreach ($hooks as $hook) {
                remove_filter($hook, $trail, 1);
            }
        }
    }

    /* ================================================================== rollback */

    /**
     * @param  int|string  $stamp
     * @param  bool  $force  Roll back again even if already rolled back.
     */
    public function rollback_start($stamp, $force = false): string
    {
        $stamp = self::clean_ref($stamp);
        $run = Store::get('run', $stamp);
        if (! $run) {
            throw new \RuntimeException("No backfill run '$stamp' on this site.");
        }

        if (! empty($run['dry'])) {
            throw new \RuntimeException("'$stamp' was a dry run — nothing to roll back.");
        }

        $rb = Store::get('rollback', $stamp);
        // The default never equals 'complete', whatever its value.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if ($rb && ($rb['status'] ?? '') === 'complete' && ! $force) {
            throw new \RuntimeException("Run '$stamp' was already rolled back.");
        }

        Store::put('rollback', $stamp, 0, '', [
            'status' => 'running',
            'started' => gmdate('c'),
            'total' => Store::count('backup', $stamp),
        ]);

        return $stamp;
    }

    /**
     * Restores postmeta from backups, newest first (so overlapping key patterns such as
     * location_phone / location_phone_numbers unwind correctly). Cursor is the backup row id.
     *
     * @param  string  $stamp
     * @param  int|string  $cursor
     * @param  int  $limit
     * @return array{processed: int, cursor: int|string, done: bool}
     */
    public function rollback_batch($stamp, $cursor, $limit = 100): array
    {
        global $wpdb;
        $rows = Store::rows('backup', $stamp, (int) $cursor, $limit, true);
        $posts = [];
        foreach ($rows as $r) {
            $e = json_decode($r->data, true);
            $this->site->delete_meta_rows_for_field((int) $e['post_id'], $e['field'], ! empty($e['complex']));
            foreach ((array) $e['rows'] as $m) {
                $wpdb->insert($wpdb->postmeta, ['post_id' => (int) $e['post_id'], 'meta_key' => $m[0], 'meta_value' => $m[1]], ['%d', '%s', '%s']);
            }

            // Only the keys are read, and PHP turns a numeric string key into an integer.
            // @pest-mutate-ignore: RemoveIntegerCast, TrueToFalse
            $posts[(int) $e['post_id']] = true;
            $cursor = (int) $r->id;
        }

        foreach (array_keys($posts) as $pid) {
            clean_post_cache($pid);
            wp_cache_delete($pid, 'post_meta');
        }

        $done = count($rows) < $limit;
        // Store::get() returns null or an array, and a null takes the key the same way.
        // @pest-mutate-ignore: RemoveArrayCast
        $rb = (array) Store::get('rollback', $stamp);
        $rb['cursor'] = $cursor;
        if ($done) {
            $rb['status'] = 'complete';
            $rb['finished'] = gmdate('c');
        }

        Store::put('rollback', $stamp, 0, '', $rb);

        return ['processed' => count($rows), 'cursor' => $cursor, 'done' => $done];
    }
}
