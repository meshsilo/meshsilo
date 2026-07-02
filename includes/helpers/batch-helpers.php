<?php
// Webhook helper functions
// =====================
// Webhook Functions
// =====================

/**
 * Trigger webhook for an event (delegated to plugins via filter)
 */
function triggerWebhook($event, $payload)
{
    if (class_exists('PluginManager')) {
        PluginManager::applyFilter('trigger_webhook', null, $event, $payload);
    }
}

