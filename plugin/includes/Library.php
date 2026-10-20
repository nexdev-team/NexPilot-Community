<?php
namespace Nexdev\SiteConnector;
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/Design.php';
final class Library {
 public static function operations(): array {return ['skill_save'=>'content','skill_delete'=>'content','design_save'=>'content','design_draft_save'=>'content','design_draft_delete'=>'content','design_delete'=>'content','design_activate'=>'content'];}
 public static function key(string $kind,string $slug): string {
  if(!in_array($kind,['skill','design','design_draft'],true)||!preg_match('/^[a-z][a-z0-9-]{0,39}$/D',$slug))throw new ValidationError('Invalid library kind or slug.');
  return $kind.'_'.$slug;
 }
 public static function validate(string $op,array $a): void {
  $kind=str_starts_with($op,'skill')?'skill':(str_starts_with($op,'design_draft_')?'design_draft':'design');$save=str_ends_with($op,'save');
  Policy::fields($a,$save?['slug','document']:['slug'],$save?['slug','document']:['slug']);self::key($kind,$a['slug']);
  if($save&&(!is_string($a['document'])||strlen($a['document'])>65536))throw new ValidationError('Document must be text up to 64 KiB.');

 }
 public static function design(string $raw): array {
  $p=Design::parse($raw);if(!$p['readiness']['ready'])throw new ValidationError('Design is not ready. Inspect design readiness errors.');return $p['contract'];
 }
 public static function snapshot(string $op,array $a): array {
  $kind=str_starts_with($op,'skill')?'skill':(str_starts_with($op,'design_draft_')?'design_draft':'design');$record=Store::get(self::key($kind,$a['slug']));
  $data=in_array($op,['design_activate','design_save'],true)?['target'=>$record,'active'=>get_option('nsc_active_design',''),'draft'=>Store::get(self::key('design_draft',$a['slug']))]:$record;
  return ['data'=>$data,'revision'=>Policy::fingerprint($data)];
 }
 public static function apply(string $op,array $a): array {
  $kind=str_starts_with($op,'skill')?'skill':(str_starts_with($op,'design_draft_')?'design_draft':'design');$key=self::key($kind,$a['slug']);
  if($op==='design_save'&&!Design::parse($a['document'])['readiness']['ready']){$kind='design_draft';$key=self::key($kind,$a['slug']);}
  if(str_ends_with($op,'save'))Store::put($key,$kind,['slug'=>$a['slug'],'document'=>$a['document'],'readiness'=>$kind==='design_draft'?'draft':'ready','updated'=>time()]);
  elseif($op==='design_activate'){
   $record=Store::get($key);if(!$record)throw new ConnectorError('Design not found.');self::design($record['document']);update_option('nsc_active_design',$a['slug'],false);
  }else {Store::delete($key);if($kind==='design'&&get_option('nsc_active_design','')===$a['slug'])delete_option('nsc_active_design');}
  return ['slug'=>$a['slug'],'operation'=>$op,'stored_kind'=>$kind];
 }
 public static function check(string $content,array $context=[]): array {
  $slug=get_option('nsc_active_design','');$record=$slug?Store::get(self::key('design',$slug)):null;
  if(!$record)throw new ConnectorError('No active design.');return Design::check($record,$content,$context);
 }
}
