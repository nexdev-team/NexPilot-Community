<?php
namespace Nexdev\SiteConnector;
final class Admin {
 public static function enqueue(string $hook): void {
  if ($hook !== 'tools_page_nsc' || !current_user_can('manage_options')) return;
  wp_enqueue_script('nsc-blocks-review', plugins_url('../assets/blocks-review.js', __FILE__), [], NSC_VERSION, true);
  wp_add_inline_script('nsc-blocks-review', 'window.nscBlocksEditor=' . wp_json_encode(admin_url('post.php')) . ';', 'before');
 }
 public static function menu(): void {add_management_page('NexPilot Beta','NexPilot Beta','manage_options','nsc',[self::class,'page']);}
 private static function guard(): void {if(!current_user_can('manage_options'))wp_die('Administrator access required.',403);}
 private static function form(string $action,string $id='',string $button='Submit'): void {
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:4px">';
  wp_nonce_field('nsc_action');echo '<input type="hidden" name="action" value="nsc_action"><input type="hidden" name="operation" value="'.esc_attr($action).'"><input type="hidden" name="id" value="'.esc_attr($id).'">';
  submit_button($button,'secondary','submit',false);echo '</form>';
 }
 public static function page(): void {
  self::guard();
  echo '<style>.wrap pre{max-width:100%;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}.wrap code{overflow-wrap:anywhere}.wrap input{max-width:100%}.wrap details{box-sizing:border-box;min-width:0}.wrap form{max-width:100%}</style>';
  echo '<div class="wrap"><h1>NexPilot Beta <small>'.esc_html(NSC_VERSION).'</small></h1><div class="notice notice-warning inline"><p>Beta edition — still under testing. Validate each site in staging before deployment. Full maintenance grants unrestricted WordPress access.</p></div>';
  echo '<p>MCP endpoint: <code>'.esc_html(rest_url('nexdev-connector/v1/mcp')).'</code></p><p>Use a separate connection for each AI client. Credentials expire after 30 days. Start with Read only.</p>';
  self::form(get_option('nsc_disabled',false)?'enable':'disable','',get_option('nsc_disabled',false)?'Enable connector':'Disable all connections');
  echo '<h2>OAuth connections</h2><p>Administrator consent and PKCE required. Enabling OAuth allows public client registration; every connection still requires your consent.</p>';
  self::form(get_option('nsc_oauth_enabled',false)?'oauth_disable':'oauth_enable','',get_option('nsc_oauth_enabled',false)?'Disable OAuth':'Enable OAuth');
  self::form('prune','','Remove expired records older than 30 days');
  echo '<h2>Uninstall data policy</h2><p>Default: keep connector records and audit after deletion. Optional removal deletes connections, proposals, libraries and audit on uninstall. Site content, uploads and sandbox files remain. Network uninstall retains data.</p>';
  self::form(get_option('nsc_delete_on_uninstall',false)?'retain_data':'delete_data','',get_option('nsc_delete_on_uninstall',false)?'Keep data on uninstall':'Remove connector data on uninstall');
  self::form('diagnostics','','Run read-only integration checks');
  echo '<h2>Create connection</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('nsc_action');
  echo '<input type="hidden" name="action" value="nsc_action"><input type="hidden" name="operation" value="create"><label>Client name <input required maxlength="80" name="label" placeholder="Claude Desktop"></label><p>Read access is included.</p>';
  foreach(['content'=>'Edit content','commerce'=>'WooCommerce operations','maintenance'=>'Allow temporary full maintenance'] as $v=>$label)echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="'.esc_attr($v).'">'.esc_html($label).'</label>';
  submit_button('Create connection');echo '</form><h2>Connections</h2>';
  foreach(Store::all('client') as $c){
   echo '<div style="border:1px solid #ccc;padding:12px;margin:8px 0"><strong>'.esc_html($c['label']).'</strong> <code>'.esc_html($c['id']).'</code><p>'.esc_html(implode(', ',$c['scopes'])).' | '.esc_html($c['revoked']?'Revoked':'Expires '.gmdate('Y-m-d H:i',$c['expires']).' UTC').'</p>';
   if(!$c['revoked']){if(empty($c['oauth_only']))self::form('rotate',$c['id'],'Rotate credential');self::form('revoke',$c['id'],'Revoke connection');if(in_array('maintenance',$c['scopes'],true)){self::form('open',$c['id'],'Open full maintenance for 10 minutes');self::form('close',$c['id'],'Close maintenance');echo '<p>Maintenance until: '.esc_html($c['maintenance_until']?gmdate('Y-m-d H:i:s',$c['maintenance_until']).' UTC':'closed').'</p>';}}
   echo '</div>';
  }
  echo '<h2>Recent proposed changes</h2><p>Review the exact arguments and previous state. Approvals expire 30 minutes after proposal creation. Order status changes may send emails; PHP can have irreversible side effects.</p>';
  foreach(Store::all('proposal') as $p){
   echo '<details style="border:1px solid #ccc;padding:12px;margin:8px 0"><summary>'.esc_html($p['operation'].' — '.$p['state'].' — '.$p['id']).'</summary><p>Client: '.esc_html($p['client_id']).' | Expires: '.esc_html(gmdate('Y-m-d H:i:s',$p['expires'])).' UTC</p><pre style="white-space:pre-wrap;max-height:350px;overflow:auto">'.esc_html(wp_json_encode(['arguments'=>$p['arguments'],'before'=>$p['before']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
   if($p['state']==='pending'&&$p['expires']>time()){
    if($p['operation']==='blocks_batch'){
     echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-nsc-blocks="'.esc_attr(wp_json_encode($p['arguments']['targets'])).'">';wp_nonce_field('nsc_action');
     echo '<input type="hidden" name="action" value="nsc_action"><input type="hidden" name="operation" value="approve_blocks"><input type="hidden" name="id" value="'.esc_attr($p['id']).'"><input type="hidden" name="serialized" value=""><button type="submit" class="button" disabled>Validate in native editor and approve</button><p role="status"></p></form>';
    }else self::form('approve',$p['id'],'Approve this exact change');
    self::form('reject',$p['id'],'Reject');
   }
   echo '</details>';
  }

  global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT 50',Store::table('audit')),ARRAY_A);
  echo '<h2>Recent audit events</h2><pre>'.esc_html(wp_json_encode($rows,JSON_PRETTY_PRINT)).'</pre><p>Audit is local. It is not tamper-proof against a full administrator.</p></div>';
 }
 public static function action(): void {
  self::guard();check_admin_referer('nsc_action');
  try {
   $op=sanitize_key($_POST['operation']??'');$id=sanitize_text_field(wp_unslash($_POST['id']??''));
   if($op==='diagnostics'){
    $checks=[];
    $probe=static function($name,$fn)use(&$checks){try{$checks[$name]=['status'=>'ok','result'=>$fn()];}catch(\Throwable $e){$checks[$name]=['status'=>'unavailable','message'=>$e instanceof ConnectorError?$e->getMessage():'Integration check failed.'];}};
    $probe('storage_mutex',static fn()=>Store::locked(static fn()=>['backend'=>defined('NSC_SQLITE_LOCK_FILE')?'sqlite-single-host':'mysql-advisory','schema'=>(int)get_option('nsc_schema_version',0),'sodium'=>function_exists('sodium_crypto_secretbox')]));
    foreach(['bundle','composite'] as $kind)$probe($kind,static function()use($kind){$rows=Integrations::commerce(['kind'=>$kind]);return ['products_read'=>count($rows),'detail_records'=>array_sum(array_map(static fn($r)=>count($r[$kind==='bundle'?'items':'components']??[]),$rows))];});
    $probe('minmax',static function(){ $rules=Integrations::commerce(['kind'=>'minmax']);$products=wc_get_products(['limit'=>1,'type'=>'simple']);if($products)$rules=Integrations::commerce(['kind'=>'minmax','id'=>$products[0]->get_id()]);return ['stored_rule_count'=>count($rules['stored_rules']),'classic_quantity_input'=>$rules['classic_quantity_input']];});
    $probe('wpforms',static function(){$forms=Integrations::forms(['action'=>'list']);if($forms)Integrations::forms(['action'=>'get','id'=>$forms[0]['id']]);return ['forms_read'=>count($forms)];});
    $probe('wpforms_entry_schema',static fn()=>Integrations::forms(['action'=>'stats','form_id'=>2147483647]));
    nocache_headers();wp_die('<h1>Read-only integration checks</h1><p>No products, forms, entries or settings were changed. No customer data is displayed. A successful zero-record check verifies availability, not populated data.</p><pre>'.esc_html(wp_json_encode($checks,JSON_PRETTY_PRINT)).'</pre><p><a href="'.esc_url(admin_url('tools.php?page=nsc')).'">Back to connector</a></p>','Integration checks',['response'=>200]);
   }
   if(in_array($op,['create','rotate'],true)){
    $scopes=['read'];foreach(array_map('sanitize_key',(array)wp_unslash($_POST['scopes']??[])) as $s)if(in_array($s,['content','commerce','maintenance'],true))$scopes[]=$s;
    $result=Store::locked(fn()=>$op==='rotate'?Store::rotate_client($id):Store::create_client(sanitize_text_field(wp_unslash($_POST['label']??'')),$scopes,get_current_user_id()));
    nocache_headers();header('Referrer-Policy: no-referrer');
    wp_die('<h1>Connection created</h1><p>Copy this credential now. It is displayed only once. Store it in a local token file with owner-only permissions; never put it in a URL or chat.</p><textarea readonly rows="3" cols="100">'.esc_textarea($result['token']).'</textarea><p><a href="'.esc_url(admin_url('tools.php?page=nsc')).'">Back to connector</a></p>','Connection created',['response'=>200]);
   }
   // Keep nonce-protected input decoding outside the storage callback. Preserve markup bytes.
   $serialized = $op === 'approve_blocks' ? wp_unslash($_POST['serialized'] ?? '') : '';
   if (!is_string($serialized) || strlen($serialized) > 131072) throw new ValidationError('Invalid serialized batch.');
   $targets = $serialized === '' ? null : json_decode($serialized, true);
   Store::locked(static function()use($op,$id,$targets){
    if(in_array($op,['retain_data','delete_data'],true)){Store::audit('human:'.get_current_user_id(),'retention.'.$op);update_option('nsc_delete_on_uninstall',$op==='delete_data',false);return;}
    if(in_array($op,['oauth_enable','oauth_disable'],true)){update_option('nsc_oauth_enabled',$op==='oauth_enable',false);if($op==='oauth_disable'){foreach(Store::records('client') as $c)if(!empty($c['oauth_only'])){$c['revoked']=true;$c['maintenance_until']=0;Store::put($c['id'],'client',$c);Access::revoke_sessions($c['id']);}}Store::audit('human:'.get_current_user_id(),'oauth.'.$op);return;}
    if($op==='prune'){Store::prune();Store::audit('human:'.get_current_user_id(),'retention.pruned');return;}
    if(in_array($op,['disable','enable'],true)){update_option('nsc_disabled',$op==='disable',false);if($op==='disable')Access::revoke_sessions();Store::audit('human:'.get_current_user_id(),'connector.'.$op);return;}
    $record=Store::get($id);if(!$record)throw new ConnectorError('Record not found.');
    if(in_array($op,['revoke','open','close'],true)){
     if(!isset($record['hash']))throw new ConnectorError('Not a connection.');
     if($op==='revoke'){Access::revoke_sessions($id);$record['revoked']=true;$record['maintenance_until']=0;}
     if($op==='open'){Policy::require_scope($record,'maintenance');$record['maintenance_until']=time()+600;}
     if($op==='close'){$record['maintenance_until']=0;Access::revoke_sessions($id);}
     Store::put($id,'client',$record);
    }elseif(in_array($op,['approve','approve_blocks','reject'],true)){
     if(($record['state']??'')!=='pending'||$record['expires']<=time())throw new ConnectorError('Proposal is no longer pending.');
     if($record['operation']==='blocks_batch'&&$op==='approve')throw new ConnectorError('Use native editor validation for block batches.');
     if($op==='approve_blocks'){
      if($record['operation']!=='blocks_batch')throw new ConnectorError('Not a block batch.');
      if(!is_array($targets)||count($targets)!==count($record['arguments']['targets']))throw new ConnectorError('Invalid serialized batch.');
      Blocks::validate(['targets'=>$targets]);
      foreach($targets as $i=>$t){if($t['id']!==$record['arguments']['targets'][$i]['id'])throw new ConnectorError('Target mismatch.');}
      if(Policy::fingerprint($targets)!==Policy::fingerprint($record['arguments']['targets'])){
       // Re-serialization changed bytes. Store the normalized proposal but require a second human review.
       $record['arguments']['targets']=$targets;$record['digest']=Policy::fingerprint([$record['operation'],$record['arguments'],$record['before']['revision']]);$record['editor_validated']=true;
       Store::put($id,'proposal',$record);Store::audit('human:'.get_current_user_id(),'blocks.normalized_review_again',$id);return;
      }
      $record['editor_validated']=true;
     }
     $record['state']=in_array($op,['approve','approve_blocks'],true)?'approved':'rejected';$record['approved_by']=get_current_user_id();Store::put($id,'proposal',$record);
    }else throw new ConnectorError('Unknown admin action.');
    Store::audit('human:'.get_current_user_id(),'admin.'.$op,$id);
   });
   wp_safe_redirect(admin_url('tools.php?page=nsc'));exit;
  }catch(\Throwable $e){wp_die(esc_html('Action refused: '.$e->getMessage()),'Connector',['response'=>400]);}
 }
}
