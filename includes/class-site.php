<?php

namespace UAMSWP_ACFCDT_Migrator;

/**
 * Everything specific to the current site (blog): table definitions, which tables exist,
 * which posts to process, which ACF fields apply to a post, and where output files go.
 */
class Site {

	/** Field types that never hold a value. */
	const SKIP_TYPES = [ 'tab', 'message', 'accordion' ];

	public $blog_id;

	public $url;


	private ?array $defs = null;

	private array $field_cache = [];

	public function __construct( private $json_dir_override = null ) {
		$this->blog_id           = get_current_blog_id();
		$this->url               = home_url( '/' );
	}

	/* ------------------------------------------------------------------ table definitions */

	public function json_dir() {
		if ( $this->json_dir_override ) {
			return untrailingslashit( $this->json_dir_override );
		}

		if ( defined( 'ACFCDT_JSON_DIR' ) ) {
			return untrailingslashit( ACFCDT_JSON_DIR );
		}

		if ( function_exists( 'acf_get_setting' ) && acf_get_setting( 'save_json' ) ) {
			return untrailingslashit( acf_get_setting( 'save_json' ) ) . '/database-tables';
		}

		return WP_CONTENT_DIR . '/uploads/acf-custom-database-tables';
	}

	/**
	 * Parsed table_*.json definitions for post-type tables.
	 *
	 * @return array name => [name, post_type, file, columns => [[name, identifier]]]
	 */
	public function table_defs(): array {
		if ( $this->defs !== null ) {
			return $this->defs;
		}

		$this->defs = [];
		foreach ( (array) glob( $this->json_dir() . '/*.json' ) as $file ) {
			$def = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $def ) || empty( $def['name'] ) ) {
				continue;
			}

			$rel = $def['relationship'] ?? [];
			if ( ( $rel['type'] ?? '' ) !== 'post' || empty( $rel['post_type'] ) ) {
				continue;
			}

			$columns = [];
			foreach ( (array) ( $def['columns'] ?? [] ) as $col ) {
				$columns[] = [
					'name'       => $col['name'] ?? '',
					'identifier' => $col['map']['identifier'] ?? '',
				];
			}

