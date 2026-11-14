<?php

declare(strict_types=1);

namespace UAMSWP_ACFCDT_Migrator;

use ACFCustomDatabaseTables\Core;

/**
 * Switches ACF Custom Database Tables' intercepts off and on inside a single PHP process.
 *
 * Attached  = "effective" mode: ACF reads go through the plugin, i.e. what the live site shows today.
 * Detached  = "meta" mode:      ACF reads/writes hit core postmeta only, i.e. what the site will show
 *                               once the plugin is deactivated.
 *
 * Works by finding every callback registered on any hook whose object lives in the
 * ACFCustomDatabaseTables\ namespace, and removing / re-adding them.
 */
class HookGuard
{
    const PLUGIN_NS = 'ACFCustomDatabaseTables\\';

    /** @var array[] [hook, callable, priority, accepted_args] */
    private array $callbacks = [];

    /** @var object[] ACFGetFieldIntercept instances (have per-request caches we need to clear). */
    private array $get_intercepts = [];

    private bool $detached = false;

    public function __construct()
    {
        $this->collect();
    }

    private function collect(): void
    {
        global $wp_filter;

        foreach ($wp_filter as $hook => $wp_hook) {
            if (! ($wp_hook instanceof \WP_Hook)) {
                continue;
            }

            foreach ($wp_hook->callbacks as $priority => $cbs) {
                foreach ($cbs as $cb) {
                    $fn = $cb['function'];
                    if (! is_array($fn) || ! is_object($fn[0])) {
                        continue;
                    }

                    $class = $fn[0]::class;
                    if (! str_starts_with($class, self::PLUGIN_NS)) {
                        continue;
                    }

                    $this->callbacks[] = [$hook, $fn, $priority, $cb['accepted_args']];

                    if (str_ends_with($class, 'ACFGetFieldIntercept') && method_exists($fn[0], 'reset')) {
                        $this->get_intercepts[spl_object_hash($fn[0])] = $fn[0];
                    }
                }
            }
        }
    }

    public static function plugin_loaded(): bool
    {
        return function_exists('acf_custom_database_tables') || class_exists(Core::class, false);
    }

    public function has_intercepts(): bool
    {
        return $this->callbacks !== [];
    }

    public function hook_names(): array
    {
        return array_values(array_unique(array_map(fn (array $c) => $c[0], $this->callbacks)));
    }

    public function callback_count(): int
    {
        return count($this->callbacks);
    }

    public function is_detached(): bool
    {
        return $this->detached;
    }

    /** Switch to meta mode. */
    public function detach(): void
    {
        if ($this->detached) {
            return;
        }

        foreach ($this->callbacks as $c) {
            remove_filter($c[0], $c[1], $c[2]);
        }

        $this->detached = true;
        $this->reset_caches();
    }

    /** Switch back to effective mode. */
    public function attach(): void
    {
        if (! $this->detached) {
            return;
        }

        foreach ($this->callbacks as $c) {
            add_filter($c[0], $c[1], $c[2], $c[3]);
        }

        $this->detached = false;
        $this->reset_caches();
    }

    /**
     * Clears ACF's value store and the plugin's in-memory "already handled" bookkeeping so the next read
     * really goes to the source for the current mode. Call between posts and on every mode switch.
     */
    public function reset_caches(): void
    {
        foreach ($this->get_intercepts as $intercept) {
            $intercept->reset();
        }

        self::flush_acf_values();
    }

    public static function flush_acf_values(): void
    {
        if (function_exists('acf_get_store')) {
            $store = acf_get_store('values');
            if ($store) {
                $store->reset();
            }
        }
    }
}
