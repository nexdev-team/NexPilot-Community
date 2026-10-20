<?php
// Behavioral tests with explicit WordPress/MySQL test doubles, not a live WP integration suite.
namespace {
define('ABSPATH',__DIR__.'/fixtures/');define('DB_NAME','test');define('DAY_IN_SECONDS',86400);define('NSC_VERSION','test');
@mkdir(ABSPATH,0700,true);file_put_contents(ABSPATH.'sample.txt','original');
class WP_Error {public function __construct(public $code,public $message,public $data=[]){}}
class WP_REST_Response {public array $headers=[];public function __construct(public $data,public $status=200){}public function header($k,$v){$this->headers[$k]=$v;}}
class WP_REST_Request {public function __construct(public array $headers=[],public string $body='',public string $method='POST'){}public function get_header($k){return $this->headers[$k]??'';}public function get_body(){return $this->body;}public function get_method(){return $this->method;}}
class FakeDB {
 public string $prefix='wp_';public array $records=[];public array $audit=[];public bool $locked=false;public bool $locks_available=true;
 public function prepare($sql,...$args){foreach($args as $a)$sql=preg_replace_callback('/%[sdi]/',static fn($m)=>$m[0]==='%i'?'`'.str_replace('`','``',(string)$a).'`':"'".str_replace("'","''",(string)$a)."'",$sql,1);return $sql;}
 public function insert($table,$row){if(str_ends_with($table,'audit')){$this->audit[]=$row;return 1;}if(isset($this->records[$row['id']]))return false;$this->records[$row['id']]=$row;return 1;}
 public function replace($table,$row){$this->records[$row['id']]=$row;return 1;}
 public function get_var($sql){
  if(str_contains($sql,'GET_LOCK')){if(!$this->locks_available||$this->locked)return '0';$this->locked=true;return '1';}
  if(str_contains($sql,'RELEASE_LOCK')){$this->locked=false;return '1';}
  preg_match("/WHERE id='([^']+)'/",$sql,$m);return $this->records[$m[1]??'']['payload']??null;
 }
 public function query($sql){ // Rate SQL is covered separately by inspection; this is only a transport fixture.
  preg_match("/VALUES \('([^']+)','rate','([^']+)'\)/",$sql,$m);if(!$m)return false;$this->records[$m[1]]=['payload'=>$m[2]];return 1;
 }
}
$wpdb=new FakeDB;$options=[];$owner_active=true;$secure=true;$posts=[1=>(object)['ID'=>1,'post_type'=>'page','post_title'=>'Original','post_content'=>'Body','post_excerpt'=>'','post_status'=>'draft','post_name'=>'original']];$updates=0;
function wp_salt($scheme){return 'test-only-salt';}
function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}
function get_user_by($k,$v){return (object)['ID'=>$v];}
function user_can($u,$c){return $GLOBALS['owner_active'];}
function current_user_can(...$args){return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function wp_slash($data){return $data;}
function wp_update_post($data,$error=false){foreach($data as $k=>$v)$GLOBALS['posts'][$data['ID']]->$k=$v;$GLOBALS['updates']++;return $data['ID'];}
function is_wp_error($v){return $v instanceof WP_Error;}
function is_ssl(){return $GLOBALS['secure'];}
function wp_get_environment_type(){return 'production';}
function wp_set_current_user($id){}
function get_bloginfo($k){return 'Fixture';}
function home_url(){return 'https://fixture.invalid';}
foreach(['Errors','Policy','Store','Maintenance','Resources','Library','Files','Blocks','Tools','Server'] as $file)require __DIR__.'/../plugin/includes/'.$file.'.php';
use Nexdev\SiteConnector\{Policy,Store,Tools,Server};
$n=0;
function check($condition,$name){global $n;if(!$condition)throw new Exception('FAIL: '.$name);echo 'PASS '.$name."\n";$n++;}
function denied(callable $fn,$name){try{$fn();}catch(Throwable $e){check(true,$name);return;}check(false,$name);}
function approve($id){$p=Store::get($id);$p['state']='approved';Store::put($id,'proposal',$p);}
$r=Store::create_client('Reader',['read'],1);$w=Store::create_client('Writer',['read','content'],1);$other=Store::create_client('Other',['read','content'],1);$m=Store::create_client('Maintenance',['read','maintenance'],1);
$read=$r['client'];$write=$w['client'];$maintenance=$m['client'];
check(Store::authenticate($w['token'])['id']===$write['id'],'credential authenticates its own client');
check(!str_contains(Policy::canonical($wpdb->records),$w['token']),'plaintext token is not stored');
denied(fn()=>Store::authenticate(substr($w['token'],0,-1).'z'),'malformed credential rejected');
$rev=$read;$rev['revoked']=true;Store::put($rev['id'],'client',$rev);denied(fn()=>Store::authenticate($r['token']),'revoked credential rejected');Store::put($read['id'],'client',$read);
$exp=$read;$exp['expires']=time()-1;Store::put($exp['id'],'client',$exp);denied(fn()=>Store::authenticate($r['token']),'expired credential rejected');Store::put($read['id'],'client',$read);
$owner_active=false;denied(fn()=>Store::authenticate($w['token']),'removed owner privileges invalidate connection');$owner_active=true;
check(Policy::fingerprint(['b'=>2,'a'=>1])===Policy::fingerprint(['a'=>1,'b'=>2]),'canonical digest independent of object key order');
$revision=Tools::call($write,'content_get',['id'=>1])['revision'];
$args=['operation'=>'content_update','arguments'=>['id'=>1,'fields'=>['title'=>'Changed']],'expected_revision'=>$revision];
denied(fn()=>Tools::call($read,'change_propose',$args),'read client cannot propose writes');
denied(fn()=>Tools::call($read,'orders_list',[]),'read client cannot access commerce tools');
denied(fn()=>Tools::call($write,'content_get',['id'=>'1']),'invalid ID type rejected');
denied(fn()=>Tools::call($write,'content_get',['id'=>1,'extra'=>true]),'unknown arguments rejected');
$p=Tools::call($write,'change_propose',$args);check(str_starts_with($wpdb->records[$p['id']]['payload'],'sealed:'),'proposal contents encrypted at rest');check($posts[1]->post_title==='Original','proposal has no content side effects');
denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'unapproved execution refused');
denied(fn()=>Tools::call($other['client'],'change_get',['id'=>$p['id']]),'another client cannot inspect proposal');
approve($p['id']);denied(fn()=>Tools::call($other['client'],'change_execute',['id'=>$p['id']]),'another client cannot consume approval');
$out=Tools::call($write,'change_execute',['id'=>$p['id']]);check($out['state']==='done'&&$posts[1]->post_title==='Changed','approved exact change executes');
Tools::call($write,'change_execute',['id'=>$p['id']]);check($updates===1,'completed change not applied twice');
$args['expected_revision']=Tools::content(1)['revision'];$args['arguments']['fields']['title']='Next';
$p=Tools::call($write,'change_propose',$args);approve($p['id']);$posts[1]->post_title='Human edit';
denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'concurrent human edit blocks stale proposal');check($posts[1]->post_title==='Human edit','conflict preserves human edit');
$args['expected_revision']=Tools::content(1)['revision'];$p=Tools::call($write,'change_propose',$args);approve($p['id']);$stored=Store::get($p['id']);$stored['arguments']['fields']['title']='Tampered';Store::put($p['id'],'proposal',$stored);
denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'changed approved payload rejected');
$p=Tools::call($write,'change_propose',$args);approve($p['id']);$stored=Store::get($p['id']);$stored['expires']=time()-1;Store::put($p['id'],'proposal',$stored);denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'expired approval rejected');
$p=Tools::call($write,'change_propose',$args);approve($p['id']);$rev=$write;$rev['revoked']=true;Store::put($write['id'],'client',$rev);denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'revocation rechecked inside execution lock');Store::put($write['id'],'client',$write);
$options['nsc_disabled']=true;denied(fn()=>Tools::call($write,'change_execute',['id'=>$p['id']]),'kill switch rechecked before execution');$options=[];
$wpdb->locks_available=false;denied(fn()=>Tools::call($write,'change_propose',$args),'missing database lock fails closed');$wpdb->locks_available=true;
Store::locked(function(){denied(fn()=>Store::locked(fn()=>null),'second writer refused while lock held');});check(!$wpdb->locked,'writer lock released');
denied(fn()=>Tools::call($maintenance,'file_read',['path'=>'sample.txt']),'maintenance denied until human opens window');
$maintenance['maintenance_until']=time()+600;Store::put($maintenance['id'],'client',$maintenance);
$expiredMaintenance=$maintenance;$expiredMaintenance['maintenance_until']=time()-1;denied(fn()=>Tools::call($expiredMaintenance,'file_read',['path'=>'sample.txt']),'expired maintenance window rejected');
$p=Tools::call($maintenance,'change_propose',['operation'=>'php_execute','arguments'=>['code'=>'return 6 * 7;']]);approve($p['id']);$res=Tools::call($maintenance,'change_execute',['id'=>$p['id']]);check($res['result']['return']===42,'exact approved PHP runs in maintenance window');
$p=Tools::call($maintenance,'change_propose',['operation'=>'php_execute','arguments'=>['code'=>'throw new \\Exception("fixture failure");']]);approve($p['id']);$res=Tools::call($maintenance,'change_execute',['id'=>$p['id']]);check($res['state']==='uncertain','execution exception records uncertain outcome');denied(fn()=>Tools::call($maintenance,'change_execute',['id'=>$p['id']]),'uncertain operation cannot be retried');
$file=Tools::call($maintenance,'file_read',['path'=>'sample.txt']);$p=Tools::call($maintenance,'change_propose',['operation'=>'file_write','arguments'=>['path'=>'sample.txt','content'=>'updated'],'expected_revision'=>$file['revision']]);approve($p['id']);
check(Tools::call($maintenance,'change_execute',['id'=>$p['id']])['state']==='done'&&file_get_contents(ABSPATH.'sample.txt')==='updated','file read revision works for approved file replacement');
denied(fn()=>Policy::path('../run.php'),'path traversal rejected');denied(fn()=>Policy::path('/etc/passwd'),'absolute path rejected');denied(fn()=>Policy::path('php://filter'),'stream wrapper rejected');
symlink(dirname(ABSPATH,2).'/run.php',ABSPATH.'outside-link');denied(fn()=>Policy::path('outside-link'),'symlink escape rejected');unlink(ABSPATH.'outside-link');
$valid=new WP_REST_Request(['authorization'=>'Bearer '.$w['token']],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-03-26']]));check(Server::permission($valid)===true,'authenticated transport allowed');
$init=Server::handle($valid);check($init->data['result']['protocolVersion']==='2025-03-26','MCP initialize negotiates version');
$origin=clone $valid;$origin->headers['origin']='https://evil.invalid';check(Server::permission($origin) instanceof WP_Error,'browser-origin request rejected');
$secure=false;check(Server::permission($valid) instanceof WP_Error,'non-TLS production request rejected');$secure=true;
$notify=new WP_REST_Request([],json_encode(['jsonrpc'=>'2.0','method'=>'notifications/initialized']));check(Server::handle($notify)->status===202,'notification has accepted response');
$get=new WP_REST_Request([],'','GET');check(Server::handle($get)->status===405,'unsupported SSE GET returns 405');
$bad=new WP_REST_Request([],'{');check(Server::handle($bad)->status===400,'malformed JSON rejected');
$queuedArgs=['operation'=>'content_update','arguments'=>['id'=>1,'fields'=>['title'=>'Queued write must not run']],'expected_revision'=>Tools::content(1)['revision']];
$queued=Tools::call($write,'change_propose',$queuedArgs);approve($queued['id']);$originalTitle=$posts[1]->post_title;
$rotated=$write;$rotated['hash']=hash('sha256','rotated-test-secret');Store::put($write['id'],'client',$rotated);
denied(fn()=>Tools::call($write,'change_execute',['id'=>$queued['id']]),'credential rotation blocks previously authenticated queued write');
check($posts[1]->post_title===$originalTitle,'rotation rejection preserves content');Store::put($write['id'],'client',$write);
$GLOBALS['owner_active']=false;
denied(fn()=>Tools::call($write,'change_execute',['id'=>$queued['id']]),'owner permission loss blocks queued write');
check($posts[1]->post_title===$originalTitle,'owner rejection preserves content');$GLOBALS['owner_active']=true;
unlink(ABSPATH.'sample.txt');rmdir(ABSPATH);echo "\n$n tests passed (test doubles; not live WordPress/MySQL).\n";
}
