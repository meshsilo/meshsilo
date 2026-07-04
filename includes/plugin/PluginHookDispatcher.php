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
     */
    public function addFilter(string $hook, callable $callback, int $priority, string $pluginId): void
    {
        $this->filters[$hook][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $pluginId];
        usort($this->filters[$hook], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Register an action hook (event listener). Higher priority runs first.
     * Unlike filters, actions don't return/modify a value -- they just execute.
     */
    public function addAction(string $event, callable $callback, int $priority, string $pluginId): void
    {
        $this->actions[$event][] = ['callback' => $callback, 'priority' => $priority, 'plugin' => $pluginId];
        usort($this->actions[$event], fn(array $a, array $b) => $b['priority'] <=> $a['priority']);
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
     * Fire an action event. All registered listeners are called; errors are
     * logged and swallowed so one listener cannot abort the rest.
     */
    public function doAction(string $event, mixed ...$args): void
    {
        if (empty($this->actions[$event])) {
            return;
        }

        foreach ($this->actions[$event] as $action) {
            try {
                ($action['callback'])(...$args);
            } catch (\Throwable $e) {
                logError("Plugin action error on '{$event}'", [
                    'plugin' => $action['plugin'] ?? 'unknown',
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
