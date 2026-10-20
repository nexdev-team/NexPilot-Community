<?php
// WordPress is the only supported entry point. Retain records unless an administrator opted in.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
if (is_multisite()) { return; } // Network cleanup requires a separately reviewed migration.
if (!get_option('nsc_delete_on_uninstall', false)) { return; }
require_once __DIR__.'/includes/Errors.php';
require_once __DIR__.'/includes/Policy.php';
require_once __DIR__.'/includes/Store.php';
require_once __DIR__.'/includes/Access.php';
// Revoke every recorded temporary session before removing the tracking records.
while ($nexdev_site_connector_sessions=\Nexdev\SiteConnector\Store::all('browser',1000)) {
 foreach($nexdev_site_connector_sessions as $nexdev_site_connector_r) {
  \WP_Session_Tokens::get_instance($nexdev_site_connector_r['user_id'])->destroy($nexdev_site_connector_r['session']);
  \Nexdev\SiteConnector\Store::delete('browser_'.substr(hash('sha256',$nexdev_site_connector_r['session']),0,56));
 }
}
global $wpdb;
foreach (['records','audit'] as $nexdev_site_connector_name) {
 $nexdev_site_connector_table=\Nexdev\SiteConnector\Store::table($nexdev_site_connector_name);
 if (!preg_match('/^[a-zA-Z0-9_]+$/D',$nexdev_site_connector_table)) { return; }
 $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i',$nexdev_site_connector_table));
}
foreach (['nsc_schema_version','nsc_oauth_enabled','nsc_active_design','nsc_disabled','nsc_sandbox_error','nsc_delete_on_uninstall'] as $nexdev_site_connector_option) { delete_option($nexdev_site_connector_option); }
delete_transient('nsc_editor_runtime');
// Deliberately retain user content, uploads, and sandbox files: these can contain user work.
