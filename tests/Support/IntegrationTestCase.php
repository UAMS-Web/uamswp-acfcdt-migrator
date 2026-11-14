<?php

/**
 * Base case for every Integration test.
 */

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator\Tests\Support;

use WP_UnitTest_Factory;
use WP_UnitTestCase;

/**
 * WordPress' test case, unchanged, on the PHPUnit 9.6 its test suite supports.
 *
 * Loaded by tests/Integration/bootstrap.php once WordPress is up; it cannot be
 * autoloaded earlier, because its parent does not exist until then.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    /**
     * The fixture factory, with a named return type for static analysis.
     */
    protected static function factory(): WP_UnitTest_Factory
    {
        return parent::factory();
    }
}

// cspell:ignore autoloaded
