<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

/**
 * The plugin's load-time branching, kept out of the main file so that a unit test can reach every branch.
 *
 * The main file holds the double-load guard, the two constants and one call into this class. What is loaded depends
 * on how the file was loaded: the WP-CLI command only when WP-CLI is running, and the admin screens only when
 * WordPress's hook API exists, because `wp --require` loads the file before that API is there.
 */
class Loader
{
    /**
     * @param  string  $dir  The plugin directory, without a trailing slash.
     */
    public static function load(string $dir): void
    {
        require_once $dir.'/includes/class-hook-guard.php';
        require_once $dir.'/includes/class-normalizer.php';
        require_once $dir.'/includes/class-site.php';
        require_once $dir.'/includes/class-store.php';
        require_once $dir.'/includes/class-engine.php';

        if (defined('WP_CLI') && constant('WP_CLI')) {
            require_once $dir.'/includes/class-command.php';
            \WP_CLI::add_command('acfcdt-migrate', Command::class);
        }

        // Admin screens (only when loaded as a normal plugin; `wp --require` runs before WordPress's hook API exists).
        if (function_exists('add_action') && function_exists('is_admin')) {
            require_once $dir.'/includes/class-admin.php';
            if (is_admin()) {
                Admin::init();
            }
        }
    }
}
