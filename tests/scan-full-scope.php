<?php
/** Full scans skip URL checks; explicit URL scans still run them. */
namespace LinguatorPro\Includes\Other { class Linguator_Model {} }
namespace LinguatorPro\Modules\Scan {
 define('ABSPATH', __DIR__);
 abstract class Abstract_Check {
  protected $section='site';
  public function __construct($model) {}
  public function section(){return $this->section;}
  public function only($id){}
  public function extra(){return [];}
  public function run(){
   $GLOBALS['check_runs'][$this->section]=($GLOBALS['check_runs'][$this->section]??0)+1;
   $result=new Result(strtolower((new \ReflectionClass($this))->getShortName()),'pass','Test','Test');
   $result->section=$this->section;
   return [$result];
  }
 }
}
namespace {
 function __($text,$domain=''){return $text;}
 function apply_filters($name,$value){return $value;}
 function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
 function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;}
 require __DIR__.'/../modules/scan/result.php';
 foreach(['Languages','Url_Config','Url_Settings','Url_Slugs','Url_Numbered_Copies','Url_Routing','Url_Permalinks','Content','Content_Identical','Content_Links','Content_Placeholders','Content_Fields','Content_Terms','Untranslated_Types','Menus','Menu_Links','Navigation','Strings','Switcher','Elementor','Elementor_Headers','Elementor_Loops'] as $name){
  $section=strpos($name,'Url_')===0?'urls':(strpos($name,'Elementor')===0?'elementor':(strpos($name,'Content')===0?'content':'site'));
  eval('namespace LinguatorPro\\Modules\\Scan\\Checks; class '.$name.' extends \\LinguatorPro\\Modules\\Scan\\Abstract_Check { protected $section="'.$section.'"; }');
 }
 require __DIR__.'/../modules/scan/scanner.php';
 $scanner=new \LinguatorPro\Modules\Scan\Scanner(new \LinguatorPro\Includes\Other\Linguator_Model());
 $key=\LinguatorPro\Modules\Scan\Scanner::REPORT_OPTION;
 $url=['id'=>'preserved_url','section'=>'urls','status'=>'warning','label'=>'Existing URL issue','message'=>'Keep this','details'=>[]];
 $GLOBALS['options'][$key]=['time'=>100,'results'=>[$url],'partial_scans'=>['urls'=>75,'site'=>60]];
 $result=$scanner->scan();
 if(!empty($GLOBALS['check_runs']['urls']))throw new \Exception('Full scan ran URL checks.');
 if(end($result['results'])!==$url || $result['partial_scans']!==['urls'=>75])throw new \Exception('Full scan changed URL results or their timestamp.');
 $scanner->scan_section('urls');
 if(($GLOBALS['check_runs']['urls']??0)!==6)throw new \Exception('Explicit URL scan must run all URL checks.');
 $GLOBALS['options']=[];
 $result=$scanner->scan();
 if(array_filter($result['results'],fn($row)=>$row['section']==='urls') || !empty($result['partial_scans']))throw new \Exception('First full scan fabricated URL results.');
 echo "Full scan skips URL checks and preserves saved URL rows/timestamps; explicit URL scan runs normally.\n";

 \LinguatorPro\Modules\Scan\Scanner::set_ignored_many(['first','second','first'],true);
 if(\LinguatorPro\Modules\Scan\Scanner::get_ignored()!==['first','second'])throw new \Exception('Bulk ignore must deduplicate keys.');
 \LinguatorPro\Modules\Scan\Scanner::set_ignored_many(['first','second'],false);
 if(\LinguatorPro\Modules\Scan\Scanner::get_ignored()!==[])throw new \Exception('Bulk restore must restore every selected key.');
 echo "Bulk ignore and restore preserve unique keys.\n";
}
