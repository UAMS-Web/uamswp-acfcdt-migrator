<?php

declare(strict_types=1);
use UAMSWP_ACFCDT_Migrator\Loader;

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

require_once __DIR__.'/includes/class-loader.php';

Loader::load(__DIR__);
