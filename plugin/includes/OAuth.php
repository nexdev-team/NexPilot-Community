<?php
namespace Nexdev\SiteConnector;
/** Public OAuth clients, authorization code + S256 PKCE; no implicit/password grants. */
final class OAuth {
 public static function enabled(): bool {return (bool)get_option('nsc_oauth_enabled',false)&&!get_option('nsc_disabled',false);}
 public static function resource(): string {return rest_url('nexdev-connector/v1/mcp');}
 public static function issuer(): string {return home_url('/nsc-oauth');}
 private static function url(string $path): string {return rest_url('nexdev-connector/v1/oauth/'.$path);}
 private static function transport(): void {
  if(!self::enabled()||(!is_ssl()&&!(defined('NSC_ALLOW_LOCAL_HTTP')&&NSC_ALLOW_LOCAL_HTTP&&wp_get_environment_type()==='local')))throw new ConnectorError('OAuth unavailable.');
 }
 public static function register_routes(): void {
  foreach(['metadata','resource','register','token','revoke'] as $path)register_rest_route('nexdev-connector/v1','/oauth/'.$path,['methods'=>in_array($path,['metadata','resource'],true)?'GET':'POST','permission_callback'=>'__return_true','callback'=>static fn($r)=>self::endpoint($path,$r)]);
 }
 public static function metadata(): array {return ['issuer'=>self::issuer(),'authorization_endpoint'=>admin_url('admin-post.php?action=nsc_oauth_authorize'),'token_endpoint'=>self::url('token'),'registration_endpoint'=>self::url('register'),'revocation_endpoint'=>self::url('revoke'),'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'token_endpoint_auth_methods_supported'=>['none'],'revocation_endpoint_auth_methods_supported'=>['none'],'code_challenge_methods_supported'=>['S256'],'scopes_supported'=>['read','content','commerce','maintenance'],'client_id_metadata_document_supported'=>false,'authorization_response_iss_parameter_supported'=>true];}
 public static function resource_metadata(): array {return ['resource'=>self::resource(),'authorization_servers'=>[self::issuer()],'scopes_supported'=>['read','content','commerce','maintenance'],'bearer_methods_supported'=>['header']];}
 public static function discovery(): void {
  $path=wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$issuer=wp_parse_url(self::issuer(),PHP_URL_PATH);$resource=wp_parse_url(self::resource(),PHP_URL_PATH);
  if(!in_array($path,['/.well-known/oauth-authorization-server'.$issuer,'/.well-known/oauth-protected-resource'.$resource,'/.well-known/oauth-protected-resource'],true))return;
  try{self::transport();}catch(\Throwable $e){status_header(404);exit;}
  nocache_headers();header('Content-Type: application/json');header('X-Content-Type-Options: nosniff');
  echo wp_json_encode(str_contains($path,'oauth-authorization-server')?self::metadata():self::resource_metadata());exit;
 }
 public static function challenge($response,$server,$request) {
  if($request->get_route()==='/nexdev-connector/v1/mcp'&&$response->get_status()===401&&self::enabled())$response->header('WWW-Authenticate','Bearer resource_metadata="'.self::url('resource').'", scope="read"');
  return $response;
 }
 private static function response(array $data,int $status=200): \WP_REST_Response {$r=new \WP_REST_Response($data,$status);$r->header('Cache-Control','no-store');$r->header('Pragma','no-cache');return $r;}
 private static function throttle(string $route): void {
  Store::locked(static function()use($route){
   $address=sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']??''));
   $ip=hash_hmac('sha256',filter_var($address,FILTER_VALIDATE_IP)?$address:'unknown',wp_salt('auth'));
   // Slot collisions share a limit (stricter), never reset another address's counter.
   $bucket=intdiv(time(),60);
   foreach(['global'=>400,'slot_'.substr($ip,0,2)=>60] as $who=>$limit){$id='oauthrate_'.$route.'_'.$who;$r=Store::get($id);$count=$r&&$r['bucket']===$bucket?$r['count']+1:1;Store::put($id,'oauth_rate',['bucket'=>$bucket,'count'=>$count]);if($count>$limit)throw new RateLimitError('Too many requests.');}
  },'oauth_public');
 }
 private static function params(\WP_REST_Request $r): array {
  if(strlen($r->get_body())>8192||$r->get_query_params())throw new ValidationError('Invalid request.');
  // Reject duplicate form fields and array syntax before PHP can collapse them.
  if(strtolower(trim(explode(';',$r->get_header('content-type'))[0]))==='application/x-www-form-urlencoded'){
   $keys=[];foreach(explode('&',$r->get_body()) as $part){if($part==='')continue;$key=urldecode(explode('=',$part,2)[0]);if(isset($keys[$key])||preg_match('/[\[\]\x00-\x20]/',$key))throw new ValidationError('Ambiguous parameter.');$keys[$key]=true;}
   return $r->get_body_params();
  }
  throw new ValidationError('Form encoding required.');
 }
 public static function endpoint(string $path,\WP_REST_Request $r): \WP_REST_Response {
  try{
   self::transport();
   if($path==='metadata')return self::response(self::metadata());if($path==='resource')return self::response(self::resource_metadata());
   self::throttle($path);
   if($path==='register'){
    if(strlen($r->get_body())>8192||$r->get_query_params())throw new ValidationError('Invalid registration.');
    $a=$r->get_json_params();if(!is_array($a))throw new ValidationError('JSON required.');
    return self::response(Store::locked(fn()=>self::register_client($a)),201);
   }
   $a=self::params($r);
   return self::response(Store::locked(fn()=>$path==='token'?self::token($a):self::revoke($a)));
  }catch(RateLimitError $e){$r=self::response(['error'=>'temporarily_unavailable'],429);$r->header('Retry-After','60');return $r;}
  catch(ValidationError $e){return self::response(['error'=>'invalid_request'],400);}
  catch(\Throwable $e){return self::response(['error'=>$path==='register'?'invalid_client_metadata':'invalid_grant'],400);}
 }
 private static function redirect_uri(string $uri): bool {
  if(strlen($uri)>2048||preg_match('/[\x00-\x20\\\\]/',$uri))return false;$u=wp_parse_url($uri);
  if(!$u||!preg_match('/^(?:[a-z0-9.-]+|\[::1\])$/iD',$u['host']??'')||isset($u['fragment'])||isset($u['user'])||isset($u['pass'])||empty($u['host']))return false;
  if(($u['scheme']??'')!=='https'&&!(($u['scheme']??'')==='http'&&in_array($u['host'],['127.0.0.1','[::1]'],true)))return false;
  parse_str($u['query']??'',$q);return !array_intersect(array_keys($q),['code','state','iss','error']);
 }
 private static function register_client(array $a): array {
  // RFC 7591: ignore unrecognized optional metadata; never fetch client-supplied URLs.
  $a=array_intersect_key($a,array_flip(['client_name','redirect_uris','grant_types','response_types','token_endpoint_auth_method','scope']));$a+=['client_name'=>'Unnamed application'];
  Policy::fields($a,['client_name','redirect_uris','grant_types','response_types','token_endpoint_auth_method','scope'],['client_name','redirect_uris']);
  if(!is_string($a['client_name'])||strlen($a['client_name'])<1||strlen($a['client_name'])>100||!is_array($a['redirect_uris'])||!array_is_list($a['redirect_uris'])||count($a['redirect_uris'])<1||count($a['redirect_uris'])>10)throw new ValidationError('Invalid client.');
  foreach($a['redirect_uris'] as $uri)if(!is_string($uri)||!self::redirect_uri($uri))throw new ValidationError('Invalid redirect.');
  if(($a['token_endpoint_auth_method']??'none')!=='none'||($a['response_types']??['code'])!==['code']||array_diff($a['grant_types']??['authorization_code','refresh_token'],['authorization_code','refresh_token']))throw new ValidationError('Unsupported flow.');
  if(isset($a['scope']))self::scopes($a['scope']);
  $id=bin2hex(random_bytes(16));$app=['client_id'=>$id,'client_name'=>sanitize_text_field($a['client_name']),'redirect_uris'=>array_values(array_unique($a['redirect_uris'])),'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'token_endpoint_auth_method'=>'none','client_id_issued_at'=>time()];
  // Anonymous registration must not allocate persistent application records.
  unset($app['client_id']);
  $payload=Policy::canonical(['app'=>$app,'expires'=>time()+600,'nonce'=>$id]);
  if(strlen($payload)>4096)throw new ValidationError('Registration metadata too large.');
  $body=rtrim(strtr(base64_encode($payload),'+/','-_'),'=');
  $id='nscd_'.$body.'.'.hash_hmac('sha256','nsc-dcr-v1:'.$body,wp_salt('auth'));
  return ['client_id'=>$id]+$app;
 }
 private static function scopes($value): array {
  if(!is_string($value))throw new ValidationError('Invalid scope.');$s=array_values(array_unique(explode(' ',trim($value))));
  if(!in_array('read',$s,true)||array_diff($s,['read','content','commerce','maintenance']))throw new ValidationError('Invalid scope.');return $s;
 }
 private static function app_key(string $id): string {return preg_match('/^[a-f0-9]{32}$/D',$id)?'app_'.$id:'app_'.substr(hash('sha256',$id),0,60);}
 private static function app($id): array {
  if(!is_string($id)||strlen($id)>6144||!preg_match('/^(?:[a-f0-9]{32}|nscd_[A-Za-z0-9_-]+\.[a-f0-9]{64})$/D',$id))throw new ValidationError('Invalid client.');
  $stored=Store::get(self::app_key($id));
  if($stored){if(($stored['client_id']??null)!==$id)throw new ConnectorError('Client binding mismatch.');return $stored;}
  if(!str_starts_with($id,'nscd_'))throw new ConnectorError('Unknown client.');
  [$body,$mac]=explode('.',substr($id,5),2);
  if(!hash_equals(hash_hmac('sha256','nsc-dcr-v1:'.$body,wp_salt('auth')),$mac))throw new ConnectorError('Invalid registration signature.');
  $raw=base64_decode(strtr($body,'-_','+/'),true);
  if($raw===false||strlen($raw)>4096)throw new ConnectorError('Invalid registration.');
  $packet=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  if(!is_int($packet['expires']??null)||$packet['expires']<=time()||!is_array($packet['app']??null))throw new ConnectorError('Registration expired. Register again.');
  return ['client_id'=>$id]+$packet['app'];
 }
 private static function persist_app(string $id): void {
  // Called only after nonce-protected administrator consent, under the writes lock.
  $app=self::app($id);Store::put(self::app_key($id),'oauth_app',$app);
 }
 private static function auth_request(array $a): array {
  Policy::fields($a,['action','client_id','redirect_uri','response_type','code_challenge','code_challenge_method','scope','state','resource'],['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','state','resource']);
  foreach($a as $key=>$v)if(!is_string($v)||strlen($v)>($key==='client_id'?6144:2048))throw new ValidationError('Invalid authorization request.');
  $app=self::app($a['client_id']);
  if(!in_array($a['redirect_uri'],$app['redirect_uris'],true)||$a['response_type']!=='code'||$a['resource']!==self::resource()||$a['code_challenge_method']!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$a['code_challenge'])||strlen($a['state'])<16||strlen($a['state'])>512)throw new ValidationError('Invalid authorization request.');
  $a['scopes']=self::scopes($a['scope']??'read');$a['name']=$app['client_name'];return $a;
 }
 public static function authorize(): void {
  nocache_headers();header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");header('X-Frame-Options: DENY');
  try{
   self::transport();if(!is_user_logged_in()){auth_redirect();exit;}if(!current_user_can('manage_options'))wp_die('Administrator access required.','OAuth',['response'=>403]);
   if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
    check_admin_referer('nsc_oauth_consent');$decision=sanitize_key(wp_unslash($_POST['decision']??''));$id=sanitize_text_field(wp_unslash($_POST['request_id']??''));
    $target=Store::locked(static function()use($id,$decision){
     $r=Store::get('consent_'.$id);if(!$r||$r['owner']!==get_current_user_id()||$r['expires']<=time()||$r['used'])throw new ConnectorError('Consent expired.');
     $a=$r['request'];$r['used']=true;Store::put('consent_'.$id,'oauth_consent',$r);
     if($decision!=='allow')return add_query_arg(['error'=>'access_denied','state'=>$a['state'],'iss'=>self::issuer()],$a['redirect_uri']);
     self::persist_app($a['client_id']);
     $issued=Store::create_client('OAuth: '.$a['name'],$a['scopes'],get_current_user_id());$c=$issued['client'];$c['oauth_only']=true;$c['oauth_app_id']=$a['client_id'];Store::put($c['id'],'client',$c);
     $code=bin2hex(random_bytes(32));$record=['hash'=>hash('sha256',$code),'request'=>$a,'connection_id'=>$c['id'],'expires'=>time()+120,'used'=>false];
     Store::put(self::key('code',$code),'oauth_code',$record,true);Store::audit($c['id'],'oauth.consented');
     return add_query_arg(['code'=>$code,'state'=>$a['state'],'iss'=>self::issuer()],$a['redirect_uri']);
    });
    // Exact URI was validated at request creation; safe_redirect would reject authorized third-party callbacks.
$u=wp_parse_url($target);$origin=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".$origin."; frame-ancestors 'none'; base-uri 'none'");
    wp_redirect($target,302,'Nexdev OAuth');exit;
   }
   if(($_SERVER['REQUEST_METHOD']??'')!=='GET')throw new ValidationError('Method unsupported.');
   self::throttle('authorize');$keys=[];foreach(explode('&',$_SERVER['QUERY_STRING']??'') as $part){$key=urldecode(explode('=',$part,2)[0]);if(isset($keys[$key])||preg_match('/[\[\]\x00-\x20]/',$key))throw new ValidationError('Ambiguous parameter.');$keys[$key]=true;}$a=self::auth_request(wp_unslash($_GET));$u=wp_parse_url($a['redirect_uri']);$origin=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' ".$origin."; frame-ancestors 'none'; base-uri 'none'");$id=bin2hex(random_bytes(16));
   Store::put('consent_'.$id,'oauth_consent',['owner'=>get_current_user_id(),'request'=>$a,'expires'=>time()+300,'used'=>false],true);
   echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Authorize NexPilot connection</title><style>body{font:16px/1.6 system-ui,sans-serif;color:#17212b;background:#f6f7f7;max-width:680px;margin:48px auto;padding:24px;overflow-wrap:anywhere}h1{font-size:28px;line-height:1.2}code{font-size:14px}form{padding-top:16px}button{font:inherit;padding:12px 18px;border:1px solid #2271b1;border-radius:4px;cursor:pointer;margin:4px}button[value=allow]{background:#2271b1;color:#fff}button[value=deny]{background:#fff;color:#17212b}button:focus-visible{outline:3px solid #17212b;outline-offset:3px}@media(max-width:600px){body{margin:12px auto;padding:16px}}</style></head><body><h1>Authorize connection</h1><p>Application: <strong>'.esc_html($a['name']).'</strong> (self-reported, not verified)</p><p>Redirect: <code>'.esc_html($a['redirect_uri']).'</code></p><p>Site: '.esc_html(home_url()).'</p><p>Requested access: <strong>'.esc_html(implode(', ',$a['scopes'])).'</strong></p><p>Write operations still require separate human approval. Maintenance remains closed until opened separately. Connection expires in 30 days and can be revoked in Tools → NexPilot.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="nsc_oauth_authorize"><input type="hidden" name="request_id" value="'.esc_attr($id).'">';
   wp_nonce_field('nsc_oauth_consent');echo '<button name="decision" value="allow">Allow requested access</button> <button name="decision" value="deny">Deny</button></form></body></html>';exit;
  }catch(\Throwable $e){wp_die('Authorization request refused.','OAuth',['response'=>400]);}
 }
 private static function key(string $type,string $secret): string {return 'o_'.$type.'_'.substr(hash('sha256',$secret),0,54);}
 private static function secret($value): string {if(!is_string($value)||!preg_match('/^[a-f0-9]{64}$/D',$value))throw new ValidationError('Invalid token.');return $value;}
 private static function active(array $g): array {
  $c=Store::get($g['connection_id']);if(!$c||!empty($g['revoked'])||$g['expires']<=time()||!Policy::allowed($c,'read')||!user_can($c['user_id'],'manage_options')||!self::enabled())throw new ConnectorError('Grant unavailable.');return $c;
 }
 private static function issue(string $id,array $g): array {
  self::active($g);$access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));$expiry=min(time()+600,$g['expires']);
  Store::put(self::key('access',$access),'oauth_access',['hash'=>hash('sha256',$access),'grant_id'=>$id,'expires'=>$expiry,'resource'=>$g['resource'],'scopes'=>$g['scopes']],true);
  Store::put(self::key('refresh',$refresh),'oauth_refresh',['hash'=>hash('sha256',$refresh),'grant_id'=>$id,'expires'=>$g['expires'],'used'=>false],true);
  return ['access_token'=>'oat_'.$access,'token_type'=>'Bearer','expires_in'=>$expiry-time(),'refresh_token'=>$refresh,'scope'=>implode(' ',$g['scopes'])];
 }
 private static function token(array $a): array {
  Policy::fields($a,['grant_type','client_id','code','redirect_uri','code_verifier','resource','refresh_token','scope'],['grant_type','client_id','resource']);self::app($a['client_id']);
  if(($a['resource']??null)!==self::resource())throw new ConnectorError('Audience mismatch.');
  if($a['grant_type']==='authorization_code'){
   if(isset($a['refresh_token'])||isset($a['scope']))throw new ValidationError('Unexpected code-exchange parameter.');
   $code=self::secret($a['code']??null);$r=Store::get(self::key('code',$code));$v=$a['code_verifier']??'';
   if(!$r||!is_string($v)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$v)||!hash_equals($r['hash'],hash('sha256',$code)))throw new ConnectorError('Invalid code.');
   $req=$r['request'];$challenge=rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=');
   if($req['client_id']!==$a['client_id']||$req['redirect_uri']!==($a['redirect_uri']??null)||!hash_equals($req['code_challenge'],$challenge))throw new ConnectorError('Binding mismatch.');
   if($r['used']){if(isset($r['grant_id']))self::revoke_grant($r['grant_id']);throw new ConnectorError('Code replay.');}
   if($r['expires']<=time())throw new ConnectorError('Code expired.');
   $c=Store::get($r['connection_id']);$id='grant_'.bin2hex(random_bytes(16));$g=['connection_id'=>$c['id'],'app_id'=>$a['client_id'],'resource'=>self::resource(),'scopes'=>$req['scopes'],'expires'=>$c['expires'],'revoked'=>false];self::active($g);
   Store::audit($c['id'],'oauth.code_exchanged');$r['used']=true;$r['grant_id']=$id;Store::put(self::key('code',$code),'oauth_code',$r);Store::put($id,'oauth_grant',$g,true);return self::issue($id,$g);
  }
  if($a['grant_type']==='refresh_token'){
   if(isset($a['code'])||isset($a['code_verifier'])||isset($a['redirect_uri']))throw new ValidationError('Unexpected refresh parameter.');
   $token=self::secret($a['refresh_token']??null);$r=Store::get(self::key('refresh',$token));if(!$r||$r['expires']<=time()||!hash_equals($r['hash'],hash('sha256',$token)))throw new ConnectorError('Invalid refresh.');
   $g=Store::get($r['grant_id']);if(!$g||$g['app_id']!==$a['client_id'])throw new ConnectorError('Binding mismatch.');
   if($r['used']){self::revoke_grant($r['grant_id']);throw new ConnectorError('Refresh replay.');}self::active($g);
   if(isset($a['scope'])){$scopes=self::scopes($a['scope']);if(array_diff($scopes,$g['scopes']))throw new ConnectorError('Scope escalation.');$g['scopes']=$scopes;Store::put($r['grant_id'],'oauth_grant',$g);$c=self::active($g);$c['scopes']=array_values(array_intersect($c['scopes'],$scopes));if(!in_array('maintenance',$scopes,true)){$c['maintenance_until']=0;Access::revoke_sessions($c['id']);}Store::put($c['id'],'client',$c);}
   Store::audit($g['connection_id'],'oauth.refreshed');$r['used']=true;Store::put(self::key('refresh',$token),'oauth_refresh',$r);return self::issue($r['grant_id'],$g);
  }
  throw new ValidationError('Unsupported grant.');
 }
 public static function authenticate(string $token): array {
  if(!str_starts_with($token,'oat_'))throw new ConnectorError('Invalid access token.');
  $secret=self::secret(substr($token,4));$r=Store::get(self::key('access',$secret));if(!$r||$r['expires']<=time()||$r['resource']!==self::resource()||!hash_equals($r['hash'],hash('sha256',$secret)))throw new ConnectorError('Invalid access token.');
  $c=self::recheck_record($r);$c['_oauth_access_id']=self::key('access',$secret);return $c;
 }
 public static function reauthorize(array $c): array {
  $r=Store::get($c['_oauth_access_id']);if(!$r||$r['expires']<=time()||$r['resource']!==self::resource())throw new ConnectorError('Token expired.');return self::recheck_record($r);
 }
 private static function recheck_record(array $r): array {
  $g=Store::get($r['grant_id']);if(!$g||$g['resource']!==self::resource())throw new ConnectorError('Audience mismatch.');$c=self::active($g);$c['scopes']=array_values(array_intersect($c['scopes'],$g['scopes'],$r['scopes']));return $c;
 }
 private static function revoke_grant(string $id): void {$g=Store::get($id);if(!$g)return;$g['revoked']=true;Store::put($id,'oauth_grant',$g);$c=Store::get($g['connection_id']);if($c){$c['revoked']=true;$c['maintenance_until']=0;Store::put($c['id'],'client',$c);Access::revoke_sessions($c['id']);}}
 private static function revoke(array $a): array {
  Policy::fields($a,['token','client_id','token_type_hint'],['token','client_id']);self::app($a['client_id']);
  $token=$a['token'];if(!is_string($token))throw new ValidationError('Invalid token.');$access=str_starts_with($token,'oat_');$secret=$access?substr($token,4):$token;
  if(preg_match('/^[a-f0-9]{64}$/D',$secret)){$r=Store::get(self::key($access?'access':'refresh',$secret));$g=$r?Store::get($r['grant_id']):null;if($g&&$g['app_id']===$a['client_id']&&hash_equals($r['hash'],hash('sha256',$secret)))self::revoke_grant($r['grant_id']);}return [];
 }
}
