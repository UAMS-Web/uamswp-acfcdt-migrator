<?php

/**
 * The migrator inside the real plugin stack it works against, reading a real database.
 *
 * A class-based PHPUnit 9.6 test, run from tests/Integration's own Composer setup, because WordPress' test
 * suite supports PHPUnit 9 only.
 *
 * @see includes/class-store.php
 * @see tests/Integration/plugin-stack.json
 */

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator\Tests\Integration;

use ACFCustomDatabaseTables\Core;
use UAMSWP_ACFCDT_Migrator\Engine;
use UAMSWP_ACFCDT_Migrator\Store;
use UAMSWP_ACFCDT_Migrator\Tests\Support\IntegrationTestCase;

final class PluginStackTest extends IntegrationTestCase
{
    public function test_the_three_real_plugins_and_the_migrator_are_loaded(): void
    {
        $this->assertTrue(function_exists('acf_get_value'), 'ACF Pro is loaded.');
        $this->assertTrue(class_exists('ACFE', false), 'ACF Extended is loaded.');
        $this->assertTrue(class_exists(Core::class), 'ACF Custom Database Tables is loaded.');
        $this->assertTrue(class_exists(Engine::class, false), 'The migrator is loaded.');
    }

    public function test_reads_a_post_back_from_the_database(): void
    {
        global $wpdb;

        $postId = self::factory()->post->create(['post_title' => 'Read back from the test database']);

        $this->assertSame(
            'Read back from the test database',
            $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $postId))
        );
    }

    public function test_the_store_asks_the_database_whether_its_table_exists(): void
    {
        global $wpdb;

        $this->assertSame(
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(Store::table()))) === Store::table(),
            Store::exists()
        );
    }
}

// cspell:ignore ACFE
