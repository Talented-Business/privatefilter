<?php

/**
 
 * 
 *  @author    Lazutina <sui201837@outlook.com>
 *  @copyright 2019 Lazutina
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
use Symfony\Component\Cache\Simple\FilesystemCache;
require_once(dirname(__FILE__).'/src/PrivateFilterProvider.php');

class PrivateFilters extends Module {

    public function __construct() {
        $this->name = 'privatefilters'; // internal identifier, unique and lowercase
        $this->tab = 'front_office_features'; // backend module coresponding category
        $this->version = '1.0.0'; // version number for the module
        $this->author = 'Lazutina'; // module author
        $this->need_instance = 0; // load the module when displaying the "Modules" page in backend
        $this->bootstrap = true;


        $this->displayName = $this->l('Private Filters '); // public name
        $this->description = $this->l('Features Filter'); // public description

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?'); // confirmation message at uninstall

        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        parent::__construct();
    }

    /**
     * Install this module
     * @return boolean
     */
    public function install() {
        return parent::install()&&$this->registerHook('displayTopColumn')
        &&$this->registerHook('displayWrapperTop')
        &&$this->registerHook('header')
        &&$this->registerHook('actionProductSave')
        &&$this->registerHook('displayTop');
    }
    public function hookActionProductSave($params){
      $cache = new FilesystemCache();
      $cache->delete('product_filters');
    }
    public function hookHeader()
    {
      $this->context->controller->registerJavascript('module-privatefilters', 'modules/' .$this->name. '/assets/js/filter.js');
    }  
    public function hookDisplayTopColumn()
    {
      return $this->hookDisplayTop();
    }
    public function hookDisplayWrapperTop()
    {
      //return $this->hookDisplayTop();
    }

    public function hookDisplayTop()
    {
      $this->context->controller->addJs($this->_path.'assets/js/filter.js');
      $features = Feature::getFeatures($this->context->language->id);
      foreach($features as $feature){
        switch($feature['name']){
          case "Make":
            $make_id = $feature['id_feature'];
          break;
          case "Model":
            $model_id = $feature['id_feature'];
          break;
          case "Year":
            $year_id = $feature['id_feature'];
          break;
        }
      }
      $cache = new FilesystemCache();
      if ($cache->has('product_filters')) {
        $filter_features = $cache->get('product_filters');
      }else{
        $products = Product::getProducts($this->context->language->id,0,-1,'id_product','desc');
        $filter_features = array();
        foreach ($products as $product) {
          $product_features = Product::getFeaturesStatic($product['id_product']);
          if($product['id_product'] == 8){
            //var_dump($features);
          }
          $feature_value_makes = array();
          $feature_value_models = array();
          $feature_value_years = array();
          foreach($product_features as $feature){
            if($feature['id_feature'] == $make_id){
              $feature_value_makes[] = $feature['id_feature_value'];
            }elseif($feature['id_feature'] == $model_id){
              $feature_value_models[] = $feature['id_feature_value'];
            }elseif($feature['id_feature'] == $year_id){
              $feature_value_years[] = $feature['id_feature_value'];
            }
          }
          if(!empty($feature_value_makes)&& !empty($feature_value_models)&& !empty($feature_value_years)){
            foreach($feature_value_makes as $feature_value_make){
              if(!isset($filter_features[$feature_value_make])){
                $filter_features[$feature_value_make] = array();
              }
              foreach($feature_value_models as $feature_value_model){
                if(!isset($filter_features[$feature_value_make][$feature_value_model])){
                  $filter_features[$feature_value_make][$feature_value_model] = array();
                }
                foreach($feature_value_years as $feature_value_year){
                  if(!in_array($feature_value_year, $filter_features[$feature_value_make][$feature_value_model]))$filter_features[$feature_value_make][$feature_value_model][] = $feature_value_year;
                }
              }
            }
          }
        }
        $cache->set('product_filters', $filter_features);
      }
      $all_parameters = Tools::getAllValues();
      $make = isset($all_parameters['make'])?$all_parameters['make']:'';
      $model = isset($all_parameters['model'])?$all_parameters['model']:'';
      $year = isset($all_parameters['year'])?$all_parameters['year']:'';
      $sub_features = array();
      foreach($features as $feature){
        switch($feature['name']){
          case "Make":
            $makes = array(null=>'-Make-');  
            $makes = $this->getHash($makes,FeatureValue::getFeatureValuesWithLang($this->context->language->id,$feature['id_feature']),$filter_features);
            asort($makes);
          break;
          case "Model":
            $models = array(null=>'-Model-');
            if($make != '' && isset($filter_features[$make])){
              $models = $this->getHash($models,FeatureValue::getFeatureValuesWithLang($this->context->language->id,$feature['id_feature']),$filter_features[$make]);
              asort($models);
            }
            $sub_features = $this->getSubHash($sub_features,FeatureValue::getFeatureValuesWithLang($this->context->language->id,$feature['id_feature']));
          break;
          case "Year":
            if($model != '' && isset($filter_features[$make][$model])){
              $years = $this->getYearHash(array(),FeatureValue::getFeatureValuesWithLang($this->context->language->id,$feature['id_feature']),$filter_features[$make][$model]);
              arsort($years);
              $years = array(null=>'-Year-') + $years;
            }else{
                $years = array(null=>'-Year-');
            }
            $sub_features = $this->getSubHash($sub_features,FeatureValue::getFeatureValuesWithLang($this->context->language->id,$feature['id_feature']));
          break;
        }
      }
      $search_controller_url = $this->context->link->getModuleLink('privatefilters', 'filter', array(), null, false, null, true);

      $this->context->smarty->assign(
          array(
              'makes' => $makes,
              'models' => $models,
              'years' => $years,
              'make' => $make,
              'model' => $model,
              'year' => $year,
              'sub_features'=>$sub_features,
              'filter_features'=>$filter_features,
              'search_controller_url'=>$search_controller_url
          )
      );

      return $this->display(__FILE__, 'display_top.tpl');
    }
    private function getSubHash($results,$values){
      if(is_array($results)==false)$results = array();
      foreach($values as $value){
        $results[$value['id_feature_value']] = $value['value'];
      }
      return $results;
    }
    private function getHash($results,$values,$filter_features){
      if(is_array($results)==false)$results = array();
      foreach($values as $value){
        if(isset($filter_features[$value['id_feature_value']]))$results[$value['id_feature_value']] = $value['value'];
      }
      return $results;
    }
    private function getYearHash($results,$values,$filter_features){
      if(is_array($results)==false)$results = array();
      foreach($values as $value){
        if(in_array($value['id_feature_value'],$filter_features))$results[$value['id_feature_value']] = $value['value'];
      }
      return $results;
    }
    /**
     * Uninstall this module
     * @return boolean
     */
    public function uninstall() {
        return parent::uninstall();
    }
}