<?php
namespace Nexdev\SiteConnector;
/** Native WordPress/WooCommerce REST controllers own field validation and permissions. */
final class Resources {
 public static function operations(): array {
  return [
   'content_create'=>['content','POST','/wp/v2/{type}',false],
   'content_delete'=>['content','DELETE','/wp/v2/{type}/{id}',true],
   'order_create'=>['commerce','POST','/wc/v3/orders',false],
   'order_update'=>['commerce','PUT','/wc/v3/orders/{id}',true],
   'order_delete'=>['commerce','DELETE','/wc/v3/orders/{id}',true],
   'product_create'=>['commerce','POST','/wc/v3/products',false],
   'product_delete'=>['commerce','DELETE','/wc/v3/products/{id}',true],
   'product_edit'=>['commerce','PUT','/wc/v3/products/{id}',true],
   'variation_create'=>['commerce','POST','/wc/v3/products/{parent}/variations',false],
   'variation_update'=>['commerce','PUT','/wc/v3/products/{parent}/variations/{id}',true],
   'variation_delete'=>['commerce','DELETE','/wc/v3/products/{parent}/variations/{id}',true],
   'coupon_create'=>['commerce','POST','/wc/v3/coupons',false],
   'coupon_update'=>['commerce','PUT','/wc/v3/coupons/{id}',true],
   'coupon_delete'=>['commerce','DELETE','/wc/v3/coupons/{id}',true],
   'refund_create'=>['maintenance','POST','/wc/v3/orders/{parent}/refunds',false],
   'customer_create'=>['maintenance','POST','/wc/v3/customers',false],
   'customer_update'=>['maintenance','PUT','/wc/v3/customers/{id}',true],
   'customer_delete'=>['maintenance','DELETE','/wc/v3/customers/{id}',true],
   'term_create'=>['content','POST','/wp/v2/{taxonomy}',false],
   'term_update'=>['content','POST','/wp/v2/{taxonomy}/{id}',true],
   'term_delete'=>['content','DELETE','/wp/v2/{taxonomy}/{id}',true],
   'media_update'=>['content','POST','/wp/v2/media/{id}',true],
   'media_delete'=>['content','DELETE','/wp/v2/media/{id}',true],
   'user_create'=>['maintenance','POST','/wp/v2/users',false],
   'user_update'=>['maintenance','POST','/wp/v2/users/{id}',true],
   'user_delete'=>['maintenance','DELETE','/wp/v2/users/{id}',true],
   'settings_update'=>['maintenance','POST','/wp/v2/settings',true],
   'plugin_install'=>['maintenance','POST','/wp/v2/plugins',false],
   'plugin_update'=>['maintenance','POST','/wp/v2/plugins/{plugin}',true],
   'plugin_delete'=>['maintenance','DELETE','/wp/v2/plugins/{plugin}',true],
  ];
 }
 public static function validate(string $op,array $a): void {
  $def=self::operations()[$op];$keys=['fields'];preg_match_all('/\{(\w+)\}/',$def[2],$m);$keys=array_merge($keys,$m[1]);
  Policy::fields($a,$keys,$keys);
  if(!is_array($a['fields']))throw new ValidationError('fields must be an object.');
  // REST body parameters take precedence over URL captures. Never allow a second target.
  if(array_intersect(array_keys($a['fields']),['id','product_id','order_id','plugin']))throw new ValidationError('Route identifiers must not appear in fields.');
  foreach(['id','parent'] as $key)if(array_key_exists($key,$a))Policy::id($a[$key]);
  if(isset($a['type'])&&!in_array($a['type'],['posts','pages'],true))throw new ValidationError('type must be posts or pages.');
  if(isset($a['taxonomy'])&&!in_array($a['taxonomy'],['categories','tags'],true))throw new ValidationError('Unsupported taxonomy.');
  if(isset($a['plugin'])&&!preg_match('~^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$~D',$a['plugin']))throw new ValidationError('Invalid plugin identifier.');
  if(strlen(Policy::canonical($a))>131072)throw new ValidationError('Operation too large.');
 }
 public static function route(string $op,array $a): string {
  return preg_replace_callback('/\{(\w+)\}/',static fn($m)=>(string)$a[$m[1]],self::operations()[$op][2]);
 }
 public static function request(string $method,string $route,array $params=[]): array {
  $req=new \WP_REST_Request($method,$route);$req->set_body_params($params);
  if($method==='GET')$req->set_query_params($params);
  $res=rest_do_request($req);
  if($res->is_error()||$res->get_status()>=400)throw new ConnectorError('Native API rejected operation. Verify fields and permissions.');
  $data=$res->get_data();return is_array($data)?$data:['value'=>$data];
 }
 public static function snapshot(string $op,array $a): array {
  $def=self::operations()[$op];
  $data=$def[3]?self::request('GET',self::route($op,$a),['context'=>'edit']):null;
  // Creating refunds/variations must also bind approval to the current parent state.
  if(in_array($op,['refund_create','variation_create'],true))$data=self::request('GET','/wc/v3/'.($op==='refund_create'?'orders':'products').'/'.$a['parent'],['context'=>'edit']);
  return ['data'=>$data,'revision'=>Policy::fingerprint($data)];
 }
 public static function apply(string $op,array $a): array {
  self::validate($op,$a);
  $result=self::request(self::operations()[$op][1],self::route($op,$a),$a['fields']);
  return self::redact($result);
 }
 public static function redact(array $data): array {
  foreach($data as $k=>&$v){
   if(preg_match('/password|secret|token|application_password/i',(string)$k))$v='[REDACTED]';
   elseif(is_array($v))$v=self::redact($v);
  }return $data;
 }
 public static function read(array $client,string $route,array $params): array {
  // No arbitrary plugin routes: a GET route is not necessarily side-effect-free.
  if(preg_match('~^/wp/v2/(posts|pages|categories|tags|media|blocks|block-types|templates|template-parts)(/[a-zA-Z0-9_./%-]+)?$~D',$route))Policy::require_scope($client,'read');
  elseif(preg_match('~^/wc/v3/(products|orders|coupons)(/[a-zA-Z0-9_/-]+)?$~D',$route))Policy::require_scope($client,'commerce');
  elseif(preg_match('~^/wp/v2/(users|settings|plugins|themes)(/[a-zA-Z0-9_./%-]+)?$~D',$route)||preg_match('~^/wc/v3/customers(/[0-9]+)?$~D',$route))Policy::maintenance($client);
  else throw new ValidationError('Route is not in the native read allowlist.');
  if(str_contains($route,'..')||str_contains($route,'%'))throw new ValidationError('Ambiguous route.');
  // Pagination cannot be bypassed with per_page=-1.
  $params['per_page']=min(50,max(1,(int)($params['per_page']??20)));
  $data=self::request('GET',$route,$params);
  return ['data'=>self::redact($data),'revision'=>Policy::fingerprint($data)];
 }
}
