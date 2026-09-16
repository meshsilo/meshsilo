<?php

declare(strict_types=1);

/**
 * Plugin Hook Dispatcher
 *
 * Owns the plugin hook registry (filters and actions) and their execution.
 * Extracted from PluginManager as a cohesive collaborator; PluginManager keeps
 * the public facade (addFilter/addAction/applyFilter/doAction) and delegates
 * here so hook execution order and error handling remain identical.
 *
 * Filters transform a value through a priority-ordered chain of callbacks.
 * Actions are fire-and-forget event listeners. Both isolate plugin errors so
 * one broken plugin cannot crash the page.
 */
class PluginHookDispatcher
{
    /** @var array<string, list<array{callback: callable, priority: int, plugin: string}>> */
    private array $filters = [];

    /** @var array<string, list<array{callback: callable, priority: int, plugin: string}>> */
    private array $actions = [];

    /**
     * Register a filter hook. Higher priority runs first (descending sort).
     * Note: this is the REVERSE of WordPress, where lower priority runs first.
     */
    public function addFilter(string $hook, callable $callback, int $priority, string $pluginId): void
    {
        $this->filters[$hook][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $pluginId];
        usort($this->filters[$hook], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register an action hook (event listener). Higher priority runs first
     * (the reverse of WordPress, where lower priority runs first).
     * Unlike filters, actions don't return/modify a value -- they just execute.
     */
    public function addAction(string $event, callable $callback, int $priority, string $pluginId): void
    {
        $this->actions[$event][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $pluginId];
        usort($this->actions[$event], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Unregister a filter callback. Returns true if at least one
     * registration was removed.
     */
    public function removeFilter(string $hook, callable $callback): bool
    {
        return $this->removeFromRegistry($this->filters, $hook, $callback);
    }

    /**
     * Unregister an action callback. Returns true if at least one
     * registration was removed.
     */
    public function removeAction(string $event, callable $callback): bool
    {
        return $this->removeFromRegistry($this->actions, $event, $callback);
    }

    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    public function hasAction(string $event): bool
    {
        return !empty($this->actions[$event]);
    }

    /**
     * @param array<string, list<array{callback: callable, priority: int, plugin: string}>> $registry
     */
    private function removeFromRegistry(array &$registry, string $name, callable $callback): bool
    {
        $removed = false;
        foreach ($registry[$name] ?? [] as $i => $entry) {
            if ($entry['callback'] === $callback) {
                unset($registry[$name][$i]);
                $removed = true;
            }
        }
        if ($removed) {
            $registry[$name] = array_values($registry[$name]);
            if (empty($registry[$name])) {
                unset($registry[$name]);
            }
        }
        return $removed;
    }

    /**
     * Run a value through the registered filter chain and return the result.
     * A broken plugin is logged and skipped with the value left unmodified.
     */
    public function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (empty($this->filters[$hook])) {
            return $value;
        }

        foreach ($this->filters[$hook] as $filter) {
            try {
                $value = ($filter['callback'])($value, ...$args);
            } catch (\Throwable $e) {
                logError("Plugin filter error on '{$hook}'", [
                    'plugin' => $filter['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                // Continue with unmodified value -- don't let one broken plugin crash the page
            }
        }

        return $value;
    }

    /**
     * Run an allow/deny gate through the registered filter chain.
     *
     * Gate contract (matches the historical before_download/before_delete
     * filter usage): the operation is allowed only when the final value is
     * exactly true; a listener may return false or a string reason to deny.
     *
     * Unlike applyFilter, a listener that throws DENIES the operation
     * (fail closed): a crashing access-control plugin must never leave a
     * download or delete silently allowed. Gate listeners register with
     * addFilter, so existing before_download/before_delete plugins work
     * unchanged.
     *
     * @return mixed true to allow; false or a string denial reason otherwise
     */
    public function applyGate(string $hook, mixed $default, mixed ...$args): mixed
    {
        $allowed = $default;

        foreach ($this->filters[$hook] ?? [] as $filter) {
            try {
                $allowed = ($filter['callback'])($allowed, ...$args);
            } catch (\Throwable $e) {
                logError("Plugin gate error on '{$hook}' - denying", [
                    'plugin' => $filter['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        return $allowed;
    }

    /**
     * Fire an action event. All registered listeners are called; errors are
     * logged and swallowed so one listener cannot abort the rest.
     *
     * Back-compat: event-shaped hooks were historically dispatched through
     * the filter registry as applyFilter($event, null, ...$args), so filter
     * registrations under the same name are also fired here, with the same
     * (null, ...$args) signature they always received.
     */
    public function doAction(string $event, mixed ...$args): void
    {
        foreach ($this->actions[$event] ?? [] as $action) {
            try {
                ($action['callback'])(...$args);
            } catch (\Throwable $e) {
                logError("Plugin action error on '{$event}'", [
                    'plugin' => $action['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->filters[$event] ?? [] as $filter) {
            try {
                ($filter['callback'])(null, ...$args);
            } catch (\Throwable $e) {
                logError("Plugin action error on '{$event}'", [
                    'plugin' => $filter['plugin'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Registered filters, keyed by hook name (for feature introspection).
     *
     * @return array<string, list<array{callback: callable, priority: int, plugin: string}>>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Registered actions, keyed by event name (for feature introspection).
     *
     * @return array<string, list<array{callback: callable, priority: int, plugin: string}>>
     */
    public function getActions(): array
    {
        return $this->actions;
    }
}
