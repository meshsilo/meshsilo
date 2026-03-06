<?php
/**
 * Middleware Interface
 *
 * All custom middleware classes should implement this interface.
 */

interface MiddlewareInterface {
    /**
     * Handle the middleware logic
     *
     * @param array $params Route parameters
     * @return bool True to continue, false to halt
     */
    public function handle(array $params): bool;
}
