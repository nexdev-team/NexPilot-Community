<?php
/**
 * Plugin Name: NexPilot Community
 * Description: Read-only MCP access to published WordPress content and WooCommerce catalog items.
 * Version: 0.1.0-beta.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Nexdev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Nexdev\NexPilotCommunity;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '0.1.0-beta.1';

require_once __DIR__ . '/includes/Credentials.php';
require_once __DIR__ . '/includes/Protocol.php';
require_once __DIR__ . '/includes/Admin.php';

add_action('rest_api_init', [Protocol::class, 'register']);
add_action('admin_menu', [Admin::class, 'register']);
add_action('admin_post_nexpilot_community_action', [Admin::class, 'handle']);
