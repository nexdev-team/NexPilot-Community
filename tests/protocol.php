<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('NEXPILOT_COMMUNITY_ALLOW_LOCAL_HTTP', true);

$options = [];
$posts = [
    1 => (object) ['ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Public update', 'post_content' => '<p>Public body</p>', 'post_modified_gmt' => '2026-01-01 00:00:00'],
    2 => (object) ['ID' => 2, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Private draft', 'post_content' => 'Do not show', 'post_modified_gmt' => '2026-01-01 00:00:00'],
];
$products = [
    10 => new FakeProduct(10, 'Visible product', 'publish', true),
    11 => new FakeProduct(11, 'Hidden product', 'publish', false),
    12 => new FakeProduct(12, 'Draft product', 'draft', true),
];

class FakeProduct {
    public function __construct(private int $id, private string $name, private string $status, private bool $visible) {}
    public function get_id(): int { return $this->id; }
    public function get_name(): string { return $this->name; }
    public function get_status(): string { return $this->status; }
    public function is_visible(): bool { return $this->visible; }
    public function get_price(): string { return '15.00'; }
    public function get_description(): string { return '<p>Public description</p>'; }
}

function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; return true; }
function wp_get_environment_type() { return 'local'; }
function is_ssl() { return false; }
function get_bloginfo($key) { return 'Example site'; }
function home_url($path = '') { return 'http://127.0.0.1:9488' . $path; }
function get_post($id) { global $posts; return $posts[$id] ?? null; }
function get_posts($args) { global $posts; return array_values(array_filter($posts, static fn($p) => $p->post_type === $args['post_type'] && $p->post_status === $args['post_status'])); }
function get_the_title($post) { return $post->post_title; }
function get_permalink($post) { return 'https://example.test/item/' . (is_object($post) ? $post->ID : $post); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function wp_check_invalid_utf8($text, $strip = false) { return $text; }
function wp_json_encode($value) { return json_encode($value, JSON_THROW_ON_ERROR); }
function wc_get_products($args) { global $products; return array_values($products); }
function wc_get_product($id) { global $products; return $products[$id] ?? false; }
function get_woocommerce_currency() { return 'EUR'; }

class WP_REST_Request {
    public function __construct(private string $body, private array $headers = []) {}
    public function get_body(): string { return $this->body; }
    public function get_header(string $name): string { return $this->headers[strtolower($name)] ?? ''; }
}
class WP_REST_Response {
    private array $headers = [];
    public function __construct(public $data, public int $status = 200) {}
    public function header($key, $value): void { $this->headers[$key] = $value; }
}
class WP_Error {
    public function __construct(public string $code, public string $message, public array $data) {}
}

require __DIR__ . '/../plugin/includes/Credentials.php';
require __DIR__ . '/../plugin/includes/Protocol.php';

use Nexdev\NexPilotCommunity\Credentials;
use Nexdev\NexPilotCommunity\Protocol;

function check($condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS ' . $label . "\n";
}
function call_tool(string $token, string $name, array $args = []): array {
    $request = new WP_REST_Request(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $args]]), ['authorization' => 'Bearer ' . $token]);
    check(Protocol::authorize($request) === true, 'valid transport authorized');
    return Protocol::handle($request)->data['result'];
}

$token = Credentials::issue('Test client');
check((bool) preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $token), 'credential format');
check(!str_contains(json_encode(Credentials::all()), $token), 'plaintext credential is not stored');
check(Credentials::verify('Bearer ' . $token) !== null, 'valid credential accepted');
check(Credentials::verify('Bearer ' . substr($token, 0, -1) . (substr($token, -1) === '0' ? '1' : '0')) === null, 'modified credential denied');
check(Protocol::authorize(new WP_REST_Request('{}', ['authorization' => 'Bearer ' . $token, 'origin' => 'https://other.test'])) instanceof WP_Error, 'browser origin denied');
check(Protocol::authorize(new WP_REST_Request('{}', ['authorization' => 'Bearer wrong'])) instanceof WP_Error, 'missing credential denied');

$published = call_tool($token, 'content_get', ['id' => 1]);
check($published['isError'] === false && str_contains($published['content'][0]['text'], 'Public body'), 'published content available');
$draft = call_tool($token, 'content_get', ['id' => 2]);
check($draft['isError'] === true && !str_contains($draft['content'][0]['text'], 'Do not show'), 'draft body never returned');
$list = call_tool($token, 'content_list');
check($list['isError'] === false && !str_contains($list['content'][0]['text'], 'Private draft'), 'draft excluded from list');
$write = call_tool($token, 'change_execute');
check($write['isError'] === true, 'mutation tool unavailable');
$visible_product = call_tool($token, 'product_get', ['id' => 10]);
check($visible_product['isError'] === false && str_contains($visible_product['content'][0]['text'], 'Visible product'), 'visible product available');
$hidden_product = call_tool($token, 'product_get', ['id' => 11]);
check($hidden_product['isError'] === true && !str_contains($hidden_product['content'][0]['text'], 'Hidden product'), 'hidden product denied');
$draft_product = call_tool($token, 'product_get', ['id' => 12]);
check($draft_product['isError'] === true, 'draft product denied');
$product_list = call_tool($token, 'products_list');
check($product_list['isError'] === false && !str_contains($product_list['content'][0]['text'], 'Hidden product')
    && !str_contains($product_list['content'][0]['text'], 'Draft product'), 'non-public products excluded from list');

$request = new WP_REST_Request('{"jsonrpc":"2.0","id":4,"method":"tools/list"}', ['authorization' => 'Bearer ' . $token]);
$names = array_column(Protocol::handle($request)->data['result']['tools'], 'name');
check($names === ['site_info', 'content_list', 'content_get', 'products_list', 'product_get'], 'only five read tools advertised');

Credentials::revoke(explode('.', $token)[0]);
check(Credentials::verify('Bearer ' . $token) === null, 'revocation effective');
