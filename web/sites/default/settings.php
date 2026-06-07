<?php

// phpcs:ignoreFile

/**
 * @file
 * Drupal site configuration for MonarchWorks Drupal headless hub.
 *
 * Database credentials are read from environment variables so no secrets
 * are committed to the repository. The container injects:
 *   - DRUPAL_DB_HOST        (default: postgres)
 *   - DRUPAL_DB_NAME        (default: drupal)
 *   - DRUPAL_DB_USER        (default: innovate)
 *   - POSTGRES_PASSWORD     — shared platform DB password
 */

/**
 * Database configuration — PostgreSQL via environment variables.
 */
$databases['default']['default'] = [
  'driver'    => 'pgsql',
  'database'  => getenv('DRUPAL_DB_NAME') ?: 'drupal',
  'username'  => getenv('DRUPAL_DB_USER') ?: 'innovate',
  'password'  => getenv('POSTGRES_PASSWORD') ?: '',
  'host'      => getenv('DRUPAL_DB_HOST') ?: 'postgres',
  'port'      => '5432',
  'prefix'    => '',
];

/**
 * Config sync directory.
 *
 * Stored outside the web docroot so it can be version-controlled and
 * imported/exported via drush config:import / config:export.
 */
$settings['config_sync_directory'] = '../config/sync';

/**
 * Hash salt — used for one-time login links, form tokens, etc.
 * Override via DRUPAL_HASH_SALT env var in production for extra security.
 */
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'monarchworks-drupal-local-dev-hash-salt-change-in-production';

/**
 * Trusted host patterns — restricts which hostnames Drupal will serve.
 * In local Docker the site is reachable on localhost:4478.
 * Add production hostnames here when deploying.
 */
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\.0\.0\.1$',
  // Allow requests using the Docker Compose service name (drupal) so that
  // other containers on the same network (e.g. monarchworks-website) can
  // reach the Drupal JSON:API without a trusted-host rejection.
  '^drupal$',
];

/**
 * Allow the site to load from the Docker container internal hostname.
 */
if (getenv('DRUPAL_TRUSTED_HOST')) {
  $settings['trusted_host_patterns'][] = '^' . preg_quote(getenv('DRUPAL_TRUSTED_HOST'), '/') . '$';
}

/**
 * File system paths.
 * Using the default public files directory inside web/sites/default/files.
 */
$settings['file_public_path'] = 'sites/default/files';

/**
 * Private files directory — outside the web root for security.
 */
$settings['file_private_path'] = '';

/**
 * Disable the JavaScript / CSS preprocessor in local dev to simplify
 * debugging. Remove these lines for production.
 */
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

/**
 * S3FS — MinIO asset storage.
 *
 * Files managed by Drupal (images, PDFs, etc.) are stored in the MinIO
 * `drupal-content` bucket using the S3-compatible s3fs module.  All values
 * are injected via environment variables so no secrets are committed.
 *
 * Environment variables used:
 *   - S3FS_ENDPOINT      (default: http://s3:9000)
 *   - S3FS_BUCKET        (default: drupal-content)
 *   - S3FS_ACCESS_KEY    (default: innovate)
 *   - S3FS_SECRET_KEY    (falls back to POSTGRES_PASSWORD for convenience
 *                         in the shared local stack; override in production)
 *   - S3FS_REGION        (default: us-east-1 — ignored by MinIO but required
 *                         by the AWS SDK used internally)
 */
// S3FS credentials are read via $settings (not $config) per s3fs module docs.
// The module reads Settings::get('s3fs.access_key') and Settings::get('s3fs.secret_key').
$settings['s3fs.access_key'] = getenv('S3FS_ACCESS_KEY') ?: 'innovate';
$settings['s3fs.secret_key'] = getenv('S3FS_SECRET_KEY') ?: (getenv('POSTGRES_PASSWORD') ?: '');

// S3FS configuration — MinIO local endpoint via use_customhost + hostname.
// s3fs v3.x uses use_customhost=TRUE and the hostname field for custom endpoints.
$config['s3fs.settings']['bucket'] = getenv('S3FS_BUCKET') ?: 'drupal-content';
$config['s3fs.settings']['region'] = getenv('S3FS_REGION') ?: 'us-east-1';
$config['s3fs.settings']['use_customhost'] = TRUE;
// Full URL including protocol — s3fs v3.x accepts http:// prefix in hostname.
$config['s3fs.settings']['hostname'] = getenv('S3FS_ENDPOINT') ?: 'http://s3:9000';
$config['s3fs.settings']['use_path_style_endpoint'] = TRUE;
// do NOT use use_https since we're using plain http to MinIO.
$config['s3fs.settings']['use_https'] = FALSE;
// Use s3:// as the default managed-file scheme so uploads go to MinIO.
// Activated below and by drush config:set system.file default_scheme s3.
$config['s3fs.settings']['use_s3_for_public'] = TRUE;

/**
 * Load local overrides if they exist (not committed).
 */
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
