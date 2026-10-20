<?php
namespace Nexdev\SiteConnector;
final class Maintenance {
 public static function wp_cli(array $args): array {
  if(!defined('NSC_PHP_CLI')||!defined('NSC_WP_CLI_PHAR')||!is_executable(NSC_PHP_CLI)||!is_file(NSC_WP_CLI_PHAR)||!function_exists('proc_open'))throw new ConnectorError('WP-CLI is unavailable. Configure NSC_PHP_CLI and NSC_WP_CLI_PHAR on the staging server.');
  // Argument array bypasses shell interpolation. This remains unrestricted admin access.
  $command=array_merge([NSC_PHP_CLI,NSC_WP_CLI_PHAR,'--path='.ABSPATH,'--no-color'], $args);
  $env=null;
  if(defined('NSC_WP_CLI_CONFIG')){$env=getenv();$env['WP_CLI_CONFIG_PATH']=NSC_WP_CLI_CONFIG;$env['WP_CLI_CACHE_DIR']=dirname(NSC_WP_CLI_CONFIG).'/wp-cli-cache';$env['WP_CLI_PACKAGES_DIR']=dirname(NSC_WP_CLI_CONFIG).'/wp-cli-packages';}
  $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ABSPATH,$env,['bypass_shell'=>true]);
  if(!is_resource($process))throw new ConnectorError('Cannot start WP-CLI.');
  fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
  $output='';$error='';$start=microtime(true);$status=null;
  try{
   do{
    $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
    if(strlen($output)+strlen($error)>65536||microtime(true)-$start>30){proc_terminate($process,9);throw new ConnectorError('WP-CLI limit exceeded; inspect site before retrying. Descendant processes may still run.');}
    $status=proc_get_status($process);if($status['running'])usleep(10000);
   }while($status['running']);
   $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
   if(strlen($output)+strlen($error)>65536)throw new ConnectorError('WP-CLI output limit exceeded.');
   return ['exit_code'=>$status['exitcode'],'stdout'=>$output,'stderr'=>$error];
  }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
 }
}
