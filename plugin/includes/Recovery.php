<?php
namespace Nexdev\SiteConnector;
/** Reverts only known reversible fields, never orders, payments, emails or arbitrary code. */
final class Recovery {
 public static function source(array $client,string $id): array {
  $p=Store::get($id);
  if(!$p||($p['client_id']??'')!==$client['id']||$p['state']!=='done'||!in_array($p['operation'],['content_update','product_update','file_write'],true))throw new ConnectorError('Only your completed content/product/file updates support structured restore.');
  return $p;
 }
 public static function snapshot(array $client,string $id): array {
  $p=self::source($client,$id);$a=$p['arguments'];
  if($p['operation']==='content_update')$current=Tools::content($a['id']);
  elseif($p['operation']==='product_update')$current=Tools::product($a['id']);
  else{$path=Policy::path($a['path'],false);$current=file_exists($path)?hash_file('sha256',$path):null;}
  return ['source_id'=>$id,'source_digest'=>$p['digest'],'current'=>$current,'revision'=>Policy::fingerprint([$p['digest'],$current])];
 }
 public static function apply(array $client,string $id): array {
  $p=self::source($client,$id);$a=$p['arguments'];
  if($p['operation']==='content_update'){
   $map=['title'=>'post_title','content'=>'post_content','excerpt'=>'post_excerpt','status'=>'post_status','slug'=>'post_name'];$data=['ID'=>$a['id']];foreach(array_keys($a['fields']) as $k)$data[$map[$k]]=$p['before'][$k];
   $result=wp_update_post(wp_slash($data),true);if(is_wp_error($result))throw new ConnectorError('Content restore failed.');return Tools::content($a['id']);
  }
  if($p['operation']==='product_update'){
   $product=wc_get_product($a['id']);foreach(array_keys($a['fields']) as $k){$method='set_'.$k;$product->$method($p['before'][$k]);}$product->save();return Tools::product($a['id']);
  }
  if($p['before']['content']===null)return Files::apply('file_delete',['path'=>$a['path']]);
  return Files::apply('file_upload',['path'=>$a['path'],'base64'=>base64_encode($p['before']['content'])]);
 }
}
