<?php

/**
 * The class whose presence tells `HookGuard::plugin_loaded()` the plugin is active.
 *
 * Kept apart from the other stand-ins: a class cannot be unloaded, so loading it must be the test's own step.
 */

declare(strict_types=1);

namespace ACFCustomDatabaseTables {
    class Core {}
}
