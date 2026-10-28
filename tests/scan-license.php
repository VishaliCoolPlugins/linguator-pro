<?php
/** License state must be available before the admin license manager is loaded. */
define('ABSPATH', __DIR__.'/');
define('LINGUATOR_PRO_FILE', __DIR__.'/../linguator-pro.php');
function get_plugin_data($file,$markup=false,$translate=false){return ['Version'=>'1.0'];}
function site_url(){return 'http://license-test.local';}
$GLOBALS['license_test_record']=null;
function get_option($key,$default=null){return $GLOBALS['license_test_record'] ?? $default;}
require __DIR__.'/../admin/Register/class-linguator-pro-base.php';
use LinguatorPro\Admin\Register\Linguator_Pro_Base;
$singleton=new ReflectionProperty(Linguator_Pro_Base::class,'selfobj');
$singleton->setAccessible(true);
if(Linguator_Pro_Base::is_license_active())throw new Exception('Missing license must remain locked.');
$client=Linguator_Pro_Base::getInstance(LINGUATOR_PRO_FILE);
foreach([true=>true,'support_expired'=>true,'license_expired'=>false,''=>false] as $valid=>$expected){
 $encrypt=new ReflectionMethod(Linguator_Pro_Base::class,'encrypt');
 $encrypt->setAccessible(true);
 $GLOBALS['license_test_record']=$encrypt->invoke($client,serialize((object)['is_valid'=>$valid]),site_url());
 $singleton->setValue(null,null);
 if(Linguator_Pro_Base::is_license_active()!==$expected)throw new Exception('Incorrect cached license status.');
}
echo "License initialization: active/support-expired accepted; missing/expired rejected before admin initialization.\n";
