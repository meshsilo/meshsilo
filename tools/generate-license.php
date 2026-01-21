#!/usr/bin/env php
<?php
/**
 * Silo License Key Generator
 *
 * This tool generates cryptographically signed license keys for Silo.
 * Keep this tool and the private key secure - they are NOT distributed with Silo.
 *
 * Usage:
 *   php generate-license.php --tier=pro --email=user@example.com
 *   php generate-license.php --tier=business --email=company@example.com --expires=2025-12-31
 *   php generate-license.php --generate-keys  (generates new RSA key pair)
 *
 * Options:
 *   --tier       License tier: pro or business (required)
 *   --email      Customer email (required)
 *   --name       Customer name (optional)
 *   --expires    Expiration date YYYY-MM-DD, or "never" for lifetime (default: 1 year)
 *   --max-users  Override max users for this license (optional)
 *   --generate-keys  Generate new RSA key pair
 */

// Configuration
define('PRIVATE_KEY_FILE', __DIR__ . '/license-private.pem');
define('PUBLIC_KEY_FILE', __DIR__ . '/license-public.pem');
define('KEY_BITS', 2048);

// Tier definitions (must match includes/license.php)
$tiers = [
    'pro' => [
        'max_users' => 5,
        'max_models' => -1,
        'max_storage_gb' => 100,
    ],
    'business' => [
        'max_users' => -1,
        'max_models' => -1,
        'max_storage_gb' => -1,
    ]
];

/**
 * Parse command line arguments
 */
function parseArgs($argv) {
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            $args[$key] = $value;
        }
    }
    return $args;
}

/**
 * Generate RSA key pair
 */
