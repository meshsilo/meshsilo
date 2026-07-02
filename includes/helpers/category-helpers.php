<?php
// Category helper functions
// Invalidate categories cache (call after modifying categories)
function invalidateCategoriesCache()
{
    Cache::getInstance()->forget('all_categories');
}
