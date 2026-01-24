<?php
/**
 * SAML Service Provider Metadata
 *
 * Generates SP metadata XML for IdP configuration
 */
require_once 'includes/config.php';
require_once 'includes/saml.php';

header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="silo-sp-metadata.xml"');

echo generateSPMetadata();
