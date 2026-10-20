<?php
namespace Nexdev\SiteConnector;
final class Media {
 public static function validate(array $a): void {
  Policy::fields($a,['path','title','parent'],['path','title']);
  if(!is_string($a['path'])||!is_string($a['title']))throw new ValidationError('path/title must be text.');if(isset($a['parent']))Policy::id($a['parent']);
  $path=Policy::path($a['path']);$uploads=wp_upload_dir();$root=realpath($uploads['basedir']);
  if(!$root||!str_starts_with($path,$root.'/')||!is_file($path)||filesize($path)>16*1024*1024)throw new ConnectorError('Import only existing files within uploads, up to 16 MiB.');
  if(!current_user_can('upload_files'))throw new ConnectorError('Upload capability required.');
 }
 public static function apply(array $a): array {
  require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
  $path=Policy::path($a['path']);$temp=wp_tempnam(basename($path));if(!$temp||!copy($path,$temp))throw new ConnectorError('Cannot stage media import.');
  try{$id=media_handle_sideload(['name'=>basename($path),'tmp_name'=>$temp],$a['parent']??0,$a['title']);if(is_wp_error($id))throw new ConnectorError('WordPress rejected the media file.');return ['id'=>$id,'url'=>wp_get_attachment_url($id)];}finally{if(is_file($temp))wp_delete_file($temp);}
 }
}
