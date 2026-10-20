<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('nexpilot_community_clients');
