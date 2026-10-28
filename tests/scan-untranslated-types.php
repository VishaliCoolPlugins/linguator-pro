<?php
/** Standalone regression checks for the live translatability notice. */
namespace LinguatorPro\Modules\Scan {
 define('ABSPATH', __DIR__);
 abstract class Abstract_Check {
  protected $model;
  public function __construct($model) { $this->model=$model; }
  protected function wants($id) { return true; }
  protected function result($id,$status,$label,$message) { $result=new Result($id,$status,'Language Setup',$label,$message); $result->section='site'; return $result; }
 }
}
namespace LinguatorPro\Modules\Scan\Checks {
 function __($text,$domain='') { return $text; }
 function admin_url($path) { return '/wp-admin/'.$path; }
 function get_post_types($args) { return ['book']; }
 function get_taxonomies($args) { return ['genre']; }
 function apply_filters($hook,$values,$settings) { return $values; }
 function get_post_type_object($slug) { return (object)['labels'=>(object)['name'=>'Books']]; }
 function taxonomy_exists($slug) { return true; }
 function get_taxonomy($slug) { return (object)['labels'=>(object)['name'=>'Genres']]; }
}
namespace {
 function __($text,$domain='') { return $text; }
 require __DIR__.'/../modules/scan/result.php';
 require __DIR__.'/../modules/scan/checks/untranslated-types.php';
 $model=new class { public $enabled=false; public function is_translated_post_type($slug){return $this->enabled;} public function is_translated_taxonomy($slug){return $this->enabled;} };
 $check=new \LinguatorPro\Modules\Scan\Checks\Untranslated_Types($model);
 $result=$check->run()[0]->to_array();
 if($result['status']!=='warning' || strpos($result['message'],'"Books" (book)')===false || strpos($result['message'],'"Genres" (genre)')===false) throw new \Exception('Disabled types and taxonomies must be listed.');
 if($result['fix_url']!=='/wp-admin/admin.php?page=lmat_settings&tab=general') throw new \Exception('Settings link must point to Linguator.');
 $model->enabled=true;
 if($check->run()[0]->status!=='pass') throw new \Exception('Enabled types must not generate a warning.');
 echo "Translatability notice: lists disabled types and taxonomies, links settings, and clears when enabled.\n";
}
