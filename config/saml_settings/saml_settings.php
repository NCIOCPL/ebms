<?php
/**
* SAML Entity ID Overrides
* Prevents Dev/Stage from hijacking Prod logins.
*/
// 1. Detect the Environment (Acquia/Pantheon/Custom)
// Change 'AH_SITE_ENVIRONMENT' to whatever env variable your host uses.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (preg_match('/^dev\./', $host)) {
  $env = 'dev';
} elseif (preg_match('/^stage\./', $host)) {
  $env = 'stage';
} elseif (preg_match('/^qa\./', $host)) {
  $env = 'qa';
} elseif (preg_match('/^ddev\./', $host)) {
  $env = 'ddev';
} else {
  $env = 'prod';
}

switch ($env) {
  case 'dev':
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms-dev.nci.nih.gov';
    $cert_path = '/local/drupal/ebms/private/saml_certs/' . $env;
    break;
  case 'qa':
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms-qa.nci.nih.gov';
    $cert_path = '/local/drupal/ebms/private/saml_certs/' . $env;
    break;
  case 'stage':
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms-stage.nci.nih.gov';
    $cert_path = '/local/drupal/ebms/private/saml_certs/' . $env;
    break;
  case 'prod':
    // Optional: Hardcode prod just to be safe, or let it use the DB config
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms.nci.nih.gov';
    $cert_path = '/local/drupal/ebms/private/saml_certs/' . $env;
    break;
  case 'ddev':
    // Local Development
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms.ddev.site/';
    $cert_path = '/var/www/html/private/saml_certs/' . $env;
    break;
  default:
    // Local Development
    $config['samlauth.authentication']['sp_entity_id'] = 'https://ebms.ddev.site/';
    $cert_path = '/var/www/html/private/saml_certs/' . $env;
    break;
}

// 1. Define the path based on the environment
// Ensure this path is NOT accessible via the browser!

// 2. Inject the Entity ID (from previous conversation)//
// 3. Inject the Certificates (Read from file)
if (file_exists($cert_path . '/sp.key') && file_exists($cert_path . '/sp.crt')) {
    $config['samlauth.authentication']['sp_private_key'] = file_get_contents($cert_path . '/sp.key');
    $config['samlauth.authentication']['sp_x509_certificate'] = file_get_contents($cert_path . '/sp.crt');
}
