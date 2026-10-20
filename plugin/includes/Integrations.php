<?php
namespace Nexdev\SiteConnector;
/** Conditional adapters: never invent data when a commercial dependency is absent. */
final class Integrations {
 public static function forms(array $a): array {
  if(!function_exists('wpforms'))throw new ConnectorError('WPForms is not installed/active.');
  $action=$a['action'];
  if($action==='list')return array_map(static fn($p)=>['id'=>$p->ID,'title'=>$p->post_title],get_posts(['post_type'=>'wpforms','post_status'=>'any','posts_per_page'=>50,'paged'=>$a['page']??1]));
  if($action==='get'){$p=get_post(Policy::id($a['id']??null));if(!$p||$p->post_type!=='wpforms')throw new ConnectorError('Form not found.');return ['id'=>$p->ID,'title'=>$p->post_title,'configuration'=>json_decode($p->post_content,true)];}
  global $wpdb;$table=$wpdb->prefix.'wpforms_entries';
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table)throw new ConnectorError('Local WPForms entry storage unavailable. WPForms Lite may not store entries locally.');
  $columns=$wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i',$table),0);
  if(array_diff(['entry_id','form_id','fields'],$columns))throw new ConnectorError('Unsupported WPForms entry schema.');
  if(!in_array($action,['entries','entry','stats','search'],true))throw new ValidationError('Unsupported WPForms action.');
  $form=isset($a['form_id'])?Policy::id($a['form_id']):0;
  $entry=$action==='entry'?Policy::id($a['id']??null):0;
  $search=isset($a['search'])?'%'.$wpdb->esc_like($a['search']).'%':'%';
  $offset=(min(10000,max(1,$a['page']??1))-1)*50;
  if($action==='stats'){
   $count=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE (%d=0 OR form_id=%d) AND (%d=0 OR entry_id=%d) AND (%d=0 OR fields LIKE %s)',$table,$form,$form,$entry,$entry,isset($a['search'])?1:0,$search));
   if($wpdb->last_error)throw new ConnectorError('WPForms count query failed.');return ['count'=>(int)$count];
  }
  $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE (%d=0 OR form_id=%d) AND (%d=0 OR entry_id=%d) AND (%d=0 OR fields LIKE %s) ORDER BY entry_id DESC LIMIT 50 OFFSET %d',$table,$form,$form,$entry,$entry,isset($a['search'])?1:0,$search,$offset),ARRAY_A);
  if($wpdb->last_error)throw new ConnectorError('WPForms query failed.');return Resources::redact($rows);
 }
 public static function commerce(array $a): array {
  if(!function_exists('wc_get_product'))throw new ConnectorError('WooCommerce is inactive.');
  $kind=$a['kind'];
  if(in_array($kind,['bundle','composite'],true)){
   if(!class_exists($kind==='bundle'?'WC_Product_Bundle':'WC_Product_Composite'))throw new ConnectorError('Required commercial WooCommerce extension is inactive.');
   $products=isset($a['id'])?[wc_get_product(Policy::id($a['id']))]:wc_get_products(['type'=>$kind,'limit'=>25,'page'=>$a['page']??1]);$out=[];
   foreach($products as $p){if(!$p||!$p->is_type($kind))continue;$data=['id'=>$p->get_id(),'type'=>$kind,'data'=>$p->get_data()];
    if($kind==='composite'&&is_callable([$p,'get_composite_data']))$data['components']=$p->get_composite_data();
    if($kind==='composite'&&is_callable([$p,'get_scenario_data']))$data['scenarios']=$p->get_scenario_data();
    if($kind==='bundle'&&is_callable([$p,'get_bundled_items'])){$data['items']=[];foreach($p->get_bundled_items() as $key=>$item){$data['items'][]=['id'=>$key,'product_id'=>is_callable([$item,'get_product_id'])?$item->get_product_id():null,'data'=>is_callable([$item,'get_data'])?$item->get_data():null];}}
    $out[]=$data;
   }return Resources::redact($out);
  }
  if($kind!=='minmax')throw new ValidationError('Unknown integration.');
  if(!class_exists('WC_Min_Max_Quantities')&&!defined('WC_MIN_MAX_QUANTITIES_VERSION'))throw new ConnectorError('Min/Max Quantities extension is inactive.');
  // Return explicit stored values; do not claim effective/inherited rules without the installed version's resolver.
  if(isset($a['id']))$meta=get_post_meta(Policy::id($a['id']));
  elseif(isset($a['category_id']))$meta=get_term_meta(Policy::id($a['category_id']));
  else {global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s LIMIT 100",'%'.$wpdb->esc_like('min_max').'%', $wpdb->esc_like('woocommerce_minimum').'%', $wpdb->esc_like('woocommerce_maximum').'%'),ARRAY_A);$meta=array_column($rows,'option_value','option_name');}
  $effective=null;$resolved=null;
  if(isset($a['id'])){
   $product=wc_get_product($a['id']);
   if($product){if(class_exists('WC_Min_Max_Quantities_Quantity_Rules'))$resolved=(new \WC_Min_Max_Quantities_Quantity_Rules($product))->get();$html=woocommerce_quantity_input([],$product,false);$parser=new \WP_HTML_Tag_Processor($html);while($parser->next_tag(['tag_name'=>'INPUT'])){if($parser->get_attribute('name')==='quantity'){$effective=['min'=>$parser->get_attribute('min'),'max'=>$parser->get_attribute('max'),'step'=>$parser->get_attribute('step')];break;}}}
  }
  return ['resolved_product_rules'=>$resolved,'classic_quantity_input'=>$effective,'stored_rules'=>array_filter($meta,static fn($k)=>preg_match('/min|max|group_of|quantity/i',$k),ARRAY_FILTER_USE_KEY),'interpretation'=>'Resolved product rules use the installed extension resolver when available, including category/variation inheritance. Classic quantity controls are produced by active WooCommerce filters and may include stock limits. Cart/order checkout validation is context-dependent; stored rules are included separately.'];
 }
}
