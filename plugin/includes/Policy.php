<?php
namespace Nexdev\SiteConnector;
final class Policy {
 public static function canonical(mixed $value): string {
  $sort = static function ($v) use (&$sort) {
   if (!is_array($v)) { return $v; }
   if (!array_is_list($v)) { ksort($v, SORT_STRING); }
   return array_map($sort, $v);
  };
  return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
 }
 public static function fingerprint(mixed $value): string { return hash('sha256', self::canonical($value)); }
 public static function allowed(array $client, string $scope): bool {
  return empty($client['revoked']) && (int)$client['expires'] > time() && in_array($scope, $client['scopes'], true);
 }
 public static function require_scope(array $client, string $scope): void {
  if (!self::allowed($client, $scope)) { throw new ConnectorError('Required permission is unavailable.'); }
 }
 public static function maintenance(array $client): void {
  self::require_scope($client, 'maintenance');
  if ((int)($client['maintenance_until'] ?? 0) <= time()) { throw new ConnectorError('Maintenance window is closed. A human administrator must open it.'); }
 }
 /** Derivative bearer capabilities must not survive credential rotation or owner reassignment. */
 public static function capability_binding(array $record,array $client): void {
  if(!is_string($record['credential_hash']??null)||!is_string($client['hash']??null)||!hash_equals($client['hash'],$record['credential_hash'])||!isset($record['owner_id'])||$record['owner_id']!==$client['user_id'])throw new ConnectorError('Capability authorization changed.');
 }
 public static function fields(array $args, array $allowed, array $required = []): void {
  if (array_diff(array_keys($args), $allowed)) { throw new ValidationError('Unknown argument.'); }
  foreach ($required as $key) { if (!array_key_exists($key, $args)) { throw new ValidationError('Missing required argument.'); } }
 }
 public static function id(mixed $value): int {
  if (!is_int($value) || $value < 1) { throw new ValidationError('ID must be a positive integer.'); }
  return $value;
 }
 public static function path(string $relative, bool $existing = true): string {
  if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.?(/|$)|://~', $relative)) { throw new ValidationError('Use a relative path without traversal.'); }
  $root = realpath(ABSPATH);
  $candidate = $root . '/' . $relative;
  $resolved = realpath($existing ? $candidate : dirname($candidate));
  if (!$resolved || ($resolved !== $root && !str_starts_with($resolved, $root . '/'))) { throw new ConnectorError('Path is outside WordPress or does not exist.'); }
  if (!$existing && is_link($candidate)) { throw new ConnectorError('Symlink writes are forbidden.'); }
  return $existing ? $resolved : $resolved . '/' . basename($candidate);
 }
}
