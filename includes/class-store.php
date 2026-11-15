<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

/**
 * All migration state lives in one network-wide table, so it works on hosts with no shell / no writable
 * private directory, survives request timeouts, and makes every batch safe to retry:
 *
 *  kind       ref            post_id  field        data
 *  snapinfo   <label>        0        ''           snapshot header (mode, time, totals)
 *  snapshot   <label>        post     ''           normalized field values for one post
 *  run        <stamp>        0        ''           backfill run header (dry, no_clear, status)
 *  change     <stamp>        post     field name   what differed / was written        (REPLACE)
 *  backup     <stamp>        post     field name   postmeta rows BEFORE the write     (INSERT IGNORE — first write wins)
 *  rollback   <stamp>        0        ''           rollback header
 *
 * Shapes of what the rows hold, for the callers that read them back:
 *
 * @phpstan-type NormalizedValue array<mixed>|string
 * @phpstan-type SnapInfo array{mode?: string, post_types?: list<string>, total?: int, status?: string, started?: string, url?: string, cache_problems?: list<string>, cursor?: int|string, finished?: string, posts?: int}
 * @phpstan-type SnapInfoRow array{mode?: string, post_types?: list<string>, total?: int, status?: string, started?: string, url?: string, cache_problems?: list<string>, cursor?: int|string, finished?: string, posts?: int, created: string}
 * @phpstan-type ChangeStats array{fields: int, fill: int, overwrite: int, clear: int, skipped: int, invalid: int, write_mismatch: int, posts: int}
 * @phpstan-type ByFieldRow array{field: string, fill: int, overwrite: int, clear: int}
 * @phpstan-type ChangeSummary array{stats: ChangeStats, by_field: list<ByFieldRow>}
 * @phpstan-type RunHeader array{dry?: bool, no_clear?: bool, total?: int, status?: string, started?: string, url?: string, cursor?: int|string, finished?: string, stats?: ChangeStats, by_field?: list<ByFieldRow>}
 * @phpstan-type RunHeaderRow array{dry?: bool, no_clear?: bool, total?: int, status?: string, started?: string, url?: string, cursor?: int|string, finished?: string, stats?: ChangeStats, by_field?: list<ByFieldRow>, created: string}
 * @phpstan-type RollbackHeader array{status?: string, started?: string, total?: int, cursor?: int|string, finished?: string}
 * @phpstan-type RollbackHeaderRow array{status?: string, started?: string, total?: int, cursor?: int|string, finished?: string, created: string}
 * @phpstan-type SnapshotRecord array{post_id: int, post_type: string, post_status: string, post_title: string, fields: array<int|string, array{t: string, v: NormalizedValue}>}
 * @phpstan-type ChangeRecord array{post_id: int, post_type: string, title: string, modified: string, field: int|string, key: string, type: string, category: 'fill'|'overwrite'|'clear', effective: NormalizedValue|null, meta_before: NormalizedValue|null, invalid?: bool, skipped?: bool, write_mismatch?: bool, meta_after?: NormalizedValue|null}
 * @phpstan-type BackupRecord array{post_id: int, field: int|string, complex: bool, rows: array<int, array<int, string|null>>|null}
 */
