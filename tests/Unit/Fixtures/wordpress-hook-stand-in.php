<?php

/**
 * A stand-in for WordPress' `WP_Hook`: only the `callbacks` property that `HookGuard` reads.
 */

declare(strict_types=1);

if (! class_exists('WP_Hook', false)) {
    class WP_Hook
    {
        /** @var array<int, array<int|string, array{function: mixed, accepted_args: int}>> */
        public array $callbacks = [];
    }
}
