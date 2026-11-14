<?php

namespace UAMSWP_ACFCDT_Migrator;

/**
 * wp-admin front end for hosts without WP-CLI.
 *
 *  - Each site:        Tools → ACFCDT Migration   (does the work for that site)
 *  - Network admin:    Settings → ACFCDT Migration (overview + links to each site)
 *
 * Long jobs run as a chain of short AJAX requests (one batch of posts each). Every batch is safe to
 * retry, progress is saved after each one, and an interrupted job can be resumed from the page.
 */
class Admin {

	const SLUG   = 'uamswp-acfcdt-migration';

	const ACTION = 'uamswp_acfcdt_mig';

	const NONCE  = 'uamswp_acfcdt_mig';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'network_admin_menu', [ self::class, 'network_menu' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'ajax' ] );
		add_action( 'admin_post_' . self::ACTION . '_dl', [ self::class, 'download' ] );
	}

	public static function can() {
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}

	public static function menu(): void {
		if ( self::can() ) {
			add_management_page( 'ACFCDT Migration', 'ACFCDT Migration', is_multisite() ? 'manage_network_options' : 'manage_options', self::SLUG, [ self::class, 'page' ] );
		}
	}

	public static function network_menu(): void {
		add_submenu_page( 'settings.php', 'ACFCDT Migration', 'ACFCDT Migration', 'manage_network_options', self::SLUG, [ self::class, 'network_page' ] );
	}

	private static function page_url( $blog_id = null ) {
		$base = $blog_id ? get_admin_url( $blog_id, 'tools.php' ) : admin_url( 'tools.php' );

		return add_query_arg( 'page', self::SLUG, $base );
	}

	private static function dl_url( array $args ) {
		return wp_nonce_url( add_query_arg( array_merge( [ 'action' => self::ACTION . '_dl' ], $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/* ================================================================== network overview */

	public static function network_page(): void {
		if ( ! self::can() ) {
			wp_die( 'Not allowed.' );
		}
		echo '<div class="wrap"><h1>ACFCDT Migration — network overview</h1>';
		echo '<p>Each site is migrated from its own <strong>Tools → ACFCDT Migration</strong> screen. Only sites that have the custom tables are listed.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Site</th><th>Tables</th><th>Snapshots</th><th>Latest backfill</th><th></th></tr></thead><tbody>';
		$any = false;
		foreach ( get_sites( [ 'number' => 0, 'deleted' => 0 ] ) as $s ) {
			switch_to_blog( $s->blog_id );
			$site   = new Site();
			$tables = array_keys( $site->active_defs() );
			if ( $tables !== [] ) {
				$any   = true;
				Store::install();
				$snaps = Store::headers( 'snapinfo' );
				$runs  = Store::headers( 'run' );
				$latest = '—';
				foreach ( $runs as $ref => $r ) {
					if ( empty( $r['dry'] ) ) {
						$latest = esc_html( $ref . ' (' . ( $r['status'] ?? '?' ) . ( isset( $r['stats']['fields'] ) ? ', ' . (int) $r['stats']['fields'] . ' fields' : '' ) . ( ! empty( $r['stats']['write_mismatch'] ) ? ', ' . (int) $r['stats']['write_mismatch'] . ' MISMATCHES' : '' ) . ')' );
						break;
					}
				}
				$snap_list = [];
				foreach ( $snaps as $ref => $i ) {
					$snap_list[] = esc_html( $ref ) . ( ( $i['status'] ?? '' ) === 'complete' ? '' : ' <em>(incomplete)</em>' );
				}
				printf(
					'<tr><td><strong>%d</strong> %s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button" href="%s">Open</a></td></tr>',
					(int) $s->blog_id, esc_html( $s->domain . $s->path ), esc_html( implode( ', ', $tables ) ),
					$snap_list !== [] ? implode( ', ', $snap_list ) : '—', $latest, esc_url( self::page_url( $s->blog_id ) )
				);
			}
			restore_current_blog();
		}
		if ( ! $any ) {
			echo '<tr><td colspan="5">No site has ACF Custom Database Tables tables.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ================================================================== site page */

	public static function page(): void {
		if ( ! self::can() ) {
			wp_die( 'Not allowed.' );
		}
		if ( ! Engine::acf_ready() ) {
			echo '<div class="wrap"><h1>ACFCDT Migration</h1><div class="notice notice-error"><p>ACF is not active on this site.</p></div></div>';

			return;
		}
		$engine = new Engine();
		$inv    = $engine->inventory();
		$snaps  = Store::headers( 'snapinfo' );
		$runs   = Store::headers( 'run' );
		$rbs    = Store::headers( 'rollback' );
		$has_tables = (bool) array_filter( $inv['tables'], fn(array $t): bool => ! empty( $t['exists'] ) );
		$complete = array_keys( array_filter( $snaps, fn(array $i): bool => ( $i['status'] ?? '' ) === 'complete' ) );
		?>
		<div class="wrap acfm">
			<h1>ACFCDT Migration — site <?php echo (int) $inv['blog_id']; ?> <small><?php echo esc_html( $inv['url'] ); ?></small></h1>

			<?php if ( ! $has_tables ) : ?>
				<div class="notice notice-info"><p>This site has no ACF Custom Database Tables tables. Nothing to migrate here.</p></div>
			<?php endif; ?>

			<?php foreach ( $inv['cache_problems'] as $problem ) : ?>
				<div class="notice notice-error"><p><strong>Plugin is not reading the custom table:</strong> <?php echo esc_html( $problem ); ?></p></div>
			<?php endforeach; ?>

			<div class="acfm-status">
				<span>Plugin: <strong><?php echo $inv['plugin_loaded'] ? 'active' : 'not active'; ?></strong> (<?php echo (int) $inv['intercepts']; ?> intercepts)</span>
				<span>Values also in postmeta: <strong><?php echo $inv['core_meta'] ? 'yes' : 'NO — custom table is the only copy'; ?></strong></span>
				<span>Table JSON: <code><?php echo esc_html( $inv['json_dir'] ); ?></code></span>
			</div>

			<ol class="acfm-steps">
				<li>Take a full database backup in the host's control panel.</li>
				<li>Download the archive files below (keep them with the backup).</li>
				<li>Snapshot <code>before</code> — mode <em>effective</em>, plugin active.</li>
				<li>Backfill — <strong>dry run</strong> first, review, then the real run.</li>
				<li>Snapshot <code>after-meta</code> — mode <em>meta</em> — and compare with <code>before</code>: must be identical. A second dry run must report 0 changes.</li>
				<li>Network-deactivate ACF Custom Database Tables, snapshot <code>after-off</code>, compare with <code>before</code>: must be identical.</li>
			</ol>

			<!-- ============================================================ inventory -->
			<details class="acfm-box">
				<summary><h2>1. Inventory &amp; archive</h2></summary>
				<?php foreach ( $inv['tables'] as $t ) : ?>
					<h3><code><?php echo esc_html( $t['table'] ); ?></code> <small>post type <?php echo esc_html( $t['post_type'] ); ?></small></h3>
					<?php if ( empty( $t['exists'] ) ) : ?>
						<p>Table does not exist on this site.</p>
						<?php continue; endif; ?>
					<p>
						Rows: <strong><?php echo (int) $t['rows']; ?></strong> ·
						rows whose post no longer exists: <?php echo (int) $t['missing_post']; ?> ·
						rows for other post types: <?php echo (int) $t['wrong_type']; ?> ·
						<?php echo esc_html( $t['post_type'] ); ?> posts with no row: <?php echo (int) $t['no_row']; ?>
					</p>
					<?php $cs = $inv['cache'][ $t['name'] ] ?? null; ?>
					<?php if ( $cs ) : ?>
						<p>
							Table map cache: post type <code><?php echo esc_html( $cs['cache_post_type'] ?: '(none)' ); ?></code> —
							<?php echo $cs['intercepting'] ? '<span class="acfm-ok">plugin is reading this table</span>' : '<span class="acfm-bad">plugin is NOT reading this table (site shows postmeta)</span>'; ?>
							<?php if ( $cs['intercepting'] && $cs['not_cached'] ) : ?>
								<br><span class="description"><?php echo count( $cs['not_cached'] ); ?> mapped column(s) missing from the cache are served from postmeta: <?php echo esc_html( implode( ', ', $cs['not_cached'] ) ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<p>
						<a class="button" href="<?php echo esc_url( self::dl_url( [ 'type' => 'archive', 'table' => $t['name'] ] ) ); ?>">Download all rows (JSONL)</a>
						<?php if ( $t['orphans'] ) : ?>
							<a class="button" href="<?php echo esc_url( self::dl_url( [ 'type' => 'orphans', 'table' => $t['name'] ] ) ); ?>">Download orphaned columns (CSV)</a>
							<span class="description">Orphaned (archived, not migrated): <?php echo esc_html( implode( ', ', $t['orphans'] ) ); ?></span>
						<?php endif; ?>
					</p>
					<table class="widefat striped acfm-compact">
						<thead><tr><th>Column</th><th>ACF name</th><th>Type</th><th>Non-empty</th><th>Note</th></tr></thead>
						<tbody>
						<?php foreach ( $t['columns'] as $c ) : ?>
							<tr<?php echo $c['non_empty'] ? '' : ' class="acfm-dim"'; ?>>
								<td><?php echo esc_html( $c['column'] ); ?></td><td><?php echo esc_html( $c['acf_name'] ); ?></td>
								<td><?php echo esc_html( $c['acf_type'] ); ?></td><td><?php echo (int) $c['non_empty']; ?></td><td><?php echo esc_html( $c['note'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
			</details>

			<!-- ============================================================ snapshot -->
			<div class="acfm-box">
				<h2>2. Snapshots</h2>
				<p class="description">Records every top-level ACF value for every provider/location post. <em>effective</em> = read through the plugin (what the site shows); <em>meta</em> = read postmeta with the plugin bypassed (what the site will show without it).</p>
				<p>
					<label>Name <input type="text" id="acfm-snap-label" value="<?php echo esc_attr( isset( $snaps['before'] ) ? ( isset( $snaps['after-meta'] ) ? 'after-off' : 'after-meta' ) : 'before' ); ?>" pattern="[A-Za-z0-9_.\-]+"></label>
					<label>Mode <select id="acfm-snap-mode"><option value="effective">effective</option><option value="meta"<?php selected( isset( $snaps['before'] ) && ! isset( $snaps['after-meta'] ) && $inv['plugin_loaded'] ); ?>>meta</option></select></label>
					<label><input type="checkbox" id="acfm-snap-overwrite"> overwrite if it exists</label>
					<button class="button button-primary" id="acfm-snap-go" <?php disabled( ! $has_tables ); ?>>Take snapshot</button>
				</p>
				<?php self::header_table( 'snapshot', $snaps, $engine ); ?>
			</div>

			<!-- ============================================================ backfill -->
			<div class="acfm-box">
				<h2>3. Backfill postmeta</h2>
				<p class="description">For each field where the plugin's value differs from postmeta: back up the postmeta rows, write the plugin's value into postmeta, read it back to confirm. Custom tables are never modified. Safe to re-run.</p>
				<p>
					<label><input type="checkbox" id="acfm-bf-dry" checked> Dry run (write nothing)</label>
					<label><input type="checkbox" id="acfm-bf-noclear"> Don't clear stale postmeta where the plugin serves an empty value</label>
					<?php if ( $inv['cache_problems'] ) : ?>
						<label class="acfm-bad"><input type="checkbox" id="acfm-bf-stale"> Real run anyway — I've decided postmeta is the current copy</label>
					<?php endif; ?>
					<label>Posts per request <input type="number" id="acfm-bf-limit" value="10" min="1" max="100" style="width:5em"></label>
					<button class="button button-primary" id="acfm-bf-go" <?php disabled( ! $has_tables || ! $inv['plugin_loaded'] ); ?>>Run backfill</button>
					<?php if ( ! $inv['plugin_loaded'] ) : ?><span class="description">Needs ACF Custom Database Tables active.</span><?php endif; ?>
				</p>
				<?php self::header_table( 'run', $runs, $engine, $rbs ); ?>
			</div>

			<!-- ============================================================ compare -->
			<div class="acfm-box">
				<h2>4. Compare snapshots</h2>
				<p>
					<select id="acfm-cmp-a"><?php foreach ( $complete as $r ) { printf( '<option%s>%s</option>', selected( $r, 'before', false ), esc_html( $r ) ); } ?></select>
					vs
					<select id="acfm-cmp-b"><?php foreach ( $complete as $i => $r ) { printf( '<option%s>%s</option>', selected( $i, 0, false ), esc_html( $r ) ); } ?></select>
					<label>Ignore fields <input type="text" id="acfm-cmp-ignore" placeholder="comma,separated"></label>
					<button class="button button-primary" id="acfm-cmp-go" <?php disabled( count( $complete ) < 2 ); ?>>Compare</button>
				</p>
				<div id="acfm-cmp-out"></div>
			</div>

			<!-- ============================================================ progress -->
			<div class="acfm-box" id="acfm-progress" hidden>
				<h2 id="acfm-progress-title">Working…</h2>
				<progress id="acfm-bar" max="100" value="0"></progress> <span id="acfm-count"></span>
				<p><strong>Keep this tab open until it finishes.</strong> If it stops, use <em>Resume</em> — finished batches are kept.</p>
				<p><button class="button" id="acfm-resume" hidden>Resume</button> <button class="button" id="acfm-reload" hidden onclick="location.reload()">Reload page</button></p>
				<pre id="acfm-log"></pre>
			</div>
		</div>
		<?php
		self::styles();
		self::script();
	}

	/** Snapshot list or backfill-run list with actions. */
	private static function header_table( string $type, array $items, Engine $engine, array $rollbacks = [] ): void {
		if ( $items === [] ) {
			echo '<p><em>None yet.</em></p>';

			return;
		}
		if ( $type === 'snapshot' ) {
			echo '<table class="widefat striped acfm-compact"><thead><tr><th>Name</th><th>Mode</th><th>Status</th><th>Posts</th><th>Started (UTC)</th><th></th></tr></thead><tbody>';
			foreach ( $items as $ref => $i ) {
				$status   = $i['status'] ?? '?';
				$posts    = Store::count( 'snapshot', $ref );
				$progress = $status === 'complete' ? $posts : $engine->posts_upto( $i['cursor'] ?? 0 ) . ' / ' . ( isset( $i['total'] ) ? (int) $i['total'] : '?' );
				printf(
					'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s <a class="button button-small" href="%7$s">Download</a> <button class="button button-small button-link-delete" data-acfm-delete="snapshot" data-ref="%1$s">Delete</button></td></tr>',
					esc_html( $ref ), esc_html( $i['mode'] ?? '' ), esc_html( $status ), esc_html( $progress ), esc_html( $i['started'] ?? '' ),
					$status === 'complete' ? '' : sprintf( '<button class="button button-small" data-acfm-resume="snapshot" data-ref="%s" data-cursor="%d" data-total="%d" data-done="%d">Resume</button>', esc_attr( $ref ), (int) ( $i['cursor'] ?? 0 ), (int) ( $i['total'] ?? 0 ), $engine->posts_upto( $i['cursor'] ?? 0 ) ),
					esc_url( self::dl_url( [ 'type' => 'snapshot', 'ref' => $ref ] ) )
				);
			}
			echo '</tbody></table>';

			return;
		}

		echo '<table class="widefat striped acfm-compact"><thead><tr><th>Run</th><th>Type</th><th>Status</th><th>Posts / fields changed</th><th>fill / overwrite / clear</th><th>Mismatches</th><th>Rollback</th><th></th></tr></thead><tbody>';
		foreach ( $items as $ref => $r ) {
			$status = $r['status'] ?? '?';
			$sum    = isset( $r['stats'] ) ? $r : Store::change_summary( $ref );
			$st     = $sum['stats'];
			$rb     = $rollbacks[ $ref ] ?? null;
			$rb_txt = $rb ? esc_html( $rb['status'] ) : '—';
			$actions = sprintf( '<a class="button button-small" href="%s">Changes</a> ', esc_url( self::dl_url( [ 'type' => 'changes', 'ref' => $ref ] ) ) );
			if ( $status !== 'complete' ) {
				$actions .= sprintf( '<button class="button button-small" data-acfm-resume="backfill" data-ref="%s" data-cursor="%d" data-total="%d" data-done="%d">Resume</button> ', esc_attr( $ref ), (int) ( $r['cursor'] ?? 0 ), (int) $r['total'], $engine->posts_upto( $r['cursor'] ?? 0 ) );
			}
			if ( empty( $r['dry'] ) ) {
				$actions .= sprintf( '<a class="button button-small" href="%s">Backups</a> ', esc_url( self::dl_url( [ 'type' => 'backups', 'ref' => $ref ] ) ) );
				if ( ! $rb || $rb['status'] !== 'complete' ) {
					$actions .= sprintf( '<button class="button button-small" data-acfm-rollback="%1$s"%2$s>%3$s</button> ', esc_attr( $ref ),
						$rb ? sprintf( ' data-cursor="%d"', (int) ( $rb['cursor'] ?? 0 ) ) : '', $rb ? 'Resume rollback' : 'Roll back' );
				}
			}
			$actions .= sprintf( '<button class="button button-small button-link-delete" data-acfm-delete="run" data-ref="%s">Delete</button>', esc_attr( $ref ) );
			printf(
				'<tr><td><code>%s</code><br><small>%s</small></td><td>%s</td><td>%s</td><td>%d / %d</td><td>%d / %d / %d%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $ref ), esc_html( $r['started'] ?? '' ), empty( $r['dry'] ) ? '<strong>REAL</strong>' : 'dry run', esc_html( $status ),
				(int) $st['posts'], (int) $st['fields'], (int) $st['fill'], (int) $st['overwrite'], (int) $st['clear'], ( $st['skipped'] ? ' (' . (int) $st['skipped'] . ' skipped)' : '' ) . ( ! empty( $st['invalid'] ) ? ' <strong style="color:#b32d2e">' . (int) $st['invalid'] . ' invalid — see Changes</strong>' : '' ),
				$st['write_mismatch'] ? '<strong style="color:#b32d2e">' . (int) $st['write_mismatch'] . '</strong>' : '0', $rb_txt, $actions
			);
			if ( ! empty( $sum['by_field'] ) && $status === 'complete' ) {
				$cells = '';
				foreach ( $sum['by_field'] as $f ) {
					$cells .= sprintf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td></tr>', esc_html( $f['field'] ), $f['fill'], $f['overwrite'], $f['clear'] );
				}
				printf( '<tr class="acfm-sub"><td colspan="8"><details><summary>By field</summary><table class="acfm-compact"><thead><tr><th>Field</th><th>fill</th><th>overwrite</th><th>clear</th></tr></thead><tbody>%s</tbody></table></details></td></tr>', $cells );
			}
		}
		echo '</tbody></table>';
	}

	/* ================================================================== AJAX */

	public static function ajax(): void {
		if ( ! self::can() || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Not allowed (permission or expired nonce — reload the page).' ], 403 );
		}
		@set_time_limit( 120 );
		ignore_user_abort( true ); // finish the batch even if the browser goes away

		$p  = wp_unslash( $_POST );
		$op = isset( $p['op'] ) ? sanitize_key( $p['op'] ) : '';
		try {
			if ( ! Engine::acf_ready() ) {
				throw new \RuntimeException( 'ACF is not active.' );
			}
			$engine = new Engine();
			$ref    = isset( $p['ref'] ) ? Engine::clean_ref( $p['ref'] ) : '';
			$cursor = isset( $p['cursor'] ) ? (int) $p['cursor'] : 0;
			$limit  = isset( $p['limit'] ) ? max( 1, min( 200, (int) $p['limit'] ) ) : 10;

			switch ( $op ) {
				case 'snapshot_start':
					$ref = $engine->snapshot_start( $p['label'], $p['mode'], ! empty( $p['overwrite'] ) );
					wp_send_json_success( [ 'ref' => $ref, 'total' => $engine->total_posts() ] );
				case 'snapshot_batch':
					wp_send_json_success( $engine->snapshot_batch( $ref, $cursor, $limit ) );
				case 'backfill_start':
					$ref = $engine->backfill_start( ! empty( $p['dry'] ), ! empty( $p['no_clear'] ), null, ! empty( $p['allow_stale'] ) );
					wp_send_json_success( [ 'ref' => $ref, 'total' => $engine->total_posts() ] );
				case 'backfill_batch':
					wp_send_json_success( $engine->backfill_batch( $ref, $cursor, $limit ) );
				case 'rollback_start':
					$engine->rollback_start( $ref, true );
					wp_send_json_success( [ 'ref' => $ref, 'total' => Store::count( 'backup', $ref ) ] );
				case 'rollback_batch':
					wp_send_json_success( $engine->rollback_batch( $ref, $cursor, $limit ) );
				case 'compare':
					$ignore = array_filter( array_map( trim(...), explode( ',', $p['ignore'] ?? '' ) ) );
					$res    = $engine->compare( $p['a'], $p['b'], $ignore );
					$total  = count( $res['diffs'] );
					$res['diffs'] = array_slice( $res['diffs'], 0, 200 );
					foreach ( $res['diffs'] as &$d ) {
						$d['a'] = self::preview( $d['a'] );
						$d['b'] = self::preview( $d['b'] );
					}
					$res['total_diffs'] = $total;
					$res['download']    = self::dl_url( [ 'type' => 'compare', 'a' => $p['a'], 'b' => $p['b'], 'ignore' => $p['ignore'] ?? '' ] );
					wp_send_json_success( $res );
				case 'delete':
					$kinds = $p['kind'] === 'snapshot' ? [ 'snapinfo', 'snapshot' ] : [ 'run', 'change', 'backup', 'rollback' ];
					if ( $p['kind'] === 'run' ) {
						$run = Store::get( 'run', $ref );
						$rb  = Store::get( 'rollback', $ref );
						if ( $run && empty( $run['dry'] ) && ( ! $rb || $rb['status'] !== 'complete' ) && empty( $p['confirm_backups'] ) ) {
							throw new \RuntimeException( 'This real run still holds the only per-field backups of the old postmeta. Download them first, then confirm deletion.' );
						}
					}
					Store::delete( $kinds, $ref );
					wp_send_json_success( [ 'deleted' => $ref ] );
				default:
					throw new \InvalidArgumentException( 'Unknown operation.' );
			}
		} catch ( \Throwable $throwable ) {
			wp_send_json_error( [ 'message' => $throwable->getMessage() ] );
		}
	}

	private static function preview( $v ) {
		$s = $v === null ? '(empty)' : ( is_string( $v ) ? $v : Normalizer::json( $v ) );

		return mb_strlen( $s ) > 300 ? mb_substr( $s, 0, 297 ) . '…' : $s;
	}

	/* ================================================================== downloads */

	public static function download(): void {
		if ( ! self::can() ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( self::NONCE );
		$g    = wp_unslash( $_GET );
		$type = isset( $g['type'] ) ? sanitize_key( $g['type'] ) : '';
		$blog = get_current_blog_id();
		@set_time_limit( 300 );

		try {
			$engine = new Engine();
			switch ( $type ) {
				case 'archive':
				case 'orphans':
					$defs = $engine->site->active_defs();
					$name = $g['table'] ?? '';
					if ( ! isset( $defs[ $name ] ) ) {
						throw new \RuntimeException( 'Unknown table.' );
					}
					$table = $engine->site->prefixed( $name );
					if ( $type === 'archive' ) {
						self::headers( "site-$blog-$table.jsonl", 'application/x-ndjson' );
						$engine->each_table_row( $name, function ( $row ): void {
							echo wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
						} );
					} else {
						$orphans = $engine->orphan_columns( $defs[ $name ] );
						self::headers( "site-$blog-$table-orphan-columns.csv", 'text/csv' );
						$out = fopen( 'php://output', 'w' );
						fputcsv( $out, array_merge( [ 'post_id' ], $orphans ) );
						$engine->each_table_row( $name, function ( $row ) use ( $out, $orphans ): void {
							$vals = [];
							$any  = false;
							foreach ( $orphans as $c ) {
								$v      = $row[ $c ] ?? '';
								$vals[] = $v;
								$any    = $any || ( !in_array($v, [null, '', '[]'], true) );
							}
							if ( $any ) {
								fputcsv( $out, array_merge( [ $row['post_id'] ], $vals ) );
							}
						} );
						fclose( $out );
					}
					exit;

				case 'snapshot':
				case 'changes':
				case 'backups':
					$ref  = Engine::clean_ref( $g['ref'] );
					$kind = $type === 'snapshot' ? 'snapshot' : ( $type === 'changes' ? 'change' : 'backup' );
					self::headers( "site-$blog-$type-$ref.jsonl", 'application/x-ndjson' );
					$after = 0;
					do {
						$rows = Store::rows( $kind, $ref, $after, 500 );
						foreach ( $rows as $r ) {
							echo $r->data, "\n";
							$after = (int) $r->id;
						}
					} while ( count( $rows ) === 500 );
					exit;

				case 'compare':
					$ignore = array_filter( array_map( trim(...), explode( ',', $g['ignore'] ?? '' ) ) );
					$res    = $engine->compare( $g['a'], $g['b'], $ignore );
					self::headers( "site-$blog-compare-{$g['a']}__vs__{$g['b']}.jsonl", 'application/x-ndjson' );
					echo Normalizer::json( [ 'summary' => $res['summary'] ] ), "\n";
					foreach ( $res['diffs'] as $d ) {
						echo Normalizer::json( $d ), "\n";
					}
					exit;
			}
			throw new \RuntimeException( 'Unknown download.' );
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html( $throwable->getMessage() ) );
		}
	}

	private static function headers( string $filename, string $type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/* ================================================================== assets */

	private static function styles(): void {
		?>
		<style>
			.acfm .acfm-box{background:#fff;border:1px solid #c3c4c7;padding:4px 16px 12px;margin:16px 0;max-width:1400px}
			.acfm details.acfm-box > summary{cursor:pointer;list-style:revert}
			.acfm details.acfm-box > summary h2{display:inline}
			.acfm .acfm-status{display:flex;gap:24px;flex-wrap:wrap;margin:8px 0}
			.acfm .acfm-steps{background:#f6f7f7;padding:8px 8px 8px 32px;max-width:1400px}
			.acfm .acfm-compact td,.acfm .acfm-compact th{padding:4px 8px}
			.acfm .acfm-dim td{color:#8c8f94}
			.acfm label{margin-right:12px}
			.acfm progress{width:min(600px,100%);height:18px;vertical-align:middle}
			.acfm #acfm-log{max-height:260px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:8px;font-size:12px;white-space:pre-wrap}
			.acfm .acfm-ok{color:#008a20;font-weight:600}.acfm .acfm-bad{color:#b32d2e;font-weight:600}
			.acfm td code{white-space:nowrap}
			.acfm .acfm-diff td{max-width:420px;word-break:break-word;font-family:monospace;font-size:12px}
		</style>
		<?php
	}

	private static function script(): void {
		$cfg = [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'act'   => self::ACTION,
		];
		?>
		<script>
		(function () {
			const C = <?php echo wp_json_encode( $cfg ); ?>;
			const $ = (id) => document.getElementById(id);
			const log = (m) => { const l = $('acfm-log'); l.textContent += m + "\n"; l.scrollTop = l.scrollHeight; };
			let job = null; // {batchOp, ref, cursor, total, done, limit, label}

			async function call(op, data, tries = 4) {
				const body = new FormData();
				body.append('action', C.act); body.append('nonce', C.nonce); body.append('op', op);
				Object.entries(data || {}).forEach(([k, v]) => body.append(k, v));
				let lastErr;
				for (let i = 0; i < tries; i++) {
					try {
						const r = await fetch(C.ajax, { method: 'POST', body, credentials: 'same-origin' });
						const text = await r.text();
						let j; try { j = JSON.parse(text); } catch (e) { throw new Error('HTTP ' + r.status + ' (not JSON — likely a timeout or PHP error)'); }
						if (!j.success) { const e = new Error((j.data && j.data.message) || 'Error'); e.fatal = true; throw e; }
						return j.data;
					} catch (e) {
						lastErr = e;
						if (e.fatal) throw e;
						log('  ! ' + e.message + ' — retrying (' + (i + 1) + '/' + (tries - 1) + ')…');
						await new Promise(res => setTimeout(res, 3000 * (i + 1)));
					}
				}
				throw lastErr;
			}

			function show(title) {
				$('acfm-progress').hidden = false; $('acfm-progress-title').textContent = title;
				$('acfm-resume').hidden = true; $('acfm-reload').hidden = true;
				$('acfm-progress').scrollIntoView({ behavior: 'smooth' });
			}
			function progress() {
				const pct = job.total ? Math.min(100, Math.round(job.done / job.total * 100)) : 0;
				$('acfm-bar').value = pct; $('acfm-count').textContent = job.done + ' / ' + job.total + ' (' + pct + '%)';
			}
			function busy(on) { document.querySelectorAll('.acfm button').forEach(b => { if (b.id !== 'acfm-resume' && b.id !== 'acfm-reload') b.disabled = on; }); }

			async function loop() {
				busy(true);
				try {
					while (true) {
						const r = await call(job.batchOp, { ref: job.ref, cursor: job.cursor, limit: job.limit });
						job.cursor = r.cursor; job.done += r.processed; progress();
						if (r.written) job.written = (job.written || 0) + r.written;
						if (r.mismatches) { job.mismatches = (job.mismatches || 0) + r.mismatches; (r.warnings || []).forEach(w => log('  ⚠ ' + w)); }
						if (r.done) break;
					}
					$('acfm-bar').value = 100;
					log('✔ ' + job.label + ' "' + job.ref + '" complete.' + (job.written !== undefined ? ' Fields written: ' + job.written + '.' : '') + (job.mismatches ? ' WRITE MISMATCHES: ' + job.mismatches : ''));
					$('acfm-progress-title').textContent = 'Done';
					$('acfm-reload').hidden = false;
				} catch (e) {
					log('✖ Stopped: ' + e.message + '  (cursor ' + job.cursor + ')');
					$('acfm-progress-title').textContent = 'Stopped — progress saved';
					$('acfm-resume').hidden = false; $('acfm-reload').hidden = false;
				}
				busy(false);
			}

			async function start(title, startOp, startData, batchOp, limit, label) {
				show(title); $('acfm-log').textContent = '';
				try {
					const s = await call(startOp, startData, 1);
					job = { batchOp, ref: s.ref, cursor: 0, total: s.total, done: 0, limit, label };
					log('Started ' + label + ' "' + s.ref + '" — ' + s.total + ' items.'); progress();
					await loop();
				} catch (e) { log('✖ ' + e.message); $('acfm-progress-title').textContent = 'Could not start'; $('acfm-reload').hidden = false; }
			}

			$('acfm-resume').addEventListener('click', () => { $('acfm-resume').hidden = true; log('Resuming from cursor ' + job.cursor + '…'); loop(); });

			const snapGo = $('acfm-snap-go');
			snapGo && snapGo.addEventListener('click', () => start('Snapshot', 'snapshot_start',
				{ label: $('acfm-snap-label').value.trim(), mode: $('acfm-snap-mode').value, overwrite: $('acfm-snap-overwrite').checked ? 1 : '' },
				'snapshot_batch', 25, 'Snapshot'));

			const bfGo = $('acfm-bf-go');
			bfGo && bfGo.addEventListener('click', () => {
				const dry = $('acfm-bf-dry').checked;
				if (!dry && !confirm('REAL backfill: this writes to postmeta on this site.\n\nHave you taken a database backup and reviewed a dry run?')) return;
				start(dry ? 'Backfill (dry run)' : 'Backfill', 'backfill_start', { dry: dry ? 1 : '', no_clear: $('acfm-bf-noclear').checked ? 1 : '', allow_stale: ($('acfm-bf-stale') && $('acfm-bf-stale').checked) ? 1 : '' },
					'backfill_batch', parseInt($('acfm-bf-limit').value, 10) || 10, dry ? 'Dry run' : 'Backfill');
			});

			document.querySelectorAll('[data-acfm-resume]').forEach(b => b.addEventListener('click', () => {
				const t = b.dataset.acfmResume;
				show('Resuming ' + t); $('acfm-log').textContent = '';
				job = { batchOp: t + '_batch', ref: b.dataset.ref, cursor: parseInt(b.dataset.cursor, 10) || 0, total: parseInt(b.dataset.total, 10) || 0,
					done: parseInt(b.dataset.done, 10) || 0, limit: t === 'snapshot' ? 25 : (parseInt($('acfm-bf-limit').value, 10) || 10), label: t };
				log('Resuming ' + t + ' "' + job.ref + '" from cursor ' + job.cursor + '…'); progress(); loop();
			}));

			document.querySelectorAll('[data-acfm-rollback]').forEach(b => b.addEventListener('click', async () => {
				const ref = b.dataset.acfmRollback;
				if (!confirm('Restore postmeta to exactly what it was before run "' + ref + '"?\n\nThe custom tables are not touched.')) return;
				if (b.dataset.cursor) {
					show('Resuming rollback'); job = { batchOp: 'rollback_batch', ref, cursor: parseInt(b.dataset.cursor, 10), total: 0, done: 0, limit: 100, label: 'Rollback' };
					return loop();
				}
				start('Rollback', 'rollback_start', { ref }, 'rollback_batch', 100, 'Rollback');
			}));

			document.querySelectorAll('[data-acfm-delete]').forEach(b => b.addEventListener('click', async () => {
				const kind = b.dataset.acfmDelete, ref = b.dataset.ref;
				if (!confirm('Delete ' + kind + ' "' + ref + '" from the migration log?')) return;
				try { await call('delete', { kind, ref }, 1); location.reload(); }
				catch (e) {
					if (confirm(e.message + '\n\nDelete anyway?')) { await call('delete', { kind, ref, confirm_backups: 1 }, 1); location.reload(); }
				}
			}));

			const cmpGo = $('acfm-cmp-go');
			cmpGo && cmpGo.addEventListener('click', async () => {
				const out = $('acfm-cmp-out'); out.innerHTML = '<p>Comparing…</p>';
				try {
					const r = await call('compare', { a: $('acfm-cmp-a').value, b: $('acfm-cmp-b').value, ignore: $('acfm-cmp-ignore').value }, 2);
					const s = r.summary, esc = (x) => String(x).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
					let h = '<p class="' + (s.identical ? 'acfm-ok' : 'acfm-bad') + '">' + (s.identical ? '✔ Identical' : '✖ ' + r.total_diffs + ' difference(s)') + '</p>';
					h += '<p>Posts: ' + s.posts_a + ' vs ' + s.posts_b + ' · only in A: ' + s.only_in_a + ' · only in B: ' + s.only_in_b + ' · posts with diffs: ' + s.posts_with_diffs + ' · fields differ: ' + s.fields_differ + '</p>';
					if (r.diffs.length) {
						h += '<p><a class="button" href="' + esc(r.download) + '">Download full report</a>' + (r.total_diffs > r.diffs.length ? ' (showing first ' + r.diffs.length + ')' : '') + '</p>';
						h += '<table class="widefat striped acfm-compact acfm-diff"><thead><tr><th>Post</th><th>Field</th><th>A</th><th>B</th></tr></thead><tbody>';
						r.diffs.forEach(d => { h += '<tr><td>' + d.post_id + ' ' + esc(d.title) + '</td><td>' + esc(d.field) + '</td><td>' + esc(d.a) + '</td><td>' + esc(d.b) + '</td></tr>'; });
						h += '</tbody></table>';
					}
					out.innerHTML = h;
				} catch (e) { out.innerHTML = '<p class="acfm-bad">' + e.message + '</p>'; }
			});
		})();
		</script>
		<?php
	}
}
