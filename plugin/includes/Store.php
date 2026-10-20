<?php
namespace Nexdev\SiteConnector;
final class Store {
 public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'nsc_' . $name; }
 public static function install(): void {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  $charset = $wpdb->get_charset_collate();
  dbDelta('CREATE TABLE ' . self::table('records') . " (
   id varchar(64) NOT NULL,
   kind varchar(24) NOT NULL,
   payload longtext NOT NULL,
   updated_at bigint unsigned NOT NULL DEFAULT 0,
   PRIMARY KEY  (id),
   KEY kind (kind)
  ) $charset;");
  dbDelta('CREATE TABLE ' . self::table('audit') . " (
   id bigint unsigned NOT NULL AUTO_INCREMENT,
   happened_at datetime NOT NULL,
   client_id varchar(64) NOT NULL,
   event varchar(64) NOT NULL,
   reference_id varchar(64) NOT NULL,
   PRIMARY KEY  (id)
  ) $charset;");
  update_option('nsc_schema_version', 2, false);
 }
 public static function get(string $id): ?array {
  global $wpdb;
  $raw = $wpdb->get_var($wpdb->prepare('SELECT payload FROM %i WHERE id=%s', self::table('records'), $id));
  return $raw === null ? null : self::decode($raw);
 }
 public static function put(string $id, string $kind, array $data, bool $new = false): void {
  global $wpdb;
  $payload = Policy::canonical($data);
  if (in_array($kind, ['proposal','job','upload','access','browser','oauth_consent','oauth_code','oauth_grant','oauth_access','oauth_refresh'], true)) {
   if (!function_exists('sodium_crypto_secretbox')) throw new ConnectorError('Sodium encryption is required for proposals.');
   $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
   $payload = 'sealed:' . base64_encode($nonce . sodium_crypto_secretbox($payload, $nonce, hash('sha256', wp_salt('auth') . 'nsc-proposals-v1', true)));
  }
  $row = ['id'=>$id, 'kind'=>$kind, 'payload'=>$payload, 'updated_at'=>(int)round(microtime(true)*1000000)];
  $ok = $new ? $wpdb->insert(self::table('records'), $row) : $wpdb->replace(self::table('records'), $row);
  if ($ok === false) { throw new ConnectorError('Storage operation failed.'); }
 }
 public static function all(string $kind, int $limit = 100): array {
  global $wpdb;
  return array_map(static fn($r)=>self::decode($r), $wpdb->get_col($wpdb->prepare('SELECT payload FROM %i WHERE kind=%s ORDER BY updated_at DESC, id DESC LIMIT %d', self::table('records'), $kind, $limit)));
 }
 public static function records(string $kind): \Generator {
  global $wpdb;$cursor='';
  do {
   $rows=$wpdb->get_results($wpdb->prepare('SELECT id,payload FROM %i WHERE kind=%s AND id>%s ORDER BY id LIMIT 200',self::table('records'),$kind,$cursor),ARRAY_A);
   if($wpdb->last_error)throw new ConnectorError('Record scan failed.');
   foreach($rows as $row){$cursor=$row['id'];yield $row['id']=>self::decode($row['payload']);}
  }while(count($rows)===200);
 }
 private static function decode(string $raw): array {
  if (str_starts_with($raw, 'sealed:')) {
   if (!function_exists('sodium_crypto_secretbox_open')) throw new ConnectorError('Sodium encryption is unavailable.');
   $bytes = base64_decode(substr($raw, 7), true);
   if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new ConnectorError('Invalid encrypted proposal.');
   $raw = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', wp_salt('auth') . 'nsc-proposals-v1', true));
   if ($raw === false) throw new ConnectorError('Proposal decryption failed; WordPress salts may have changed.');
  }
  return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
 }
 public static function audit(string $client, string $event, string $reference = ''): void {
  global $wpdb;
  if ($wpdb->insert(self::table('audit'), ['happened_at'=>gmdate('Y-m-d H:i:s'),'client_id'=>$client,'event'=>$event,'reference_id'=>$reference]) === false) { throw new ConnectorError('Audit persistence failed; operation refused.'); }
  // Optional independent receiver. No endpoint/key means local audit only.
  if(defined('NSC_AUDIT_URL')){
   if(!defined('NSC_AUDIT_KEY')||strlen(NSC_AUDIT_KEY)<32||wp_parse_url(NSC_AUDIT_URL,PHP_URL_SCHEME)!=='https')throw new ConnectorError('External audit configuration invalid.');
   $body=Policy::canonical(['id'=>bin2hex(random_bytes(16)),'site'=>home_url(),'time'=>time(),'client'=>$client,'event'=>$event,'reference'=>$reference]);
   $res=wp_safe_remote_post(NSC_AUDIT_URL,['timeout'=>3,'redirection'=>0,'headers'=>['Content-Type'=>'application/json','X-NSC-Signature'=>hash_hmac('sha256',$body,NSC_AUDIT_KEY)],'body'=>$body]);
   if(is_wp_error($res)||wp_remote_retrieve_response_code($res)<200||wp_remote_retrieve_response_code($res)>=300)throw new ConnectorError('External audit unavailable; inspect operation status before retrying.');
  }
 }
 // MySQL connection-owned advisory lock: no expiring lease that can admit a second writer.
 public static function locked(callable $fn, string $channel = 'writes'): mixed {
  global $wpdb;
  // Briefly serialize concurrent handshake requests; mutation locks remain fail-fast.
  $wait = str_starts_with($channel,'rate_') ? 1 : 0;
  // Explicit single-host SQLite backend for local WordPress. OS-held locks do not expire mid-write.
  if (defined('NSC_SQLITE_LOCK_FILE')) {
   if (!defined('DB_ENGINE') || DB_ENGINE !== 'sqlite') throw new ConnectorError('SQLite lock configured on a non-SQLite database.');
   $handle = fopen(NSC_SQLITE_LOCK_FILE . '.' . hash('sha256', $channel), 'c');
   $acquired = false; $deadline = microtime(true) + $wait;
   if ($handle) do { $acquired = flock($handle, LOCK_EX | LOCK_NB); if ($acquired || microtime(true) >= $deadline) break; usleep(10000); } while (true);
   if (!$handle || !$acquired) { if ($handle) fclose($handle); if (str_starts_with($channel,'rate_')) { throw new RateLimitError('Connection is busy. Retry later.'); } throw new ConnectorError('Another connector change is running. Retry later.'); }
   try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
  }
  $key = 'nsc_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $channel), 0, 48);
  if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $wait)) !== '1') { if (str_starts_with($channel,'rate_')) { throw new RateLimitError('Connection is busy. Retry later.'); } throw new ConnectorError('Another connector change is running, or database locks are unavailable. Retry later.'); }
  try { return $fn(); } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
 }
 public static function create_client(string $label, array $scopes, int $user_id): array {
  $valid = ['read','content','commerce','maintenance'];
  if (!$label || array_diff($scopes, $valid) || !in_array('read', $scopes, true)) { throw new ValidationError('Invalid connection settings.'); }
  $id = bin2hex(random_bytes(16)); $secret = bin2hex(random_bytes(32));
  $client = ['id'=>$id,'label'=>$label,'user_id'=>$user_id,'scopes'=>$scopes,'hash'=>hash('sha256',$secret),'expires'=>time()+30*DAY_IN_SECONDS,'revoked'=>false,'maintenance_until'=>0];
  self::put($id,'client',$client,true); self::audit($id,'connection.created');
  return ['client'=>$client,'token'=>$id . '.' . $secret];
 }
 public static function rotate_client(string $id): array {
  $c=self::get($id);if(!$c||!isset($c['hash'])||$c['revoked']||!empty($c['oauth_only']))throw new ConnectorError('Active connection required.');
  Access::revoke_sessions($id);$secret=bin2hex(random_bytes(32));$c['hash']=hash('sha256',$secret);$c['expires']=time()+30*DAY_IN_SECONDS;$c['maintenance_until']=0;
  self::put($id,'client',$c);self::audit($id,'connection.rotated');return ['client'=>$c,'token'=>$id.'.'.$secret];
 }
 public static function authenticate(string $token): array {
  if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $m)) { throw new ConnectorError('Invalid credential.'); }
  $client = self::get($m[1]);
  if (!$client || !empty($client['oauth_only']) || !hash_equals($client['hash'] ?? '', hash('sha256', $m[2])) || !Policy::allowed($client,'read')) { throw new ConnectorError('Invalid, expired or revoked credential.'); }
  $user = get_user_by('id', $client['user_id']);
  if (!$user || !user_can($user,'manage_options')) { throw new ConnectorError('Connection owner no longer has administrator access.'); }
  return $client;
 }
 public static function reauthorize(array $authenticated): array {
  $current=self::get($authenticated['id']);
  if(!$current||!empty($current['oauth_only'])||!Policy::allowed($current,'read')
   ||!is_string($authenticated['hash']??null)||!is_string($current['hash']??null)
   ||!hash_equals($current['hash'],$authenticated['hash'])
   ||($current['user_id']??null)!==($authenticated['user_id']??null))throw new ConnectorError('Connection changed or access was revoked. Authenticate again.');
  $owner=get_user_by('id',$current['user_id']);
  if(!$owner||!user_can($owner,'manage_options'))throw new ConnectorError('Connection owner no longer has administrator access.');
  return $current;
 }
 public static function rate(array $client): void {
  self::locked(static function() use ($client) {
   $id = 'rate_' . $client['id']; $bucket = intdiv(time(),60);
   $record = self::get($id);
   $count = $record && ($record['bucket'] ?? 0) === $bucket ? $record['count'] + 1 : 1;
   self::put($id, 'rate', ['bucket'=>$bucket, 'count'=>$count]);
   if ($count > 120) throw new RateLimitError('Rate limit exceeded. Retry after the current minute.');
  }, 'rate_' . $client['id']);
 }
 public static function prune(): int {
  global $wpdb;$cutoff=time()-30*DAY_IN_SECONDS;$count=0;
  foreach(['proposal','job','upload','access','browser','rate','oauth_consent','oauth_code','oauth_access','oauth_refresh','oauth_grant','oauth_rate'] as $kind){
   $rows=$wpdb->get_results($wpdb->prepare('SELECT id,payload FROM %i WHERE kind=%s AND updated_at < %d LIMIT 1000',self::table('records'),$kind,$cutoff*1000000),ARRAY_A);
   foreach($rows as $row){$record=self::decode($row['payload']);if(in_array($record['state']??'',['running','uncertain'],true))continue;self::delete($row['id']);$count++;}
  }
  return $count;
 }
 public static function delete(string $id): void {
  global $wpdb;
  if ($wpdb->delete(self::table('records'), ['id'=>$id]) === false) throw new ConnectorError('Delete failed.');
 }
}
