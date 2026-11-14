<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

use WP_CLI;

use function WP_CLI\Utils\assoc_args_to_str;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;
use function WP_CLI\Utils\get_home_dir;
use function WP_CLI\Utils\get_php_binary;
use function WP_CLI\Utils\make_progress_bar;
use function WP_CLI\Utils\wp_clear_object_cache;

/**
 * WP-CLI front end (handy on Local). Uses the same Engine and the same migration-log table as the
 * wp-admin screen (Tools → ACFCDT Migration), so a snapshot taken here can be compared there and vice versa.
 *
 * Order: inventory → archive → snapshot before → backfill --dry-run → backfill →
 * snapshot after-meta --mode=meta → compare before after-meta → deactivate plugin → snapshot after-off → compare before after-off.
 *
 * Every data subcommand accepts --network: it runs once per site that has custom tables, each in its own
 * WP-CLI process (the plugin caches per-request state that is not blog-aware).
 */
class Command
{
    /* ================================================================== inventory */

    /**
     * Report what the custom tables hold and how they map to ACF fields. Read-only.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Run for every site that has custom tables.
     *
     * [--json-dir=<dir>]
     * : Override where the table_*.json definitions are read from (default: ACFCDT_JSON_DIR).
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @when after_wp_load
     */
    public function inventory($args, $assoc): void
    {
        if ($this->maybe_network('inventory', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $inv = $engine->inventory();
        $format = get_flag_value($assoc, 'format', 'table');

        $this->site_header($engine->site);
        WP_CLI::log('Table JSON dir:          '.$inv['json_dir']);
        WP_CLI::log('Plugin loaded:           '.($inv['plugin_loaded'] ? 'yes' : 'no'));
        WP_CLI::log('Plugin intercepts found: '.$inv['intercepts'].' on ['.implode(', ', $inv['hooks']).']');
        WP_CLI::log('Values stored in core meta: '.($inv['core_meta'] ? 'yes' : 'NO — custom table is the only copy'));
        foreach ($inv['cache'] as $name => $cs) {
            if ($name === '_missing') {
                continue;
            }

            WP_CLI::log(sprintf(
                'Table map cache for %s: post type "%s" — %s%s',
                $name,
                $cs['cache_post_type'] ?: '(none)',
                $cs['intercepting'] ? 'plugin IS reading this table' : 'plugin is NOT reading this table',
                $cs['intercepting'] && $cs['not_cached'] ? '; '.count($cs['not_cached']).' mapped column(s) not in cache (served from postmeta)' : ''
            ));
        }

        foreach ($inv['cache_problems'] as $problem) {
            WP_CLI::warning($problem);
        }

        foreach ($inv['tables'] as $t) {
            WP_CLI::log('');
            WP_CLI::log(WP_CLI::colorize("%G== {$t['table']}%n  (post type: {$t['post_type']}, def: {$t['def_file']})"));
            if (empty($t['exists'])) {
                WP_CLI::log('   table does not exist on this site');

                continue;
            }

            WP_CLI::log("   rows: {$t['rows']} | rows whose post no longer exists: {$t['missing_post']} | rows for other post types/revisions: {$t['wrong_type']} | {$t['post_type']} posts with no row: {$t['no_row']}");
            if ($t['extra_cols']) {
                WP_CLI::log('   columns in DB but not in JSON: '.implode(', ', $t['extra_cols']));
            }

            if ($t['missing_cols']) {
                WP_CLI::warning('   columns in JSON but missing from DB: '.implode(', ', $t['missing_cols']));
            }

            format_items($format, $t['columns'], ['column', 'acf_name', 'acf_type', 'non_empty', 'note']);
        }
    }

    /* ================================================================== archive */

    /**
     * Export every custom table row (all columns, raw) to JSONL, plus a CSV of orphaned columns. Read-only.
     *
     * Do this IN ADDITION to `wp db export` — it's the human-readable, per-site copy you keep after tables are dropped.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Run for every site that has custom tables.
     *
     * [--stamp=<stamp>]
     * : Folder name under archive/. Default: current UTC timestamp.
     *
     * [--dir=<dir>]
     * : Base output directory.
     *
     * [--json-dir=<dir>]
     * : Override table JSON directory.
     *
     * @when after_wp_load
     */
    public function archive($args, $assoc): void
    {
        $assoc['stamp'] ??= gmdate('Ymd-His');

        if ($this->maybe_network('archive', $args, $assoc)) {
            return;
        }

        global $wpdb;
        $site = new Site(get_flag_value($assoc, 'json-dir'));
        $dir = Site::out_dir(get_flag_value($assoc, 'dir'), 'archive/'.sanitize_file_name($assoc['stamp']));
        $this->site_header($site);

        $manifest = [];
        foreach ($site->active_defs() as $def) {
            $table = $site->prefixed($def['name']);
            $file = "$dir/site-{$site->blog_id}-{$table}.jsonl";
            $fh = fopen($file, 'w');
            $count = 0;
            $last = 0;
            $has_id = in_array('id', $wpdb->get_col("SHOW COLUMNS FROM `$table`"), true);
            $offset = 0;
            do {
                $batch = $has_id
                    ? $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE id > %d ORDER BY id ASC LIMIT 500", $last), ARRAY_A)
                    : $wpdb->get_results("SELECT * FROM `$table` LIMIT 500 OFFSET $offset", ARRAY_A);
                foreach ($batch as $row) {
                    fwrite($fh, wp_json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                    $count++;
                    $last = $has_id ? (int) $row['id'] : $last;
                }

                $offset += 500;
            } while (count($batch) === 500);

            fclose($fh);

            // Orphaned columns: mapped identifier has no ACF field, or column not in JSON at all.
            $db_cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
            $def_cols = array_column($def['columns'], 'identifier', 'name');
            $orphans = [];
            foreach ($db_cols as $col) {
                if (in_array($col, ['id', 'post_id'], true)) {
                    continue;
                }

                $ident = $def_cols[$col] ?? '';
                if (! $ident || ! acf_get_field($ident)) {
                    $orphans[] = $col;
                }
            }

            if ($orphans !== []) {
                $orphan_file = "$dir/site-{$site->blog_id}-{$table}-orphan-columns.csv";
                $cols = array_merge(['post_id'], $orphans);
                $quoted = implode(',', array_map(fn ($c): string => '`'.esc_sql($c).'`', $cols));
                $where = implode(' OR ', array_map(function ($c): string {
                    $c = esc_sql($c);

                    return "(`$c` IS NOT NULL AND `$c` <> '' AND `$c` <> '[]')";
                }, $orphans));
                $rows = $wpdb->get_results("SELECT $quoted FROM `$table` WHERE $where ORDER BY post_id", ARRAY_A);
                $oh = fopen($orphan_file, 'w');
                // The escape character is passed as its current default: omitting it is deprecated as of PHP 8.4.
                fputcsv($oh, $cols, ',', '"', '\\');
                foreach ($rows as $r) {
                    fputcsv($oh, $r, ',', '"', '\\');
                }

                fclose($oh);
                WP_CLI::log(sprintf('   %s: orphaned columns [%s] — %d rows with data → %s', $table, implode(', ', $orphans), count($rows), basename($orphan_file)));
            }

            $manifest[] = [
                'table' => $table,
                'rows' => $count,
                'file' => basename($file),
                'sha256' => hash_file('sha256', $file),
            ];
            WP_CLI::log(sprintf('   %s: %d rows → %s', $table, $count, $file));
        }

        file_put_contents("$dir/site-{$site->blog_id}-manifest.json", wp_json_encode([
            'blog_id' => $site->blog_id,
            'url' => $site->url,
            'time' => gmdate('c'),
            'tables' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($manifest !== []) {
            WP_CLI::log('   Also take a SQL dump of these tables: wp db export --tables='.implode(',', array_column($manifest, 'table')).' acfcdt-site-'.$site->blog_id.'.sql');
        }

        WP_CLI::success("Archived site {$site->blog_id}.");
    }

    /* ================================================================== snapshot */

    /**
     * Record every top-level ACF value for every provider/location post, as ACF sees it.
     *
     * ## OPTIONS
     *
     * <label>
     * : Snapshot name, e.g. before, after-meta, after-off.
     *
     * [--mode=<mode>]
     * : effective = through the plugin (what the site shows now). meta = bypass the plugin and read core
     *   postmeta (what the site will show once the plugin is gone). Default: effective.
     *
     * [--network]
     * : Run for every site that has custom tables.
     *
     * [--ids=<ids>]
     * : Comma-separated post IDs (testing).
     *
     * [--force]
     * : Overwrite an existing snapshot with the same name.
     *
     * [--json-dir=<dir>]
     * : Override table JSON directory.
     *
     * @when after_wp_load
     */
    public function snapshot($args, $assoc): void
    {
        if ($this->maybe_network('snapshot', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $this->site_header($engine->site);
        try {
            $label = $engine->snapshot_start($args[0], get_flag_value($assoc, 'mode', 'effective'), (bool) get_flag_value($assoc, 'force'));
        } catch (\Exception $exception) {
            WP_CLI::error($exception->getMessage());
        }

        $ids = $this->ids($assoc);
        $total = $engine->total_posts($ids);
        $bar = make_progress_bar("Snapshot '$label' – $total posts", $total);
        $cursor = 0;
        $values = 0;
        do {
            $r = $engine->snapshot_batch($label, $cursor, 50, $ids);
            $cursor = $r['cursor'];
            $values += $r['values'];
            for ($i = 0; $i < $r['processed']; $i++) {
                $bar->tick();
            }

            wp_clear_object_cache();
        } while (! $r['done']);

        $bar->finish();
        WP_CLI::success(sprintf('Site %d: snapshot "%s" — %d posts, %d non-empty values.', $engine->site->blog_id, $label, $total, $values));
    }

    /* ================================================================== compare */

    /**
     * Compare two snapshots field by field (per site). Exits 1 if anything differs.
     *
     * ## OPTIONS
     *
     * <label-a>
     * : Baseline snapshot (usually "before").
     *
     * <label-b>
     * : Snapshot to check.
     *
     * [--network]
     * : Compare on every site that has custom tables.
     *
     * [--ignore-fields=<names>]
     * : Comma-separated field names to ignore.
     *
     * [--limit=<n>]
     * : How many differences to print. Default: 25.
     *
     * @when after_wp_load
     */
    public function compare($args, $assoc): void
    {
        if ($this->maybe_network('compare', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $this->site_header($engine->site);
        $ignore = array_filter(array_map(trim(...), explode(',', (string) get_flag_value($assoc, 'ignore-fields', ''))));
        try {
            $res = $engine->compare($args[0], $args[1], $ignore);
        } catch (\Exception $exception) {
            WP_CLI::error($exception->getMessage());
        }

        foreach (array_slice($res['diffs'], 0, (int) get_flag_value($assoc, 'limit', 25)) as $d) {
            WP_CLI::log(sprintf('post %d "%s" field %s', $d['post_id'], $d['title'], $d['field']));
            WP_CLI::log("    {$args[0]}: ".$this->preview($d['a']));
            WP_CLI::log("    {$args[1]}: ".$this->preview($d['b']));
        }

        $s = $res['summary'];
        unset($s['identical']);
        format_items('table', [$s], array_keys($s));
        if ($res['diffs']) {
            WP_CLI::warning(count($res['diffs'])." difference(s) between '{$args[0]}' and '{$args[1]}'.");
            WP_CLI::halt(1);
        }

        WP_CLI::success("'{$args[0]}' and '{$args[1]}' are identical.");
    }

    /* ================================================================== backfill */

    /**
     * Copy every value the plugin is serving into core postmeta, where it differs. Idempotent.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change; write nothing.
     *
     * [--no-clear]
     * : Don't clear stale postmeta where the plugin is serving an empty value.
     *
     * [--network]
     * : Run for every site that has custom tables.
     *
     * [--ids=<ids>]
     * : Comma-separated post IDs (testing).
     *
     * [--stamp=<stamp>]
     * : Run name. Network runs share one stamp so `rollback <stamp> --network` works.
     *
     * [--allow-stale-cache]
     * : Do a real run even though the plugin's table map cache means it is not reading a table (see inventory).
     *
     * [--json-dir=<dir>]
     * : Override table JSON directory.
     *
     * @when after_wp_load
     */
    public function backfill($args, $assoc): void
    {
        $assoc['stamp'] ??= gmdate('Ymd-His').(get_flag_value($assoc, 'dry-run') ? '-dry' : '');

        if ($this->maybe_network('backfill', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $this->site_header($engine->site);
        $dry = (bool) get_flag_value($assoc, 'dry-run');
        try {
            $stamp = $engine->backfill_start($dry, (bool) get_flag_value($assoc, 'no-clear'), $assoc['stamp'], (bool) get_flag_value($assoc, 'allow-stale-cache'));
        } catch (\Exception $exception) {
            WP_CLI::error($exception->getMessage());
        }

        $ids = $this->ids($assoc);
        $total = $engine->total_posts($ids);
        $bar = make_progress_bar(($dry ? '[DRY RUN] ' : '')."Backfill – $total posts", $total);
        $cursor = 0;
        do {
            $r = $engine->backfill_batch($stamp, $cursor, 20, $ids);
            $cursor = $r['cursor'];
            foreach ($r['warnings'] as $w) {
                WP_CLI::warning($w);
            }

            for ($i = 0; $i < $r['processed']; $i++) {
                $bar->tick();
            }

            wp_clear_object_cache();
        } while (! $r['done']);

        $bar->finish();

        $sum = Store::change_summary($stamp);
        if ($sum['by_field']) {
            format_items('table', $sum['by_field'], ['field', 'fill', 'overwrite', 'clear']);
        }

        WP_CLI::log('Stats: '.wp_json_encode($sum['stats']));
        if ($sum['stats']['write_mismatch']) {
            WP_CLI::warning("{$sum['stats']['write_mismatch']} write mismatch(es) — inspect the changes for run $stamp.");
            WP_CLI::halt(1);
        }

        WP_CLI::success(($dry ? '[DRY RUN] ' : '')."Site {$engine->site->blog_id}: run $stamp — {$sum['stats']['posts']} posts / {$sum['stats']['fields']} fields ".($dry ? 'would change.' : 'backfilled.'));
    }

    /* ================================================================== rollback */

    /**
     * Restore postmeta to exactly what it was before a backfill run. Does not touch custom tables.
     *
     * ## OPTIONS
     *
     * <stamp>
     * : The run name.
     *
     * [--network]
     * : Roll back every site that has this run.
     *
     * [--force]
     * : Roll back again even if already rolled back.
     *
     * @when after_wp_load
     */
    public function rollback($args, $assoc): void
    {
        if ($this->maybe_network('rollback', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $this->site_header($engine->site);
        if (Store::get('run', $args[0]) === null) {
            WP_CLI::log("   No run {$args[0]} on this site — nothing to roll back.");

            return;
        }

        try {
            $stamp = $engine->rollback_start($args[0], (bool) get_flag_value($assoc, 'force'));
        } catch (\Exception $exception) {
            WP_CLI::error($exception->getMessage());
        }

        $cursor = 0;
        $n = 0;
        do {
            $r = $engine->rollback_batch($stamp, $cursor, 200);
            $cursor = $r['cursor'];
            $n += $r['processed'];
        } while (! $r['done']);

        WP_CLI::success("Site {$engine->site->blog_id}: $n field(s) restored from run $stamp.");
    }

    /* ================================================================== list */

    /**
     * List snapshots and backfill runs stored for this site.
     *
     * [--network]
     * : Every site that has custom tables.
     *
     * @when after_wp_load
     */
    public function status($args, $assoc): void
    {
        if ($this->maybe_network('status', $args, $assoc)) {
            return;
        }

        $engine = $this->engine($assoc);
        $this->site_header($engine->site);
        $rows = [];
        foreach (Store::headers('snapinfo') as $ref => $i) {
            $rows[] = ['type' => 'snapshot', 'name' => $ref, 'detail' => ($i['mode'] ?? ''), 'status' => $i['status'] ?? '', 'when' => $i['created']];
        }

        $rbs = Store::headers('rollback');
        foreach (Store::headers('run') as $ref => $r) {
            $st = $r['stats'] ?? Store::change_summary($ref)['stats'];
            $rows[] = ['type' => empty($r['dry']) ? 'backfill' : 'dry run', 'name' => $ref, 'detail' => "{$st['fields']} fields, {$st['write_mismatch']} mismatches".(isset($rbs[$ref]) ? ', rollback '.$rbs[$ref]['status'] : ''), 'status' => $r['status'] ?? '', 'when' => $r['created']];
        }

        $rows !== [] ? format_items('table', $rows, ['type', 'name', 'detail', 'status', 'when']) : WP_CLI::log('   nothing stored yet');
    }

    /* ================================================================== helpers */

    /**
     * If --network is set, re-run the subcommand once per site with custom tables, each in its own process.
     *
     * @return bool true if it handled the call
     */
    private function maybe_network(string $sub, $args, $assoc): bool
    {
        if (! get_flag_value($assoc, 'network')) {
            return false;
        }

        if (! is_multisite()) {
            return false; // just run on the single site
        }

        unset($assoc['network']);
        global $wpdb;

        $table_names = array_keys((new Site(get_flag_value($assoc, 'json-dir')))->table_defs());
        if ($table_names === []) {
            WP_CLI::error('No table definition JSON files found (is ACFCDT_JSON_DIR defined? try --json-dir).');
        }

        $targets = [];
        $skipped = [];
        foreach (get_sites(['number' => 0, 'deleted' => 0]) as $s) {
            $prefix = $wpdb->get_blog_prefix($s->blog_id);
            $has = false;
            foreach ($table_names as $t) {
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix.$t))) === $prefix.$t) {
                    $has = true;
                    break;
                }
            }

            if (! $has) {
                $skipped[] = $s->blog_id;

                continue;
            }

            $targets[] = $s;
        }

        WP_CLI::log(sprintf('Network %s: %d site(s) with custom tables; %d without (skipped).', $sub, count($targets), count($skipped)));

        // launch_self() only forwards path/url/user/allow-root, so pass through what the child needs.
        $assoc['require'] = UAMSWP_ACFCDT_MIGRATOR_FILE;
        $config = WP_CLI::get_runner()->config;
        foreach (['skip-plugins', 'skip-themes'] as $global) {
            if (! empty($config[$global])) {
                $assoc[$global] = is_array($config[$global]) ? implode(',', $config[$global]) : $config[$global];
            }
        }

        $failed = [];
        foreach ($targets as $s) {
            $url = $s->domain.$s->path;
            $res = $this->run_child('acfcdt-migrate '.$sub, $args, $assoc, $url);
            if ($res !== 0) {
                $failed[] = "{$s->blog_id} ($url)";
            }
        }

        WP_CLI::log('');
        if ($failed !== []) {
            WP_CLI::warning('Non-zero exit on: '.implode(', ', $failed));
            WP_CLI::halt(1);
        }

        WP_CLI::success("Network $sub finished on ".count($targets).' site(s).');

        return true;
    }

    /**
     * Like WP_CLI::launch_self(), but streams the child's output live instead of buffering it.
     *
     * @return int exit code
     */
    private function run_child(string $command, $args, $assoc, string $url): int
    {
        $config = WP_CLI::get_runner()->config;
        foreach (['path', 'user', 'allow-root'] as $key) {
            if (! empty($config[$key]) && ! isset($assoc[$key])) {
                $assoc[$key] = $config[$key];
            }
        }

        $assoc['url'] = $url;

        $config_path = getenv('WP_CLI_CONFIG_PATH') ?: get_home_dir().'/.wp-cli/config.yml';
        $cmd = sprintf(
            'WP_CLI_CONFIG_PATH=%s %s %s %s %s %s',
            escapeshellarg($config_path),
            escapeshellarg(get_php_binary()),
            escapeshellarg($GLOBALS['argv'][0]),
            $command,
            implode(' ', array_map(escapeshellarg(...), $args)),
            assoc_args_to_str($assoc)
        );

        $proc = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);
        if (! is_resource($proc)) {
            WP_CLI::warning("Could not start: $cmd");

            return 1;
        }

        return proc_close($proc);
    }

    private function site_header(Site $site): void
    {
        WP_CLI::log('');
        WP_CLI::log(WP_CLI::colorize("%Y### Site {$site->blog_id}: {$site->url}%n"));
    }

    private function engine($assoc): Engine
    {
        if (! Engine::acf_ready()) {
            WP_CLI::error('ACF is not loaded.');
        }

        return new Engine(new Site(get_flag_value($assoc, 'json-dir')));
    }

    private function ids($assoc): ?array
    {
        $ids = get_flag_value($assoc, 'ids');

        return $ids ? array_filter(array_map(absint(...), explode(',', $ids))) : null;
    }

    private function preview($v)
    {
        $s = $v === null ? '(empty)' : Normalizer::json($v);

        return strlen($s) > 160 ? substr($s, 0, 157).'...' : $s;
    }
}
