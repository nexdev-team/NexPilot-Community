<?php
/**
 * Plugin Name: NexPilot Beta
 * Description: Independent AI connections, reviewable changes and time-limited maintenance access. Beta edition; testing and site-specific validation required.
 * Version: 0.2.1-beta.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Nexdev
 * License: GPL-2.0-or-later
 */
namespace Nexdev\SiteConnector;
if (!defined('ABSPATH')) { exit; }
define('NSC_VERSION', '0.2.1-beta.1');
require_once __DIR__ . '/includes/Errors.php';
require_once __DIR__ . '/includes/Policy.php';
require_once __DIR__ . '/includes/Store.php';
require_once __DIR__ . '/includes/Maintenance.php';
require_once __DIR__ . '/includes/Resources.php';
require_once __DIR__ . '/includes/Integrations.php';
require_once __DIR__ . '/includes/Design.php';
require_once __DIR__ . '/includes/Library.php';
require_once __DIR__ . '/includes/Files.php';
require_once __DIR__ . '/includes/Blocks.php';
require_once __DIR__ . '/includes/Transfers.php';
require_once __DIR__ . '/includes/Jobs.php';
require_once __DIR__ . '/includes/Access.php';
require_once __DIR__ . '/includes/Media.php';
require_once __DIR__ . '/includes/Recovery.php';
require_once __DIR__ . '/includes/Tools.php';
require_once __DIR__ . '/includes/OAuth.php';
require_once __DIR__ . '/includes/Server.php';
require_once __DIR__ . '/includes/Admin.php';
register_activation_hook(__FILE__, [Store::class, 'install']);
register_deactivation_hook(__FILE__, [Access::class, 'deactivate']);
add_action('rest_api_init', [Server::class, 'register']);
add_action('admin_enqueue_scripts', [Admin::class, 'enqueue']);
add_action('admin_menu', [Admin::class, 'menu']);
add_action('admin_post_nsc_action', [Admin::class, 'action']);

add_action('plugins_loaded', [Files::class, 'load_sandbox'], 20);

add_action('rest_api_init', [Transfers::class, 'register']);

add_action('admin_post_nsc_exchange', [Access::class, 'exchange']);
add_action('admin_post_nopriv_nsc_exchange', [Access::class, 'exchange']);

add_filter('determine_current_user', [Access::class, 'validate_session'], 99);

add_action('plugins_loaded', static function(){if((int)get_option('nsc_schema_version',0)<2)Store::install();}, 1);

add_action('admin_enqueue_scripts', [Blocks::class, 'enqueue']);
add_action('wp_ajax_nsc_editor_heartbeat', [Blocks::class, 'heartbeat']);

add_action('rest_api_init', [OAuth::class, 'register_routes']);
add_action('parse_request', [OAuth::class, 'discovery'], 0);
add_action('admin_post_nsc_oauth_authorize', [OAuth::class, 'authorize']);
add_action('admin_post_nopriv_nsc_oauth_authorize', [OAuth::class, 'authorize']);
add_filter('rest_post_dispatch', [OAuth::class, 'challenge'], 10, 3);
