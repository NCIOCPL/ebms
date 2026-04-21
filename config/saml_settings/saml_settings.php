<?php
/**
* SAML Entity ID Overrides
* Prevents Dev/Stage from hijacking Prod logins.
*/
// 1. Detect the Environment (Acquia/Pantheon/Custom)
// Change 'AH_SITE_ENVIRONMENT' to whatever env variable your host uses.
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (preg_match('/^dev\./i', $host) || preg_match('/^ncias-d3776-c\./i', $host)) {
  $env = 'dev';
} elseif (preg_match('/^stage\./i', $host)) {
  $env = 'stage';
} elseif (preg_match('/^qa\./i', $host) || preg_match('/^ncias-q3778-c\./i', $host)) {
  $env = 'qa';
} elseif (preg_match('/^ddev\./i', $host)) {
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
$config['samlauth.authentication']['strict'] = FALSE;
$config['samlauth.authentication']['user_mail_attribute'] = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';
$config['samlauth.authentication']['map_users'] = TRUE;
$config['samlauth.authentication']['map_users_field'] = 'authname';

// Disable standard name/mail mapping to ensure it only uses your custom field
$config['samlauth.authentication']['map_users_name'] = FALSE;
$config['samlauth.authentication']['map_users_mail'] = FALSE;
