<?php
namespace Nexdev\NexPilotCommunity;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin {
    public static function register(): void {
        add_management_page('NexPilot Community', 'NexPilot Community', 'manage_options', 'nexpilot-community', [self::class, 'page']);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
        echo '<div class="wrap"><h1>NexPilot Community</h1>';
        echo '<p>Issue a separate 30-day read-only credential for each local MCP client. Only published posts, pages and catalog products are available.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('nexpilot_community_action');
        echo '<input type="hidden" name="action" value="nexpilot_community_action"><input type="hidden" name="operation" value="issue">';
        echo '<label for="npc-label">Client name</label> <input id="npc-label" name="label" required maxlength="80"> ';
        submit_button('Create connection', 'primary', 'submit', false);
        echo '</form><h2>Connections</h2><table class="widefat striped"><thead><tr><th>Client</th><th>Expires (UTC)</th><th>Action</th></tr></thead><tbody>';
        foreach (Credentials::all() as $id => $record) {
            echo '<tr><td>' . esc_html((string) ($record['label'] ?? '')) . '</td><td>'
                . esc_html(gmdate('Y-m-d H:i', (int) ($record['expires'] ?? 0))) . '</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('nexpilot_community_action');
            echo '<input type="hidden" name="action" value="nexpilot_community_action"><input type="hidden" name="operation" value="revoke">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            submit_button('Revoke', 'secondary', 'submit', false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function handle(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', '', ['response' => 403]);
        }
        check_admin_referer('nexpilot_community_action');
        $raw_operation = $_POST['operation'] ?? '';
        $operation = is_string($raw_operation) ? sanitize_key(wp_unslash($raw_operation)) : '';
        if ($operation === 'issue') {
            $raw_label = $_POST['label'] ?? '';
            $label = is_string($raw_label) ? sanitize_text_field(wp_unslash($raw_label)) : '';
            try {
                $token = Credentials::issue($label);
            } catch (\InvalidArgumentException | \RuntimeException $error) {
                wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
            }
            nocache_headers();
            $back = admin_url('tools.php?page=nexpilot-community');
            wp_die('<h1>Connection created</h1><p>Copy this credential now. It will not be shown again.</p><p><code>'
                . esc_html($token) . '</code></p><p>Store it in a private file outside the repository with permissions 0600.</p><p><a href="'
                . esc_url($back) . '">Return to connections</a></p>', 'NexPilot Community');
        }
        if ($operation === 'revoke') {
            $raw_id = $_POST['id'] ?? '';
            try {
                Credentials::revoke(is_string($raw_id) ? sanitize_text_field(wp_unslash($raw_id)) : '');
            } catch (\InvalidArgumentException $error) {
                wp_die('Invalid client identifier.', '', ['response' => 400]);
            }
            wp_safe_redirect(admin_url('tools.php?page=nexpilot-community'));
            exit;
        }
        wp_die('Unknown operation.', '', ['response' => 400]);
    }
}
