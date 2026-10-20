<?php
namespace Nexdev\SiteConnector;
final class Blocks {
 public static function enqueue(string $hook): void {
  if(!in_array($hook,['post.php','post-new.php'],true)||!current_user_can('manage_options'))return;
  wp_enqueue_script('nsc-editor-heartbeat',plugins_url('../assets/editor-heartbeat.js',__FILE__),[],NSC_VERSION,true);
  wp_add_inline_script('nsc-editor-heartbeat','window.nscEditorRuntime='.wp_json_encode(['endpoint'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('nsc_editor_runtime')]).';','before');
 }
 public static function heartbeat(): void {
  if(!current_user_can('manage_options'))wp_send_json_error([],403);check_ajax_referer('nsc_editor_runtime');
  set_transient('nsc_editor_runtime',['seen_at'=>time()],60);wp_send_json_success();
 }
 public static function validate(array $a): void {
  Policy::fields($a,['targets'],['targets']);
  if(!is_array($a['targets'])||!array_is_list($a['targets'])||count($a['targets'])<1||count($a['targets'])>20)throw new ValidationError('targets must contain 1–20 posts.');
  $ids=[];
  foreach($a['targets'] as $target){Policy::fields($target,['id','content'],['id','content']);Policy::id($target['id']);if(isset($ids[$target['id']]))throw new ValidationError('Duplicate target.');$ids[$target['id']]=true;
   if(!is_string($target['content']))throw new ValidationError('Serialized block markup required.');
   self::check(parse_blocks($target['content']));
  }
  if(strlen(Policy::canonical($a))>131072)throw new ValidationError('Batch too large.');
 }
 private static function check(array $blocks): void {
  foreach($blocks as $b){if($b['blockName']!==null&&!\WP_Block_Type_Registry::get_instance()->is_registered($b['blockName']))throw new ValidationError('Unregistered server block.');self::check($b['innerBlocks']??[]);}
 }
 public static function snapshot(array $a): array {
  $data=array_map(static fn($t)=>Tools::content($t['id']),$a['targets']);return ['targets'=>$data,'revision'=>Policy::fingerprint($data)];
 }
 public static function apply(array $a): array {
  $done=[];foreach($a['targets'] as $t){$id=wp_update_post(wp_slash(['ID'=>$t['id'],'post_content'=>$t['content']]),true);if(is_wp_error($id))throw new ConnectorError('Batch partially failed. Inspect all targets.');$done[]=Tools::content($id);}
  return ['targets'=>$done,'validation'=>'Server registration checked. Use the editor validator before proposing static block markup.'];
 }
 public static function types(?string $name=null): array {
  $registry=\WP_Block_Type_Registry::get_instance();$types=$name?[$name=>$registry->get_registered($name)]:$registry->get_all_registered();$out=[];
  foreach($types as $key=>$t){if($t)$out[$key]=['name'=>$key,'attributes'=>$t->get_attributes(),'supports'=>$t->supports,'dynamic'=>$t->is_dynamic()];}return $out;
 }
}