function generateKeyPair() {
    echo "Generating new RSA key pair...\n";

    $config = [
        'private_key_bits' => KEY_BITS,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $resource = openssl_pkey_new($config);

    if ($resource === false) {
        echo "Error generating key pair: " . openssl_error_string() . "\n";
        exit(1);
    }

    // Extract private key
    openssl_pkey_export($resource, $privateKey);

    // Extract public key
    $details = openssl_pkey_get_details($resource);
    $publicKey = $details['key'];

    // Save keys
    file_put_contents(PRIVATE_KEY_FILE, $privateKey);
    file_put_contents(PUBLIC_KEY_FILE, $publicKey);

    chmod(PRIVATE_KEY_FILE, 0600); // Restrict private key permissions

    echo "Keys generated successfully!\n\n";
    echo "Private key saved to: " . PRIVATE_KEY_FILE . "\n";
    echo "Public key saved to: " . PUBLIC_KEY_FILE . "\n\n";
    echo "IMPORTANT: Keep the private key secure and never distribute it!\n\n";
    echo "Add this public key to your Silo database settings:\n";
    echo "  INSERT INTO settings (key, value) VALUES ('license_public_key', '" . addslashes($publicKey) . "');\n\n";
    echo "Or paste this into Admin > Settings in your Silo instance:\n";
    echo "----------------------------------------\n";
    echo $publicKey;
    echo "----------------------------------------\n";
}

/**
 * Generate license key
 */
function generateLicense($args, $tiers) {
    // Validate required arguments
    if (empty($args['tier'])) {
        echo "Error: --tier is required (pro or business)\n";
        exit(1);
    }

    if (empty($args['email'])) {
        echo "Error: --email is required\n";
        exit(1);
    }

    $tier = strtolower($args['tier']);
    if (!isset($tiers[$tier])) {
        echo "Error: Invalid tier. Must be 'pro' or 'business'\n";
        exit(1);
    }

    // Check for private key
    if (!file_exists(PRIVATE_KEY_FILE)) {
        echo "Error: Private key not found. Run with --generate-keys first.\n";
        exit(1);
    }

    $privateKey = file_get_contents(PRIVATE_KEY_FILE);
    $keyResource = openssl_pkey_get_private($privateKey);

    if ($keyResource === false) {
        echo "Error loading private key: " . openssl_error_string() . "\n";
        exit(1);
    }

    // Build license payload
    $tierConfig = $tiers[$tier];

    // Handle expiration
    $expiresAt = null;
    if (isset($args['expires'])) {
        if ($args['expires'] !== 'never') {
            $expiresAt = date('Y-m-d', strtotime($args['expires']));
        }
    } else {
        // Default: 1 year from now
        $expiresAt = date('Y-m-d', strtotime('+1 year'));
    }

    $payload = [
        'license_id' => 'SL-' . strtoupper(bin2hex(random_bytes(8))),
        'tier' => $tier,
        'email' => $args['email'],
        'name' => $args['name'] ?? null,
        'max_users' => isset($args['max-users']) ? (int)$args['max-users'] : $tierConfig['max_users'],
        'max_models' => $tierConfig['max_models'],
        'max_storage_gb' => $tierConfig['max_storage_gb'],
        'features' => [], // Can add specific feature overrides here
        'expires_at' => $expiresAt,
        'issued_at' => date('Y-m-d H:i:s'),
    ];

    // Encode payload
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $payloadBase64 = base64_encode($payloadJson);

    // Sign the payload
    $signature = '';
    $signed = openssl_sign($payloadJson, $signature, $keyResource, OPENSSL_ALGO_SHA256);

    if (!$signed) {
        echo "Error signing license: " . openssl_error_string() . "\n";
        exit(1);
    }

    $signatureBase64 = base64_encode($signature);

    // Combine into license key
    $licenseKey = $payloadBase64 . '.' . $signatureBase64;

    // Output
    echo "\n";
    echo "========================================\n";
    echo "LICENSE KEY GENERATED SUCCESSFULLY\n";
    echo "========================================\n\n";

    echo "License Details:\n";
    echo "  ID:         {$payload['license_id']}\n";
    echo "  Tier:       " . ucfirst($tier) . "\n";
    echo "  Email:      {$payload['email']}\n";
    if ($payload['name']) {
        echo "  Name:       {$payload['name']}\n";
    }
    echo "  Max Users:  " . ($payload['max_users'] == -1 ? 'Unlimited' : $payload['max_users']) . "\n";
    echo "  Max Models: " . ($payload['max_models'] == -1 ? 'Unlimited' : $payload['max_models']) . "\n";
    echo "  Storage:    " . ($payload['max_storage_gb'] == -1 ? 'Unlimited' : $payload['max_storage_gb'] . ' GB') . "\n";
    echo "  Expires:    " . ($payload['expires_at'] ?? 'Never') . "\n";
    echo "  Issued:     {$payload['issued_at']}\n";
    echo "\n";

    echo "License Key (copy this entire string):\n";
    echo "----------------------------------------\n";
    echo $licenseKey . "\n";
    echo "----------------------------------------\n\n";

    // Also save to file
    $filename = "license-{$payload['license_id']}.txt";
    $content = "Silo License Key\n";
    $content .= "================\n\n";
    $content .= "License ID: {$payload['license_id']}\n";
    $content .= "Tier: " . ucfirst($tier) . "\n";
    $content .= "Email: {$payload['email']}\n";
    $content .= "Expires: " . ($payload['expires_at'] ?? 'Never') . "\n";
    $content .= "Issued: {$payload['issued_at']}\n\n";
    $content .= "License Key:\n";
    $content .= $licenseKey . "\n";

    file_put_contents(__DIR__ . '/' . $filename, $content);
    echo "License saved to: tools/$filename\n";
}

/**
 * Show usage
 */
function showUsage() {
    echo "Silo License Key Generator\n\n";
    echo "Usage:\n";
    echo "  php generate-license.php --generate-keys\n";
    echo "  php generate-license.php --tier=pro --email=user@example.com\n";
    echo "  php generate-license.php --tier=business --email=company@example.com --expires=2025-12-31\n\n";
    echo "Options:\n";
    echo "  --generate-keys  Generate new RSA key pair\n";
    echo "  --tier           License tier: pro or business (required)\n";
    echo "  --email          Customer email (required)\n";
    echo "  --name           Customer name (optional)\n";
    echo "  --expires        Expiration date YYYY-MM-DD, or 'never' (default: 1 year)\n";
    echo "  --max-users      Override max users (optional)\n";
}

// Main execution
$args = parseArgs($argv);

if (empty($args) || isset($args['help'])) {
    showUsage();
    exit(0);
}

if (isset($args['generate-keys'])) {
    generateKeyPair();
    exit(0);
}

generateLicense($args, $tiers);
