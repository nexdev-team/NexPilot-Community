<?php
namespace Nexdev\SiteConnector;
final class Files {
 public static function operations(): array {return array_fill_keys(['file_edit','file_delete','file_upload','directory_create','sandbox_disable','sandbox_enable'],'maintenance');}
 public static function validate(string $op,array $a): void {
  $keys=match($op){'file_edit'=>['path','old','new'],'file_upload'=>['path','base64'],default=>['path']};Policy::fields($a,$keys,$keys);
  foreach($a as $v)if(!is_string($v))throw new ValidationError('File arguments must be text.');
  if(strlen(Policy::canonical($a))>131072)throw new ValidationError('File operation exceeds 128 KiB. Use approved WP-CLI/PHP for larger transfers until streaming upload is available.');
  if($op==='file_edit'&&$a['old']==='')throw new ValidationError('old must not be empty.');
  if($op==='file_upload'&&base64_decode($a['base64'],true)===false)throw new ValidationError('Invalid base64.');
  if(in_array($op,['sandbox_disable','sandbox_enable'],true)&&(!str_starts_with($a['path'],'wp-content/nexdev-sandbox/')||!str_ends_with($a['path'],'.php')))throw new ValidationError('Only Nexdev sandbox PHP files can be enabled or disabled.');
 }
 public static function snapshot(string $op,array $a): array {
  $path=Policy::path($a['path'],false);
  if(is_link($path))throw new ConnectorError('Symlinks are not supported.');
  if(is_dir($path)){$entries=scandir($path);$data=['directory'=>true,'entries'=>$entries];}
  else {$data=file_exists($path)?['sha256'=>hash_file('sha256',$path),'size'=>filesize($path)]:null;}
  if(in_array($op,['sandbox_disable','sandbox_enable'],true))$data=['file'=>$data,'disabled'=>file_exists($path.'.disabled-marker')];
  return ['data'=>$data,'revision'=>Policy::fingerprint($data)];
 }
 public static function apply(string $op,array $a): array {
  $path=Policy::path($a['path'],false);
  if($op==='directory_create'){if(file_exists($path)||!mkdir($path,0755))throw new ConnectorError('Directory creation failed.');}
  elseif($op==='file_delete'){
   // No recursive directory deletion and no core bootstrap removal through this tool.
   if(in_array($a['path'],['wp-config.php','wp-load.php','wp-settings.php','index.php','wp-content','wp-includes','wp-admin'],true))throw new ConnectorError('Protected path.');
   if(file_exists($path)&&!(is_dir($path)?rmdir($path):unlink($path)))throw new ConnectorError('Delete failed; directories must be empty.');
  }elseif(in_array($op,['sandbox_disable','sandbox_enable'],true)){
   if(!is_file($path))throw new ConnectorError('Sandbox file does not exist.');
   $marker=$path.'.disabled-marker';
   if($op==='sandbox_disable'){if(file_put_contents($marker,'')===false)throw new ConnectorError('Disable failed.');}
   elseif(file_exists($marker)&&!unlink($marker))throw new ConnectorError('Enable failed.');
  }else{
   if($op==='file_upload')$bytes=base64_decode($a['base64'],true);
   else{
    if(!is_file($path)||filesize($path)>131072)throw new ConnectorError('Edit requires a small existing file.');
    $bytes=file_get_contents($path);if(substr_count($bytes,$a['old'])!==1)throw new ConnectorError('old text must match exactly once.');$bytes=str_replace($a['old'],$a['new'],$bytes);
   }
   $tmp=tempnam(dirname($path),'.nsc-');if(!$tmp)throw new ConnectorError('Cannot stage file.');
   try{if(file_put_contents($tmp,$bytes)!==strlen($bytes))throw new ConnectorError('Incomplete write.');chmod($tmp,file_exists($path)?fileperms($path)&0777:0644);if(!rename($tmp,$path))throw new ConnectorError('Replace failed.');}finally{if(file_exists($tmp))unlink($tmp);}
  }
  return self::snapshot($op,$a);
 }
 public static function load_sandbox(): void {
  $root=WP_CONTENT_DIR.'/nexdev-sandbox';if(!is_dir($root))return;
  foreach(glob($root.'/*.php')?:[] as $file){
   if(is_link($file)||file_exists($file.'.disabled-marker'))continue;
   $state=(object)['complete'=>false];
   register_shutdown_function(static function()use($file,$state){
    $error=error_get_last();if(!$state->complete&&$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))file_put_contents($file.'.disabled-marker','');
   });
   try{include_once $file;}
   catch(\Throwable $e){file_put_contents($file.'.disabled-marker','');update_option('nsc_sandbox_error',['file'=>basename($file),'at'=>time()],false);}
   finally{$state->complete=true;}
  }
 }
}
