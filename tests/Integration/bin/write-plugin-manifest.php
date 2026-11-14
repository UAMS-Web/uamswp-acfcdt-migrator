<?php

/**
 * Prints the manifest for the plugin stack in a plugins directory, as JSON.
 *
 * Run it deliberately when the stack is meant to change, and commit the result as
 * tests/Integration/plugin-stack.json:
 *
 *   php tests/Integration/bin/write-plugin-manifest.php [plugins-directory] > tests/Integration/plugin-stack.json
 *
 * The directory defaults to WP_TESTS_PLUGINS_DIR, then to the surrounding install's wp-content/plugins.
 */

declare(strict_types=1);

require_once __DIR__.'/../PluginStack.php';

use UAMSWP_ACFCDT_Migrator\Tests\Integration\PluginStack;

$directory = $argv[1] ?? (getenv('WP_TESTS_PLUGINS_DIR') ?: dirname(__DIR__, 4));

fwrite(STDERR, "Describing the plugin stack in {$directory}\n");

echo json_encode(['plugins' => PluginStack::describe($directory)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

// cspell:ignore fwrite getenv