class Store
{
    const DB_VERSION = 1;

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->base_prefix.'acfcdt_migration';
    }

    public static function install(): void
    {
        global $wpdb;
        if (Cast::int(get_site_option('uamswp_acfcdt_migrator_db', 0)) >= self::DB_VERSION && self::exists()) {
            return;
        }

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(20) NOT NULL DEFAULT '',
			ref varchar(64) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field varchar(191) NOT NULL DEFAULT '',
			data longtext NULL,
			created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY entry (blog_id,kind,ref,post_id,field),
			KEY lookup (blog_id,kind,ref,id)
		) $charset;");
        update_site_option('uamswp_acfcdt_migrator_db', self::DB_VERSION);
    }

    public static function exists(): bool
    {
        global $wpdb;
        $t = self::table();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t;
    }

    /**
     * @param  string  $kind  Row kind, such as `snapinfo`, `run`, `change`.
     * @param  string  $ref  Snapshot label or run stamp.
     * @param  int|string  $post_id  Cast to an integer.
     * @param  int|string  $field  Cast to a string.
     * @param  mixed  $data  Anything `Normalizer::json()` can encode.
     * @param  string  $mode  'replace' or 'ignore'
     * @return int|bool Rows affected, or `false` when the query failed.
     */
    public static function put($kind, $ref, $post_id, $field, $data, $mode = 'replace')
    {
        global $wpdb;
        $verb = $mode === 'ignore' ? 'INSERT IGNORE' : 'REPLACE';
        $json = Normalizer::json($data);

        return $wpdb->query($wpdb->prepare(
            "$verb INTO ".self::table().' (blog_id, kind, ref, post_id, field, data, created) VALUES (%d, %s, %s, %d, %s, %s, %s)',
            get_current_blog_id(),
            $kind,
            $ref,
            (int) $post_id,
            (string) $field,
            $json,
            current_time('mysql', true)
        ));
    }

    /**
     * @param  string  $kind
     * @param  string  $ref
     * @param  int|string  $post_id  Cast to an integer.
     * @param  int|string  $field  Cast to a string.
     * @return mixed The decoded value, or `null` when no row matches.
     */
    public static function get($kind, $ref, $post_id = 0, $field = '')
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            'SELECT data FROM '.self::table().' WHERE blog_id = %d AND kind = %s AND ref = %s AND post_id = %d AND field = %s',
            get_current_blog_id(),
            $kind,
            $ref,
            (int) $post_id,
            (string) $field
        ));

        return $json === null ? null : json_decode($json, true);
    }

    /**
     * The snapshot header of one label.
     *
     * @param  string  $ref  Snapshot label.
     * @return SnapInfo|null `null` when no row matches; `[]` when the stored value is not an array.
     */
    public static function snapinfo($ref): ?array
    {
        $data = self::get('snapinfo', $ref);

        if ($data === null) {
            return null;
        }

        // Header rows are written only by `put()` from the arrays `Engine` builds, so a decoded array has this shape.
        /** @var SnapInfo $header */
        $header = self::shape($data);

        return $header;
    }

    /**
     * The header of one backfill run.
     *
     * @param  string  $stamp  Run name.
     * @return RunHeader|null `null` when no row matches; `[]` when the stored value is not an array.
     */
    public static function run_header($stamp): ?array
    {
        $data = self::get('run', $stamp);

        if ($data === null) {
            return null;
        }

        // Header rows are written only by `put()` from the arrays `Engine` builds, so a decoded array has this shape.
        /** @var RunHeader $header */
        $header = self::shape($data);

        return $header;
    }

    /**
     * The header of one rollback.
     *
     * @param  string  $stamp  Run name the rollback belongs to.
     * @return RollbackHeader|null `null` when no row matches; `[]` when the stored value is not an array.
     */
    public static function rollback_header($stamp): ?array
    {
        $data = self::get('rollback', $stamp);

        if ($data === null) {
            return null;
        }

        // Header rows are written only by `put()` from the arrays `Engine` builds, so a decoded array has this shape.
        /** @var RollbackHeader $header */
        $header = self::shape($data);

        return $header;
    }

    /**
     * The one place a decoded header enters typed code.
     *
     * Header rows are written only by `put()` from the arrays `Engine` builds, so a decoded array has the header shape
     * of its kind. Anything that is not an array reads as `[]`, which every header shape allows.
     *
     * @param  mixed  $decoded  The result of `json_decode()` on the row's data.
     * @return array<string, mixed>
     */
    private static function shape(mixed $decoded): array
    {
        return Cast::arr($decoded);
    }

    /**
     * Rows of one kind/ref, cursor-paged by id.
     *
     * @param  string  $kind
     * @param  string  $ref
     * @param  int  $after_id  Last row id already read; with `$desc`, `0` starts from the newest.
     * @param  int  $limit
     * @param  bool  $desc  Walk from the newest row down instead of the oldest up.
     * @return array<int, \stdClass>
     *
     * @throws \RuntimeException When the query fails.
     */
    public static function rows($kind, $ref, $after_id = 0, $limit = 500, $desc = false)
    {
        global $wpdb;
        $op = $desc ? '<' : '>';
        $order = $desc ? 'DESC' : 'ASC';
        if ($desc && ! $after_id) {
            $after_id = PHP_INT_MAX;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, post_id, field, data FROM '.self::table()." WHERE blog_id = %d AND kind = %s AND ref = %s AND id $op %d ORDER BY id $order LIMIT %d",
            get_current_blog_id(),
            $kind,
            $ref,
            $after_id,
            $limit
        ));
        if ($rows === null) {
            throw new \RuntimeException("Cannot read the $kind rows for $ref.");
        }

        return $rows;
    }

    /**
     * Every row of a kind/ref decoded, keyed by post_id (snapshots).
     *
     * Rows are returned as decoded, so a caller reading a snapshot record narrows each one itself (see `SnapshotRecord`).
     *
     * @param  string  $kind
     * @param  string  $ref
     * @return array<int, mixed>
     */
    public static function by_post($kind, $ref): array
    {
        $out = [];
        $after = 0;
        do {
            $batch = self::rows($kind, $ref, $after, 1000);
            foreach ($batch as $r) {
                // A numeric string key becomes an integer key, so the cast changes nothing.
                // @pest-mutate-ignore: RemoveIntegerCast
                $out[(int) $r->post_id] = json_decode($r->data, true);
                $after = (int) $r->id;
            }
        } while (count($batch) === 1000);

        return $out;
    }

    /**
     * @param  string  $kind
     * @param  string  $ref
     * @param  string  $where_sql  Extra SQL appended to the `WHERE` clause, already prepared.
     */
    public static function count($kind, $ref, $where_sql = ''): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.self::table()." WHERE blog_id = %d AND kind = %s AND ref = %s $where_sql",
            get_current_blog_id(),
            $kind,
            $ref
        ));
    }

    /**
     * @param  list<string>  $kinds
     * @param  string  $ref
     * @return int|bool Rows deleted, or `false` when the query failed.
     */
    public static function delete(array $kinds, $ref)
    {
        global $wpdb;
        // The first index array_fill() numbers from is discarded by implode().
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $in = implode(',', array_fill(0, count($kinds), '%s'));

        return $wpdb->query($wpdb->prepare(
            'DELETE FROM '.self::table()." WHERE blog_id = %d AND ref = %s AND kind IN ($in)",
            array_merge([get_current_blog_id(), $ref], $kinds)
        ));
    }

    /**
     * List refs of a header kind (snapinfo / run) with their header data.
     *
     * Each header is the decoded data with the row's creation time under `created` (a `created` key the data holds
     * itself wins). Data that does not decode to an array reads as an empty header.
     *
     * @param  string  $kind  `snapinfo`, `run` or `rollback`.
     * @return ($kind is 'snapinfo' ? array<string, SnapInfoRow> : ($kind is 'run' ? array<string, RunHeaderRow> : ($kind is 'rollback' ? array<string, RollbackHeaderRow> : array<string, array<string, mixed>>)))
     */
    public static function headers($kind): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ref, data, created FROM '.self::table().' WHERE blog_id = %d AND kind = %s ORDER BY id DESC',
            get_current_blog_id(),
            $kind
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[Cast::str($r->ref)] = self::shape(json_decode(Cast::str($r->data), true)) + ['created' => Cast::str($r->created)];
        }

        return $out;
    }

    /**
     * Category counts for a backfill run, computed from change rows (accurate even if batches were retried).
     *
     * @param  string  $stamp
     * @return ChangeSummary
     */
    public static function change_summary($stamp): array
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT data FROM '.self::table().' WHERE blog_id = %d AND kind = %s AND ref = %s',
            get_current_blog_id(),
            'change',
            $stamp
        ));
        $stats = ['fields' => 0, 'fill' => 0, 'overwrite' => 0, 'clear' => 0, 'skipped' => 0, 'invalid' => 0, 'write_mismatch' => 0];
        $posts = [];
        $by = [];
        foreach ($rows as $json) {
            $c = Cast::arr(json_decode(Cast::str($json), true));
            $category = Cast::str($c['category'] ?? null);
            $known = in_array($category, ['fill', 'overwrite', 'clear'], true);
            $stats['fields']++;
            if ($known) {
                $stats[$category]++;
            }

            if (! empty($c['skipped'])) {
                $stats['skipped']++;
            }

            if (! empty($c['invalid'])) {
                $stats['invalid']++;
            }

            if (! empty($c['write_mismatch'])) {
                $stats['write_mismatch']++;
            }

            // Only the keys are counted, so the value stored makes no difference.
            // @pest-mutate-ignore: TrueToFalse
            $posts[Cast::int($c['post_id'] ?? 0)] = true;
            $k = Cast::str($c['post_type'] ?? '').'.'.Cast::str($c['field'] ?? '');
            $by[$k] ??= ['field' => $k, 'fill' => 0, 'overwrite' => 0, 'clear' => 0];

            if ($known) {
                $by[$k][$category]++;
            }
        }

        $stats['posts'] = count($posts);
        ksort($by);

        return ['stats' => $stats, 'by_field' => array_values($by)];
    }
}
