<?php
namespace Nexdev\NexPilotCommunity;

if (!defined('ABSPATH')) {
    exit;
}

final class Credentials {
    private const OPTION = 'nexpilot_community_clients';
    private const LIFETIME = 2592000;

    public static function all(): array {
        $records = get_option(self::OPTION, []);
        return is_array($records) ? $records : [];
    }

    public static function issue(string $label): string {
        $label = trim($label);
        if ($label === '' || strlen($label) > 80) {
            throw new \InvalidArgumentException('Client name must be between 1 and 80 bytes.');
        }
        $records = self::all();
        if (count($records) >= 20) {
            throw new \RuntimeException('Maximum number of clients reached. Revoke an old client first.');
        }
        $id = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $records[$id] = [
            'label' => $label,
            'hash' => hash('sha256', $secret),
            'created' => time(),
            'expires' => time() + self::LIFETIME,
        ];
        update_option(self::OPTION, $records, false);
        return $id . '.' . $secret;
    }

    public static function verify(string $header): ?array {
        if (!preg_match('/^Bearer ([a-f0-9]{32})\.([a-f0-9]{64})$/D', $header, $parts)) {
            return null;
        }
        $record = self::all()[$parts[1]] ?? null;
        if (!is_array($record) || !is_string($record['hash'] ?? null)
            || !is_int($record['expires'] ?? null) || $record['expires'] <= time()
            || !hash_equals($record['hash'], hash('sha256', $parts[2]))) {
            return null;
        }
        return ['id' => $parts[1], 'label' => (string) ($record['label'] ?? '')];
    }

    public static function revoke(string $id): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid client identifier.');
        }
        $records = self::all();
        unset($records[$id]);
        update_option(self::OPTION, $records, false);
    }
}
