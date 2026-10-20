<?php
namespace Nexdev\SiteConnector;
final class Jobs {
 public static function start(array $client,array $a): array {
  if(!defined('NSC_PHP_CLI')||!is_executable(NSC_PHP_CLI)||!function_exists('proc_open')||PHP_OS_FAMILY==='Windows')throw new ConnectorError('Background worker requires configured PHP CLI on POSIX.');
  $id=bin2hex(random_bytes(16));$job=['id'=>$id,'client_id'=>$client['id'],'credential_hash'=>$client['hash'],'owner_id'=>$client['user_id'],'state'=>'queued','arguments'=>$a,'created'=>time(),'expires'=>min(time()+600,$client['maintenance_until'])];
  Store::put('job_'.$id,'job',$job,true);Store::audit($client['id'],'job.queued',$id);
  $cmd=escapeshellarg(NSC_PHP_CLI).' -d display_errors=0 '.escapeshellarg(__DIR__.'/worker.php').' '.escapeshellarg(ABSPATH).' '.escapeshellarg($id).' > /dev/null 2>&1 &';
  $p=proc_open(['/bin/sh','-c',$cmd],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
  if(!is_resource($p)||proc_close($p)!==0){$job['state']='failed_to_start';Store::put('job_'.$id,'job',$job);throw new ConnectorError('Worker could not start.');}
  return ['job_id'=>$id,'state'=>'queued'];
 }
 public static function run(string $id): void {
  $job=Store::get('job_'.$id);if(!$job||$job['state']!=='queued')return;
  // The launching request owns the lock until its proposal is committed. Retry lock only, never effects.
  for($i=0;$i<100;$i++){
   $entered=false;
   try{Store::locked(static function()use($id,&$entered){
    $entered=true;$job=Store::get('job_'.$id);if(!$job||$job['state']!=='queued')return;
    $c=Store::get($job['client_id']);
    try{
     if(!$c||!is_string($job['credential_hash']??null)||!hash_equals($c['hash']??'',$job['credential_hash'])||($job['owner_id']??null)!==$c['user_id'])throw new ConnectorError('Queued job authorization changed.');
     if(get_option('nsc_disabled',false)||$job['expires']<=time())throw new ConnectorError('Job expired or connector disabled.');Policy::maintenance($c);
     if(!user_can($c['user_id'],'manage_options'))throw new ConnectorError('Owner access removed.');wp_set_current_user($c['user_id']);
     Store::audit($c['id'],'job.started',$id);$job['state']='running';Store::put('job_'.$id,'job',$job);
     $job['result']=Maintenance::wp_cli($job['arguments']['argv']);$job['state']=$job['result']['exit_code']===0?'done':'uncertain';
    }catch(\Throwable $e){$job['state']=($job['state']==='running')?'uncertain':'cancelled';$job['error']='Job did not complete; inspect effects before retry.';}
    Store::put('job_'.$id,'job',$job);Store::audit($job['client_id'],'job.'.$job['state'],$id);
   });return;}catch(\Throwable $e){if($entered)throw $e;usleep(100000);}
  }
  // Leave queued, not falsely failed: another writer may be active. Admin can inspect/cancel.
 }
 public static function get(array $client,string $id): array {
  $job=Store::get('job_'.$id);if(!$job||$job['client_id']!==$client['id'])throw new ConnectorError('Job not found.');
  return array_intersect_key($job,array_flip(['id','state','created','expires','result','error']));
 }
}
