<?php
namespace Nexdev\SiteConnector;
final class Transfers {
 public static function validate(array $a): void {
  Policy::fields($a,['path','size','sha256'],['path','size','sha256']);
  if(!is_string($a['path'])||!is_int($a['size'])||$a['size']<1||$a['size']>16*1024*1024||!is_string($a['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$a['sha256']))throw new ValidationError('Upload needs path, size (1–16 MiB), and exact SHA-256.');
 }
 public static function create(array $client,array $a): array {
  $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));
  $r=['id'=>$id,'client_id'=>$client['id'],'credential_hash'=>$client['hash'],'owner_id'=>$client['user_id'],'hash'=>hash('sha256',$token),'expires'=>time()+300,'state'=>'ready','arguments'=>$a,'before'=>Files::snapshot('file_upload',$a)];
  Store::put('upload_'.$id,'upload',$r,true);
  return ['id'=>$id,'endpoint'=>rest_url('nexdev-connector/v1/upload/'.$id),'bearer_token'=>$token,'expires'=>$r['expires'],'state'=>'awaiting_exact_bytes','warning'=>'Token is sensitive. Send raw bytes with PUT; never place token in URL.'];
 }
 public static function register(): void {
  register_rest_route('nexdev-connector/v1','/upload/(?P<id>[a-f0-9]{32})',['methods'=>'PUT','permission_callback'=>[self::class,'permission'],'callback'=>[self::class,'receive']]);
 }
 private static function authorize(\WP_REST_Request $q): array {
  if(get_option('nsc_disabled',false)||(!is_ssl()&&!(defined('NSC_ALLOW_LOCAL_HTTP')&&NSC_ALLOW_LOCAL_HTTP&&wp_get_environment_type()==='local'))||$q->get_header('origin'))throw new ConnectorError('Transfer denied.');
  $r=Store::get('upload_'.$q['id']);
  if(!$r||$r['expires']<=time()||!preg_match('/^Bearer ([a-f0-9]{64})$/D',$q->get_header('authorization'),$m)||!hash_equals($r['hash'],hash('sha256',$m[1])))throw new ConnectorError('Invalid transfer token.');
  $c=Store::get($r['client_id']);if(!$c)throw new ConnectorError('Connection removed.');Policy::capability_binding($r,$c);Policy::maintenance($c);
  if(!user_can($c['user_id'],'manage_options'))throw new ConnectorError('Owner access removed.');
  return $r;
 }
 public static function permission(\WP_REST_Request $q): bool|\WP_Error {
  try{self::authorize($q);return true;}catch(\Throwable $e){return new \WP_Error('nsc_transfer_denied','Upload denied.',['status'=>403]);}
 }
 public static function receive(\WP_REST_Request $q): \WP_REST_Response|\WP_Error {
  try{return Store::locked(static function()use($q){
   $r=self::authorize($q);if($r['state']!=='ready')throw new ConnectorError('Transfer already consumed.');$bytes=$q->get_body();
   if(strlen($bytes)!==$r['arguments']['size']||!hash_equals($r['arguments']['sha256'],hash('sha256',$bytes)))throw new ConnectorError('Bytes do not match approved size/hash.');
   if(!hash_equals($r['before']['revision'],Files::snapshot('file_upload',$r['arguments'])['revision']))throw new ConnectorError('Destination changed since approval.');
   Store::audit($r['client_id'],'upload.started',$r['id']);$r['state']='running';Store::put('upload_'.$r['id'],'upload',$r);
   try{Files::apply('file_upload',['path'=>$r['arguments']['path'],'base64'=>base64_encode($bytes)]);$r['state']='done';Store::put('upload_'.$r['id'],'upload',$r);Store::audit($r['client_id'],'upload.done',$r['id']);}
   catch(\Throwable $e){$r['state']='uncertain';Store::put('upload_'.$r['id'],'upload',$r);throw new ConnectorError('Transfer result uncertain; inspect destination.');}
   return new \WP_REST_Response(['id'=>$r['id'],'state'=>$r['state']],200,['Cache-Control'=>'no-store']);
  });}catch(\Throwable $e){return new \WP_Error('nsc_transfer_failed','Upload failed: inspect token, size/hash, expiry and destination revision.',['status'=>409]);}
 }
}
