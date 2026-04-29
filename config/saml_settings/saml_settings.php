<?php
/**
* SAML Entity ID Overrides
* Prevents Dev/Stage from hijacking Prod logins.
*/
// 1. Detect the Environment (Acquia/Pantheon/Custom)
// Change 'AH_SITE_ENVIRONMENT' to whatever env variable your host uses.
// 1. Check for Proxy Header (Load Balancers often swallow the real host)
$machine_hostname = gethostname();

// 2. Get the Web Host
$http_host = $_SERVER['HTTP_HOST'] ?? '';

// 3. Determine ENV based on Machine Name FIRST (most reliable for Drush/CLI)
if (str_contains($machine_hostname, 'd3776') || str_contains($http_host, 'ebms-dev')) {
    $env = 'dev';
}
elseif (str_contains($machine_hostname, 'q3778') || str_contains($http_host, 'ebms-qa')) {
    $env = 'qa';
}
elseif (str_contains($machine_hostname, 'stage-id-here') || str_contains($http_host, 'ebms-stage')) {
    $env = 'stage';
}
elseif (isset($_SERVER['DDEV_PROJECT'])) {
    $env = 'ddev';
}
else {
    $env = 'prod';
}

$settings['env'] = $env;
$settings['machine_name'] = $machine_hostname;

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
};

