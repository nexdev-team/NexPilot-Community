<?php
// CLI-only worker. No HTTP execution; only a stored, approved job can be consumed.
if(PHP_SAPI!=='cli'||count($argv)!==3||!preg_match('/^[a-f0-9]{32}$/D',$argv[2]))exit(1);
$nexdev_site_connector_root=realpath($argv[1]);if(!$nexdev_site_connector_root||!is_file($nexdev_site_connector_root.'/wp-load.php'))exit(1);
require $nexdev_site_connector_root.'/wp-load.php';
if (!defined('ABSPATH')) { exit(1); }
if(!class_exists('Nexdev\\SiteConnector\\Jobs'))exit(1);
\Nexdev\SiteConnector\Jobs::run($argv[2]);
