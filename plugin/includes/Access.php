<?php
namespace Nexdev\SiteConnector;
/** Approved one-time POST exchange. Never places credentials in a login URL. */
final class Access {
 public static function create(array $client): array {
  $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));
  Store::put('access_'.$id,'access',['id'=>$id,'client_id'=>$client['id'],'credential_hash'=>$client['hash'],'owner_id'=>$client['user_id'],'hash'=>hash('sha256',$secret),'expires'=>min(time()+60,$client['maintenance_until']),'state'=>'ready'],true);
  return ['endpoint'=>admin_url('admin-post.php'),'method'=>'POST','fields'=>['action'=>'nsc_exchange','ticket'=>$id.'.'.$secret],'expires_in_seconds'=>60,'warning'=>'Post from the browser to set a temporary admin cookie. Do not put ticket in URL or logs. This grants full admin access.'];
 }
 public static function exchange(): void {
  nocache_headers();header('Referrer-Policy: no-referrer');
  try{
   if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||get_option('nsc_disabled',false)||(!is_ssl()&&!(defined('NSC_ALLOW_LOCAL_HTTP')&&NSC_ALLOW_LOCAL_HTTP&&wp_get_environment_type()==='local')))throw new ConnectorError('Exchange denied.');
   $origin=wp_unslash($_SERVER['HTTP_ORIGIN']??'');$expected=wp_parse_url(home_url());$siteOrigin=$expected['scheme'].'://'.$expected['host'].(isset($expected['port'])?':'.$expected['port']:'');
   if($origin!==''&&$origin!==$siteOrigin)throw new ConnectorError('Origin denied.');
   $ticket=wp_unslash($_POST['ticket']??'');if(!is_string($ticket)||!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D',$ticket,$m))throw new ConnectorError('Invalid ticket.');
   $sessionInfo=Store::locked(static function()use($m){
    $r=Store::get('access_'.$m[1]);if(!$r||$r['state']!=='ready'||$r['expires']<=time()||!hash_equals($r['hash'],hash('sha256',$m[2])))throw new ConnectorError('Ticket unavailable.');
    $c=Store::get($r['client_id']);if(!$c)throw new ConnectorError('Connection removed.');Policy::capability_binding($r,$c);Policy::maintenance($c);if(!user_can($c['user_id'],'manage_options'))throw new ConnectorError('Owner access removed.');
    Store::audit($c['id'],'admin_access.exchanged',$r['id']);$r['state']='consumed';Store::put('access_'.$r['id'],'access',$r);
    $duration=max(1,min(600,$c['maintenance_until']-time()));
    $session=\WP_Session_Tokens::get_instance($c['user_id'])->create(time()+$duration);
    Store::put('browser_'.substr(hash('sha256',$session),0,56),'browser',['client_id'=>$c['id'],'user_id'=>$c['user_id'],'session'=>$session,'expires'=>time()+$duration]);
    return ['client'=>$c,'duration'=>$duration,'session'=>$session];
   });
   $client=$sessionInfo['client'];$duration=$sessionInfo['duration'];
   add_filter('auth_cookie_expiration',static fn()=>$duration);
   wp_set_auth_cookie($client['user_id'],false,is_ssl(),$sessionInfo['session']);
   wp_safe_redirect(admin_url());exit;
  }catch(\Throwable $e){wp_die('Temporary access denied.','Connector',['response'=>403]);}
 }
 public static function validate_session($user_id) {
  if(!$user_id)return $user_id;
  foreach([is_ssl()?'secure_auth':'auth','logged_in'] as $scheme){
   $cookie=wp_parse_auth_cookie('', $scheme);if(!$cookie||wp_validate_auth_cookie('', $scheme)!==$user_id)continue;
   $r=Store::get('browser_'.substr(hash('sha256',$cookie['token']),0,56));if(!$r)return $user_id;
   $c=Store::get($r['client_id']);
   if(get_option('nsc_disabled',false)||!$c||!Policy::allowed($c,'maintenance')||$c['maintenance_until']<=time()||$r['expires']<=time())return 0;
   return $user_id;
  }
  return $user_id;
 }
 public static function deactivate(): void {
  update_option('nsc_disabled',true,false);
  self::revoke_sessions();
  foreach(Store::records('client') as $id=>$client){$client['maintenance_until']=0;Store::put($id,'client',$client);}
 }
 public static function revoke_sessions(?string $client_id=null): void {
  foreach(Store::records('browser') as $r){if($client_id===null||$r['client_id']===$client_id)\WP_Session_Tokens::get_instance($r['user_id'])->destroy($r['session']);}
 }
}
