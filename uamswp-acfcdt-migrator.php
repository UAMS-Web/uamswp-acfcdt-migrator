<?php

declare(strict_types=1);
use UAMSWP_ACFCDT_Migrator\Admin;
use UAMSWP_ACFCDT_Migrator\Command;

/**
 * Plugin Name: UAMSWP ACF Custom Database Tables Migrator
 * Plugin URI: https://github.com/UAMS-Web/uamswp-acfcdt-migrator
 * Description: Moves ACF values out of ACF Custom Database Tables into core postmeta with snapshot, verify and rollback. Use it from Tools → ACFCDT Migration (no WP-CLI needed) or via WP-CLI. Does nothing on the front end.
 * Version: 0.2.2
 * Author: UAMS Web Services
 * Author URI: https://communications.uams.edu/web/
 * Network: true
 * Requires PHP: 8.2
 */

// Guard against double-loading (e.g. network-activated AND passed via `wp --require`).
if (defined('UAMSWP_ACFCDT_MIGRATOR_FILE')) {
    return;
}

define('UAMSWP_ACFCDT_MIGRATOR_FILE', __FILE__);
// The header's Version is the only place the release number is written. `wp --require` runs before WordPress has
// loaded `get_file_data()`, and nothing reads the constant, so it is defined only where the function exists.
if (function_exists('get_file_data')) {
    define('UAMSWP_ACFCDT_MIGRATOR_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
}

require_once __DIR__.'/includes/class-hook-guard.php';
require_once __DIR__.'/includes/class-normalizer.php';
require_once __DIR__.'/includes/class-site.php';
require_once __DIR__.'/includes/class-store.php';
require_once __DIR__.'/includes/class-engine.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/includes/class-command.php';
    WP_CLI::add_command('acfcdt-migrate', Command::class);
}

// Admin screens (only when loaded as a normal plugin; `wp --require` runs before WordPress's hook API exists).
if (function_exists('add_action') && function_exists('is_admin')) {
    require_once __DIR__.'/includes/class-admin.php';
    if (is_admin()) {
        Admin::init();
    }
}
