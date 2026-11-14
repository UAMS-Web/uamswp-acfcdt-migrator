<?php

/**
 * Stand-ins for the objects ACF Custom Database Tables registers as hook callbacks.
 *
 * `HookGuard` tells them apart by class name (the `ACFCustomDatabaseTables\` namespace, and a name ending in
 * `ACFGetFieldIntercept` for the ones with a cache to reset), so the names are what these must get right.
 */

declare(strict_types=1);

namespace ACFCustomDatabaseTables\Intercept {
    class ACFGetFieldIntercept
    {
        public int $resets = 0;

        public function reset(): void
        {
            $this->resets++;
        }

        public function filter(mixed $value): mixed
        {
            return $value;
        }
    }

    /** Ends in the same suffix as the get-field intercept but has no `reset()` method. */
    class NoResetACFGetFieldIntercept
    {
        public function filter(mixed $value): mixed
        {
            return $value;
        }
    }

    /** In the plugin's namespace, but not a get-field intercept. */
    class ACFUpdateFieldIntercept
    {
        public int $resets = 0;

        public function reset(): void
        {
            $this->resets++;
        }

        public function filter(mixed $value): mixed
        {
            return $value;
        }
    }
}

namespace Elsewhere {
    /** Outside the plugin's namespace, with a name that would match the suffix test. */
    class ACFGetFieldIntercept
    {
        public int $resets = 0;

        public function reset(): void
        {
            $this->resets++;
        }

        public function filter(mixed $value): mixed
        {
            return $value;
        }
    }
}
