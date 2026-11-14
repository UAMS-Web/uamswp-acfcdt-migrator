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
        if ((int) get_site_option('uamswp_acfcdt_migrator_db', 0) >= self::DB_VERSION && self::exists()) {
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
     * Rows of one kind/ref, cursor-paged by id.
     *
     * @param  string  $kind
     * @param  string  $ref
     * @param  int  $after_id  Last row id already read; with `$desc`, `0` starts from the newest.
     * @param  int  $limit
     * @param  bool  $desc  Walk from the newest row down instead of the oldest up.
     * @return array<int, \stdClass>|null
     */
    public static function rows($kind, $ref, $after_id = 0, $limit = 500, $desc = false)
    {
        global $wpdb;
        $op = $desc ? '<' : '>';
        $order = $desc ? 'DESC' : 'ASC';
        if ($desc && ! $after_id) {
            $after_id = PHP_INT_MAX;
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, post_id, field, data FROM '.self::table()." WHERE blog_id = %d AND kind = %s AND ref = %s AND id $op %d ORDER BY id $order LIMIT %d",
            get_current_blog_id(),
            $kind,
            $ref,
            $after_id,
            $limit
        ));
    }

    /**
     * Every row of a kind/ref decoded, keyed by post_id (snapshots).
     *
     * @param  string  $kind
     * @param  string  $ref
     * @return mixed[]
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
     * @param  string  $kind
     * @return array<string, non-empty-array<string, mixed>>
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
            $out[$r->ref] = (array) json_decode($r->data, true) + ['created' => $r->created];
        }

        return $out;
    }

    /**
     * Category counts for a backfill run, computed from change rows (accurate even if batches were retried).
     *
     * @param  string  $stamp
     * @return array{stats: array<string, int>, by_field: list<array<string, int|string>>}
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
            $c = json_decode($json, true);
            $stats['fields']++;
            $stats[$c['category']]++;
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
            $posts[$c['post_id']] = true;
            $k = $c['post_type'].'.'.$c['field'];
            $by[$k] ??= ['field' => $k, 'fill' => 0, 'overwrite' => 0, 'clear' => 0];

            $by[$k][$c['category']]++;
        }

        $stats['posts'] = count($posts);
        /** @var array<string, array<string, int|string>> $by */
        ksort($by);

        return ['stats' => $stats, 'by_field' => array_values($by)];
    }
}