			$this->defs[ $def['name'] ] = [
				'name'      => $def['name'],
				'post_type' => $rel['post_type'],
				'file'      => $file,
				'columns'   => $columns,
			];
		}

		return $this->defs;
	}

	public function prefixed( $table ): string {
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	public function table_exists( $table ): bool {
		global $wpdb;
		$full = $this->prefixed( $table );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) === $full;
	}

	/** Table defs whose table actually exists on this site. */
	public function active_defs(): array {
		return array_filter( $this->table_defs(), fn(array $def): bool => $this->table_exists( $def['name'] ) );
	}

	/**
	 * Is ACF Custom Database Tables actually reading each table right now?
	 *
	 * The plugin decides at runtime from its cache, <json dir>/.cache/_table_map.php — NOT from the table_*.json
	 * files. If the cache names a different post type (the committed cache says 'physicians'/'locations' instead of
	 * 'provider'/'location' — Find-a-Doc #813), the plugin intercepts nothing for that post type and the site is
	 * serving postmeta, even though the table exists and holds data. Columns missing from the cache are likewise
	 * served from postmeta.
	 *
	 * @return array table name => [cache_post_type, intercepting, not_cached[]]; [] if there is no cache file.
	 */
	public function cache_status(): array {
		$file = $this->json_dir() . '/.cache/_table_map.php';
		if ( ! is_file( $file ) ) {
			return [ '_missing' => $file ];
		}

		$map = ( static fn($f) => include $f )( $file );
		$map = is_array( $map ) ? $map : [];

		$out = [];
		foreach ( $this->table_defs() as $name => $def ) {
			$ctx    = 'post:' . $def['post_type'];
			$cached = isset( $map['acf_field_names'][ $ctx ] ) ? array_keys( (array) $map['acf_field_names'][ $ctx ] ) : [];
			$not    = [];
			foreach ( $def['columns'] as $col ) {
				if ( $col['identifier'] && ! in_array( $col['identifier'], $cached, true ) && ! in_array( $col['name'], $cached, true ) ) {
					$not[] = $col['identifier'];
				}
			}

			$out[ $name ] = [
				'cache_post_type' => $map['tables'][ $name ]['relationship']['post_type'] ?? null,
				'intercepting'    => isset( $map['post_types'][ $def['post_type'] ] ),
				'not_cached'      => $not,
			];
		}

		return $out;
	}

	/**
     * Human-readable problems that mean the plugin is NOT serving a table's data right now.
     * @return string[]
     */
    public function cache_problems(): array {
		$status = $this->cache_status();
		if ( isset( $status['_missing'] ) ) {
			return [ "No table map cache at {$status['_missing']} — the plugin is not reading any custom table. Run Create/Update Tables first." ];
		}

		$problems = [];
		foreach ( $this->active_defs() as $name => $def ) {
			if ( empty( $status[ $name ]['intercepting'] ) ) {
				$problems[] = sprintf(
					'The plugin is NOT reading %s for "%s" posts — its cache says post type "%s". The site is currently showing postmeta for these posts, not the table. Run Create/Update Tables (Find-a-Doc #813) before migrating, or decide deliberately which copy is current.',
					$this->prefixed( $name ), $def['post_type'], $status[ $name ]['cache_post_type'] ?: '(none)'
				);
			}
		}

		return $problems;
	}

	public function post_types(): array {
		return array_values( array_unique( array_map( fn(array $d) => $d['post_type'], $this->active_defs() ) ) );
	}

	/* ------------------------------------------------------------------ posts & fields */

	/**
	 * Every post of the given types except auto-drafts and revisions (trash and drafts included on purpose).
	 *
	 * @return object[] {ID, post_type, post_status, post_title}
	 */
	public function posts( array $types, $ids = null, $limit = 0 ) {
		global $wpdb;
		if ( $types === [] ) {
			return [];
		}

		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = $wpdb->prepare(
			"SELECT ID, post_type, post_status, post_title, post_modified FROM {$wpdb->posts}
			 WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','inherit')",
			$types
		);
		if ( $ids ) {
			$ids = array_filter( array_map( absint(...), (array) $ids ) );
			$sql .= ' AND ID IN (' . implode( ',', $ids ?: [ 0 ] ) . ')';
		}

		$sql .= ' ORDER BY ID ASC';
		if ( $limit ) {
			$sql .= ' LIMIT ' . absint( $limit );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * Top-level ACF fields that apply to a post, keyed by field name.
	 */
	public function fields_for_post( $post_id, $post_type ) {
		$groups = array_merge(
			(array) acf_get_field_groups( [ 'post_id' => $post_id ] ),
			(array) acf_get_field_groups( [ 'post_type' => $post_type ] )
		);
		$keys = [];
		foreach ( $groups as $g ) {
			if ( ! empty( $g['key'] ) ) {
				$keys[ $g['key'] ] = $g;
			}
		}

		ksort( $keys );
		$sig = $post_type . '|' . implode( ',', array_keys( $keys ) );

		if ( ! isset( $this->field_cache[ $sig ] ) ) {
			$fields = [];
			foreach ( $keys as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( empty( $field['name'] ) || in_array( $field['type'], self::SKIP_TYPES, true ) ) {
						continue;
					}

                    $fields[ $field['name'] ] ??= $field;
				}
			}

			ksort( $fields );
			$this->field_cache[ $sig ] = $fields;
		}

		return $this->field_cache[ $sig ];
	}

	/* ------------------------------------------------------------------ postmeta backup / restore */

	/**
	 * Raw postmeta rows that belong to a field (value + _key reference, and sub-field rows for complex fields).
	 */
	public function meta_rows_for_field( $post_id, $name, $complex ) {
		global $wpdb;
		[$where, $params] = $this->meta_where( $name, $complex );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND {$where} ORDER BY meta_id ASC",
				array_merge( [ $post_id ], $params )
			),
			ARRAY_N
		);
	}

	public function delete_meta_rows_for_field( $post_id, $name, $complex ) {
		global $wpdb;
		[$where, $params] = $this->meta_where( $name, $complex );

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND {$where}",
				array_merge( [ $post_id ], $params )
			)
		);
	}

	/** @return array [sql fragment with placeholders, params] */
	private function meta_where( $name, $complex ): array {
		global $wpdb;
		$sql    = '(meta_key = %s OR meta_key = %s';
		$params = [ $name, '_' . $name ];
		if ( $complex ) {
			$like     = $wpdb->esc_like( $name ) . '\\_%';
			$sql     .= ' OR meta_key LIKE %s OR meta_key LIKE %s';
			$params[] = $like;
			$params[] = '\\_' . $like;
		}

		return [ $sql . ')', $params ];
	}

	/* ------------------------------------------------------------------ output */

	/**
	 * Base output dir. Defaults to one level ABOVE the web root so snapshots/backups are not web-accessible;
	 * falls back to wp-content if that isn't writable. Override with --dir or ACFCDT_MIGRATION_DIR.
	 */
	public static function out_dir( $override = null, $sub = '' ): string {
		if ( $override ) {
			$base = $override;
		} elseif ( defined( 'ACFCDT_MIGRATION_DIR' ) ) {
			$base = ACFCDT_MIGRATION_DIR;
		} else {
			$parent = dirname( untrailingslashit( ABSPATH ) );
			$base   = is_writable( $parent ) ? $parent . '/acfcdt-migration' : WP_CONTENT_DIR . '/acfcdt-migration';
		}

		$dir = untrailingslashit( $base ) . ( $sub ? '/' . trim( $sub, '/' ) : '' );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			\WP_CLI::error( "Cannot create output directory: $dir" );
		}

		return $dir;
	}
}
