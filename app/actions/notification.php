<?php
/**
 * Notification Actions (Discord/Slack)
 */

require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'test_discord':
        testDiscord();
        break;
    case 'test_slack':
        testSlack();
        break;
    case 'save_settings':
        saveNotificationSettings();
        break;
    case 'get_settings':
        getNotificationSettings();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function testDiscord() {
    $webhookUrl = $_POST['webhook_url'] ?? getSetting('discord_webhook_url', '');

    if (empty($webhookUrl)) {
        echo json_encode(['success' => false, 'error' => 'Discord webhook URL not configured']);
        return;
    }

    $siteName = getSetting('site_name', 'MeshSilo');
    $result = sendDiscordNotification($webhookUrl, [
        'embeds' => [[
            'title' => 'Test Notification',
            'description' => "This is a test notification from $siteName.",
            'color' => 0x5865F2,
            'timestamp' => date('c')
        ]]
    ]);

    echo json_encode($result);
}

function testSlack() {
    $webhookUrl = $_POST['webhook_url'] ?? getSetting('slack_webhook_url', '');

    if (empty($webhookUrl)) {
        echo json_encode(['success' => false, 'error' => 'Slack webhook URL not configured']);
        return;
    }

    $siteName = getSetting('site_name', 'MeshSilo');
    $result = sendSlackNotification($webhookUrl, [
        'text' => "Test notification from $siteName",
        'blocks' => [[
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => "*Test Notification*\nThis is a test notification from $siteName."
            ]
        ]]
    ]);

    echo json_encode($result);
}

function saveNotificationSettings() {
    setSetting('discord_webhook_url', $_POST['discord_webhook_url'] ?? '');
    setSetting('discord_enabled', isset($_POST['discord_enabled']) ? '1' : '0');
    setSetting('discord_events', json_encode($_POST['discord_events'] ?? []));

    setSetting('slack_webhook_url', $_POST['slack_webhook_url'] ?? '');
    setSetting('slack_enabled', isset($_POST['slack_enabled']) ? '1' : '0');
    setSetting('slack_events', json_encode($_POST['slack_events'] ?? []));

    echo json_encode(['success' => true]);
}

function getNotificationSettings() {
    echo json_encode([
        'success' => true,
        'settings' => [
            'discord_webhook_url' => getSetting('discord_webhook_url', ''),
            'discord_enabled' => getSetting('discord_enabled', '0') === '1',
            'discord_events' => json_decode(getSetting('discord_events', '[]'), true) ?: [],
            'slack_webhook_url' => getSetting('slack_webhook_url', ''),
            'slack_enabled' => getSetting('slack_enabled', '0') === '1',
            'slack_events' => json_decode(getSetting('slack_events', '[]'), true) ?: []
        ]
    ]);
}

/**
 * Send Discord notification
 */
function sendDiscordNotification($webhookUrl, $payload) {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true];
    }

    return ['success' => false, 'error' => $error ?: "HTTP $httpCode", 'response' => $response];
}

/**
 * Send Slack notification
 */
function sendSlackNotification($webhookUrl, $payload) {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === 'ok' || $httpCode === 200) {
        return ['success' => true];
    }

    return ['success' => false, 'error' => $error ?: $response];
}

/**
 * Trigger notification for an event (called from other parts of the app)
 */
function triggerNotification($event, $data) {
    // Discord
    if (getSetting('discord_enabled', '0') === '1') {
        $events = json_decode(getSetting('discord_events', '[]'), true) ?: [];
        if (in_array($event, $events) || in_array('*', $events)) {
            $webhookUrl = getSetting('discord_webhook_url', '');
            if ($webhookUrl) {
                $payload = formatDiscordPayload($event, $data);
                sendDiscordNotification($webhookUrl, $payload);
            }
        }
    }

    // Slack
    if (getSetting('slack_enabled', '0') === '1') {
        $events = json_decode(getSetting('slack_events', '[]'), true) ?: [];
        if (in_array($event, $events) || in_array('*', $events)) {
            $webhookUrl = getSetting('slack_webhook_url', '');
            if ($webhookUrl) {
                $payload = formatSlackPayload($event, $data);
                sendSlackNotification($webhookUrl, $payload);
            }
        }
    }
}

function formatDiscordPayload($event, $data) {
    $siteName = getSetting('site_name', 'MeshSilo');
    $siteUrl = getSetting('site_url', '');

    $titles = [
        'model.created' => 'New Model Uploaded',
        'model.updated' => 'Model Updated',
        'model.deleted' => 'Model Deleted',
        'model.downloaded' => 'Model Downloaded',
        'user.registered' => 'New User Registered'
    ];

    $colors = [
        'model.created' => 0x22C55E,
        'model.updated' => 0x3B82F6,
        'model.deleted' => 0xEF4444,
        'model.downloaded' => 0x8B5CF6,
        'user.registered' => 0xF59E0B
    ];

    $title = $titles[$event] ?? ucfirst(str_replace('.', ' ', $event));
    $color = $colors[$event] ?? 0x6366F1;

    $description = '';
    if (isset($data['name'])) {
        $description .= "**Name:** {$data['name']}\n";
    }
    if (isset($data['username'])) {
        $description .= "**User:** {$data['username']}\n";
    }
    if (isset($data['file_type'])) {
        $description .= "**Type:** " . strtoupper($data['file_type']) . "\n";
    }

    $embed = [
        'title' => $title,
        'description' => $description,
        'color' => $color,
        'footer' => ['text' => $siteName],
        'timestamp' => date('c')
    ];

    if (isset($data['model_id']) && $siteUrl) {
        $embed['url'] = rtrim($siteUrl, '/') . '/model.php?id=' . $data['model_id'];
    }

    return ['embeds' => [$embed]];
}

function formatSlackPayload($event, $data) {
    $siteName = getSetting('site_name', 'MeshSilo');

    $titles = [
        'model.created' => 'New Model Uploaded',
        'model.updated' => 'Model Updated',
        'model.deleted' => 'Model Deleted',
        'model.downloaded' => 'Model Downloaded',
        'user.registered' => 'New User Registered'
    ];

    $title = $titles[$event] ?? ucfirst(str_replace('.', ' ', $event));

    $text = "*$title*";
    if (isset($data['name'])) {
        $text .= "\n• Name: {$data['name']}";
    }
    if (isset($data['username'])) {
        $text .= "\n• User: {$data['username']}";
    }

    return [
        'text' => "$siteName: $title",
        'blocks' => [[
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $text
            ]
        ]]
    ];
}
