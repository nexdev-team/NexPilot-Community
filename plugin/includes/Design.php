<?php
namespace Nexdev\SiteConnector;
/** Deterministic DESIGN.md contract. Unknown prose remains data and is never executable. */
final class Design {
 public static function parse(string $raw): array {
  $json=$raw;$warnings=[];
  if(preg_match('/```(?:json|nexdev-design)\s*\R(.*?)\R```/s',$raw,$m))$json=$m[1];
  $d=json_decode($json,true);
  if(!is_array($d)||array_is_list($d)){
   $d=[];$section='';
   foreach(preg_split('/\R/',$raw) as $line){
    if(preg_match('/^#{1,6}\s+(.+?)\s*#*$/',$line,$m)){$section=strtolower(trim($m[1]));continue;}
    if(preg_match('/^\s*[-*]?\s*(authority|builder)\s*:\s*(.+)$/i',$line,$m)){$d[strtolower($m[1])]=trim($m[2]," \t\"'");continue;}
    $mapping=['colors'=>'colors','typography'=>'typography','dials'=>'dials','guidance'=>'guidance','provenance'=>'provenance'];
    if(isset($mapping[$section])&&preg_match('/^\s*[-*]?\s*([\w.-]+)\s*:\s*(.+)$/u',$line,$m)){$d[$mapping[$section]][$m[1]]=trim($m[2]," \t`");continue;}
    if(in_array($section,["don't","don’t",'dont','forbidden phrases'],true)&&preg_match('/^\s*[-*]\s+(.+)$/',$line,$m))$d['forbidden_phrases'][]=trim($m[1]);
   }
   $warnings[]='Markdown parsing supports named sections and key: value entries. Other prose is preserved for human review.';
  }
  $errors=[];$d+=['colors'=>[],'typography'=>[],'authority'=>'design','forbidden_phrases'=>[],'dials'=>[],'guidance'=>[],'provenance'=>[]];
  if(!in_array($d['authority'],['design','ask','hybrid','builder'],true))$errors[]='Invalid authority.';
  foreach(['colors','typography','dials','guidance','provenance','forbidden_phrases'] as $k)if(!is_array($d[$k])){$errors[]=$k.' must be an object or list.';$d[$k]=[];}
  if(!$d['colors'])$errors[]='Colors are missing.';
  if(!$d['typography'])$errors[]='Typography is missing.';
  foreach($d['colors'] as $v)if(!is_string($v)||!preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD',$v))$errors[]='Colors must be hex values.';
  foreach($d['typography'] as $v)if(!is_string($v)||trim($v)==='')$errors[]='Typography must contain nonempty strings.';
  foreach($d['forbidden_phrases'] as $v)if(!is_string($v)||$v==='')$errors[]='Forbidden phrases must be nonempty strings.';
  if(isset($d['builder'])&&(!is_string($d['builder'])||strlen($d['builder'])>100))$errors[]='Invalid builder.';
  if(in_array($d['authority'],['ask','hybrid','builder'],true)&&empty($d['builder']))$errors[]='This authority requires a named builder.';
  $ready=!$errors;
  return ['contract'=>$d,'tokens'=>['colors'=>$d['colors'],'typography'=>$d['typography']],'dials'=>$d['dials'],'guidance'=>$d['guidance'],'provenance'=>$d['provenance'],'readiness'=>['ready'=>$ready,'errors'=>array_values(array_unique($errors)),'warnings'=>$warnings], 'sync_readiness'=>['ready'=>false,'reason'=>'No automatic builder synchronization is performed. Inspect native builder entries and obtain approval for changes.'], 'authority'=>$d['authority'],'authoritative'=>$ready&&$d['authority']==='design','builder'=>$d['builder']??null,'instruction'=>match($d['authority']){'ask'=>'Ask the human which system leads. Until resolved, use builder values and introduce no design tokens.','hybrid'=>'Builder values win conflicts. Use design tokens only for verified gaps or explicit human requests.','builder'=>'Use builder values only; apply universal checks.','design'=>'Use this design contract as the source of truth.',default=>'Incomplete design; do not activate.'}];
 }
 public static function record(?array $r): array {if(!$r)return [];return array_merge($r,self::parse($r['document']),['raw_document'=>$r['document'],'active'=>get_option('nsc_active_design','')===$r['slug']&&($r['readiness']??'')!=='draft']);}
 public static function normalize(string $color): string {$h=strtolower(substr($color,1));if(strlen($h)<=4)$h=implode('',array_map(static fn($c)=>$c.$c,str_split($h)));if(strlen($h)===6)$h.='ff';return $h;}
 public static function check(array $r,string $content,array $context=[]): array {
  $p=self::parse($r['document']);if(!$p['readiness']['ready'])throw new ConnectorError('Active design is incomplete.');$d=$p['contract'];$authority=$d['authority'];$issues=[];
  // Context is advisory evidence supplied by the caller, never authority to mutate or approve.
  Policy::fields($context,['builder_tokens','requested_tokens'],[]);
  $requested=$context['requested_tokens']??[];$builder=$context['builder_tokens']??[];
  if(!is_array($requested)||!array_is_list($requested)||count($requested)>100||array_filter($requested,static fn($v)=>!is_string($v)))throw new ValidationError('Requested tokens must be a string list.');
  if(!is_array($builder))throw new ValidationError('Builder tokens must be an object.');
  Policy::fields($builder,['colors','typography'],[]);
  foreach($builder as $kind=>$values){if(!is_array($values)||count($values)>100)throw new ValidationError('Invalid builder tokens.');foreach($values as $key=>$v)if(!is_string($key)||!is_string($v)||$v===''||($kind==='colors'&&!preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD',$v)))throw new ValidationError('Invalid builder token.');}
  $effective=[];$evidence=[];
  foreach(['colors','typography'] as $kind){$effective[$kind]=$d[$kind];$evidence[$kind]=array_key_exists($kind,$builder);
   if($authority==='hybrid'&&$evidence[$kind]){foreach($builder[$kind] as $key=>$value)if(!in_array($kind.'.'.$key,$requested,true)||!isset($d[$kind][$key]))$effective[$kind][$key]=$value;}
  }
  $visible=html_entity_decode(wp_strip_all_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$content)),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $css=$content;
  if(str_contains($content,'<')){
   preg_match_all('~<style\b[^>]*>(.*?)</style>~is',$content,$styleBlocks);
   preg_match_all('~\bstyle\s*=\s*(["\'])(.*?)\1~is',$content,$attributes);
   $css=implode("\n",array_merge($styleBlocks[1],$attributes[2]));
   $css=html_entity_decode($css,ENT_QUOTES|ENT_HTML5,'UTF-8');
  }
  $css=preg_replace('~/\*.*?\*/~s','',$css);
  if($authority!=='builder'){
   foreach($d['forbidden_phrases'] as $phrase)if(stripos($visible,$phrase)!==false)$issues[]=['type'=>'forbidden_phrase','value'=>$phrase,'severity'=>$authority==='design'?'fail':'advisory'];
   preg_match_all('/#(?:[a-f0-9]{8}|[a-f0-9]{6}|[a-f0-9]{4}|[a-f0-9]{3})\b/i',$css,$colors);
   $palette=array_map([self::class,'normalize'],array_values($effective['colors']));
   foreach(array_unique($colors[0]) as $color){$normal=self::normalize($color);if(in_array($normal,$palette,true))continue;
    $issues[]=['type'=>'off_palette','value'=>$color,'severity'=>($authority==='design'||($authority==='hybrid'&&$evidence['colors']))?'fail':'advisory'];
   }
   preg_match_all('/font-family\s*:\s*([^;}]+)/i',$css,$fonts);$primary=static fn($f)=>strtolower(trim(explode(',',$f)[0]," \t\r\n\"'"));
   $allowed=array_map($primary,array_values($effective['typography']));
   foreach(array_unique($fonts[1]) as $stack)if(!preg_match('/var\s*\(/i',$stack)&&!in_array($primary($stack),array_merge($allowed,['inherit','initial','unset','revert']),true))$issues[]=['type'=>'off_typography','value'=>trim($stack),'severity'=>($authority==='design'||($authority==='hybrid'&&$evidence['typography']))?'fail':'advisory'];
   if($authority==='hybrid'&&(!$evidence['colors']||!$evidence['typography']))$issues[]=['type'=>'builder_evidence_required','severity'=>'advisory'];
   if($authority==='ask')$issues[]=['type'=>'human_authority_choice_required','severity'=>'advisory'];
  }
  if(str_contains($visible,'—'))$issues[]=['type'=>'universal_em_dash','severity'=>'fail'];
  if(preg_match('/font-family\s*:\s*[\"\']?Inter(?:[\"\']|\s|,|;|})/i',$css))$issues[]=['type'=>'universal_inter_font','severity'=>'fail'];
  foreach(['delve into','unlock your potential','in today’s fast-paced world'] as $phrase)if(stripos($visible,$phrase)!==false)$issues[]=['type'=>'universal_filler','value'=>$phrase,'severity'=>'fail'];
  if(preg_match('/#(?:8b5cf6|a855f7|9333ea|7c3aed)\b/i',$css))$issues[]=['type'=>'universal_ai_purple','severity'=>'fail','note'=>'Explicit heuristic palette; not a semantic judgment of all purple.'];
  if(preg_match('/(?:var|rgb|hsl|oklch|color)\s*\(/i',$css))$issues[]=['type'=>'computed_color_review_required','severity'=>'advisory'];
  return ['design'=>$r['slug'],'authority'=>$authority,'authoritative'=>$p['authoritative'],'builder'=>$p['builder'],'instruction'=>$p['instruction'],'issues'=>$issues,'passed'=>!array_filter($issues,static fn($i)=>$i['severity']==='fail'),'scope'=>'Static checks only. Builder context is caller-supplied evidence. Computed styles, layout and responsive appearance require browser review.'];
 }
}
