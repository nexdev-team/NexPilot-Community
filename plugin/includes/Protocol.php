<?php
namespace Nexdev\NexPilotCommunity;

if (!defined('ABSPATH')) {
    exit;
}

final class Protocol {
    private const MAX_REQUEST_BYTES = 131072;
    private const MAX_TEXT_BYTES = 20000;

    public static function register(): void {
        register_rest_route('nexpilot-community/v1', '/mcp', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    public static function authorize(\WP_REST_Request $request) {
        $local_http = defined('NEXPILOT_COMMUNITY_ALLOW_LOCAL_HTTP')
            && NEXPILOT_COMMUNITY_ALLOW_LOCAL_HTTP
            && wp_get_environment_type() === 'local';
        if ((!is_ssl() && !$local_http) || (string) $request->get_header('origin') !== ''
            || strlen($request->get_body()) > self::MAX_REQUEST_BYTES
            || Credentials::verify((string) $request->get_header('authorization')) === null) {
            return new \WP_Error('nexpilot_access_denied', 'Connection denied.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        $message = json_decode($request->get_body(), true);
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0'
            || !is_string($message['method'] ?? null)) {
            return self::response(null, null, -32600, 'Invalid request.');
        }
        if (!array_key_exists('id', $message)) {
            return self::response(null, null, null, null, 202);
        }
        $id = $message['id'];
        if (!is_int($id) && !is_string($id)) {
            return self::response(null, null, -32600, 'Invalid request identifier.');
        }
        try {
            switch ($message['method']) {
                case 'initialize':
                    $result = [
                        'protocolVersion' => '2025-03-26',
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => 'nexpilot-community', 'version' => VERSION],
                        'instructions' => 'Read-only access to published WordPress content and visible WooCommerce products.',
                    ];
                    break;
                case 'ping':
                    $result = (object) [];
                    break;
                case 'tools/list':
                    $result = ['tools' => self::definitions()];
                    break;
                case 'tools/call':
                    $params = $message['params'] ?? null;
                    if (!is_array($params) || !is_string($params['name'] ?? null)) {
                        throw new \InvalidArgumentException('Invalid tool call.');
                    }
                    $args = $params['arguments'] ?? [];
                    if (!is_array($args) || array_is_list($args) && $args !== []) {
                        throw new \InvalidArgumentException('Invalid tool arguments.');
                    }
                    $value = self::call($params['name'], $args);
                    $result = ['content' => [['type' => 'text', 'text' => wp_json_encode($value)]], 'isError' => false];
                    break;
                default:
                    return self::response($id, null, -32601, 'Method unavailable.');
            }
            return self::response($id, $result);
        } catch (\InvalidArgumentException $error) {
            return self::response($id, ['content' => [['type' => 'text', 'text' => $error->getMessage()]], 'isError' => true]);
        } catch (\Throwable $error) {
            return self::response($id, ['content' => [['type' => 'text', 'text' => 'Tool unavailable.']], 'isError' => true]);
        }
    }

    private static function response($id, $result, ?int $code = null, ?string $message = null, int $status = 200): \WP_REST_Response {
        if ($status === 202) {
            $response = new \WP_REST_Response(null, 202);
        } elseif ($code !== null) {
            $response = new \WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
        } else {
            $response = new \WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $status);
        }
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function definitions(): array {
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $id = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id'], 'additionalProperties' => false];
        $list = ['type' => 'object', 'properties' => [
            'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
        ], 'additionalProperties' => false];
        $content_list = $list;
        $content_list['properties']['type'] = ['type' => 'string', 'enum' => ['post', 'page']];
        return [
            self::definition('site_info', 'Public site identity and connector version.', $none),
            self::definition('content_list', 'List published posts or pages.', $content_list),
            self::definition('content_get', 'Read a published post or page by ID.', $id),
            self::definition('products_list', 'List visible published WooCommerce products.', $list),
            self::definition('product_get', 'Read a visible published WooCommerce product by ID.', $id),
        ];
    }

    private static function definition(string $name, string $description, array $schema): array {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema,
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false]];
    }

    private static function pagination(array $args): array {
        $page = $args['page'] ?? 1;
        $per_page = $args['per_page'] ?? 10;
        if (!is_int($page) || $page < 1 || $page > 100 || !is_int($per_page) || $per_page < 1 || $per_page > 20) {
            throw new \InvalidArgumentException('Invalid pagination.');
        }
        return [$page, $per_page];
    }

    private static function identifier(array $args): int {
        $id = $args['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('A positive integer ID is required.');
        }
        return $id;
    }

    private static function call(string $name, array $args): array {
        switch ($name) {
            case 'site_info':
                return ['name' => get_bloginfo('name'), 'url' => home_url('/'), 'connector' => VERSION,
                    'access' => 'published-content-only'];
            case 'content_list':
                [$page, $per_page] = self::pagination($args);
                $type = $args['type'] ?? 'post';
                if (!in_array($type, ['post', 'page'], true)) {
                    throw new \InvalidArgumentException('Type must be post or page.');
                }
                $posts = get_posts(['post_type' => $type, 'post_status' => 'publish', 'numberposts' => $per_page,
                    'offset' => ($page - 1) * $per_page, 'orderby' => 'date', 'order' => 'DESC']);
                $posts = array_values(array_filter($posts, static fn($post) => $post->post_type === $type && $post->post_status === 'publish'));
                return ['items' => array_map(static fn($post) => [
                    'id' => (int) $post->ID, 'type' => $post->post_type, 'title' => get_the_title($post),
                    'url' => get_permalink($post), 'modified_gmt' => $post->post_modified_gmt,
                ], $posts), 'page' => $page];
            case 'content_get':
                $post = get_post(self::identifier($args));
                if (!$post || !in_array($post->post_type, ['post', 'page'], true) || $post->post_status !== 'publish') {
                    throw new \InvalidArgumentException('Published content not found.');
                }
                return ['id' => (int) $post->ID, 'type' => $post->post_type, 'title' => get_the_title($post),
                    'url' => get_permalink($post), 'content' => self::safe_text($post->post_content),
                    'modified_gmt' => $post->post_modified_gmt];
            case 'products_list':
                if (!function_exists('wc_get_products')) {
                    throw new \InvalidArgumentException('WooCommerce is unavailable.');
                }
                [$page, $per_page] = self::pagination($args);
                $products = wc_get_products(['status' => 'publish', 'limit' => $per_page, 'page' => $page,
                    'orderby' => 'date', 'order' => 'DESC']);
                return ['items' => array_values(array_map([self::class, 'product_summary'],
                    array_filter($products, static fn($product) => $product->get_status() === 'publish' && $product->is_visible()))), 'page' => $page];
            case 'product_get':
                if (!function_exists('wc_get_product')) {
                    throw new \InvalidArgumentException('WooCommerce is unavailable.');
                }
                $product = wc_get_product(self::identifier($args));
                if (!$product || $product->get_status() !== 'publish' || !$product->is_visible()) {
                    throw new \InvalidArgumentException('Visible published product not found.');
                }
                return array_merge(self::product_summary($product), [
                    'description' => self::safe_text($product->get_description()),
                ]);
        }
        throw new \InvalidArgumentException('Tool unavailable.');
    }

    private static function product_summary($product): array {
        return ['id' => $product->get_id(), 'name' => $product->get_name(), 'url' => get_permalink($product->get_id()),
            'price' => $product->get_price(), 'currency' => get_woocommerce_currency()];
    }

    private static function safe_text(string $value): string {
        return wp_check_invalid_utf8(substr(wp_strip_all_tags($value), 0, self::MAX_TEXT_BYTES), true);
    }
}
