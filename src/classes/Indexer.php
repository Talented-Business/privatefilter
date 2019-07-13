<?php
/**
 * 2017 Zemez
 *
 * JX Advanced Filter
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the General Public License (GPL 2.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/GPL-2.0
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the module to newer
 * versions in the future.
 *
 * @author    Zemez
 * @copyright 2017 Zemez
 * @license   http://opensource.org/licenses/GPL-2.0 General Public License (GPL 2.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Indexer
{
    public $id_shop;
    public $id_lang;
    protected $module;
    protected $db;
    protected $context;

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->id_lang = $this->context->language->id;
        $this->id_shop = $this->context->shop->id;
        $this->module = new Jxadvancedfilter();
    }

    /**
     * Rebuild/add entry only for defined product(s) id(s), and reindex prices for that product(s) if need
     * USED: in jxadvancedfilter.php by hookActionFeatureValueDelete && hookActionCategoryDelete methods
     *
     * @param      $id_filter (int) filter ID for which entries will be rewrite
     * @param      $type (string) type of filter, used in child functions
     * @param      $products (array) products for which need to rebuild entries
     * @param bool $price_indexation (bool) index price for this products or not (false by default)
     *
     * @return $result bool
     */
    public function rebuildEntry($id_filter, $type, $products, $price_indexation = false)
    {
        $result = true;
        if ($products) {
            foreach ($products as $id_product) {
                $all_filters_items = $this->module->repository->getFilterItems($id_filter);
                if ($data = $this->generateFilterIndexes($all_filters_items, [$id_product])) {
                    $result &= $this->updateIndexTable($type, $id_product, $data);
                }
                // check if filter has a price field and rebuild entries
                if ($price_indexation
                    && $this->module->repository->getPriceFieldId($id_filter)
                    && $prices = $this->generateFilterPriceIndexes([$id_product])
                ) {
                    $result &= $this->updateIndexPriceTable($type, $id_product, $prices);
                }
                // check if filter has a price with tax included field and rebuild entries
                if ($price_indexation
                    && $this->module->repository->getPriceTaxInclFieldId($id_filter)
                    && $prices = $this->generateFilterPriceIndexes(array($id_product), true)
                ) {
                    $result &= $this->updateIndexPriceTable($type, $id_product, $prices, true);
                }
            }
        }

        return $result;
    }

    /**
     * Remove product indexes
     *
     * @param $type (int) filter type
     * @param $id_product (int) product id
     *
     * @return bool
     */
    public function removeEntry($type, $id_product)
    {
        $result = true;
        $result &= Db::getInstance()->delete(
            'jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop,
            '`id_product` = '.(int)$id_product
        );
        $result &= Db::getInstance()->delete(
            'jxadvancedfilter_price_indexed_'.$type.'_'.$this->id_shop,
            '`id_product` = '.(int)$id_product
        );
        $result &= Db::getInstance()->delete(
            'jxadvancedfilter_price_taxes_indexed_'.$type.'_'.$this->id_shop,
            '`id_product` = '.(int)$id_product
        );

        return $result;
    }

    /*
     * Count all products and split it to smaller parts fo indexation
     * to prevent server errors when too much products are indexing
     */
    public function splitSuitableProducts()
    {
        $splited = [];
        $element = 0;
        $part = 1;
        $all_products = Product::getProducts($this->id_lang, false, false, 'id_product', 'asc', false, true);
        foreach ($all_products as $product) {
            if ($element == 99) {
                $part++;
                $element = 0;
            }
            $splited[$part][] = $product['id_product'];
            $element++;
        }
        if (!$splited || !count($splited)) {
            return false;
        }

        return [
            'total'      => count($all_products),
            'parts'      => count($splited),
            'parts_info' => $splited
        ];
    }

    /**
     * Rebuild all products entries by filter type
     * USED: in controllers/admin/AdminJXAdvancedFilterController.php by ajaxProcessReindexFilters method
     *
     * @param $type (string) type of filter for rebuilding
     *
     * @return $result bool
     */
    public function rebuildAllEntries($type, $part, $products)
    {
        $result = true;
        $id_filter = $this->module->repository->checkFilterExists($type);
        if ($part == 1) {
            if (!$this->clearAttributesIndexTable($id_filter) || !$this->clearFeaturesIndexTable($id_filter)) {
                return false;
            }
        }
        $all_filters_items = $this->module->repository->getFilterItems($id_filter);
        $all_products = $products;
        // reindex all filter data except price fields
        if ($data = $this->generateFilterIndexes($all_filters_items, $all_products)) {
            $result &= $this->generateIndexTable($type, $data, $part);
        }
        // reindex prices if price parameter is used in current filter
        if ($this->module->repository->getPriceFieldId($id_filter) && $prices = $this->generateFilterPriceIndexes($all_products)) {
            $result &= $this->generateIndexPriceTable($prices, $type, $part);
        }
        // reindex prices with taxes if parameter is used in current filter
        if ($this->module->repository->getPriceTaxInclFieldId($id_filter) && $prices = $this->generateFilterPriceIndexes($all_products, true)) {
            $result &= $this->generateIndexPriceTable($prices, $type, $part, true);
        }

        return $result;
    }

    /**
     * Generate indexed data multi array for all active store products by all filter fields
     *
     * @param $filters  all filter parameters used for store
     * @param $products all active products related to current store
     *
     * @return array multi array with all required data
     */
    protected function generateFilterIndexes($filters, $products)
    {
        $result = [];
        foreach ($products as $product) {
            foreach ($filters as $key => $filter) {
                // skip if it's a price field
                if ($filter['id_item'] == $this->module->repository->getPriceFieldId($filter['id_filter'])) {
                    continue;
                }
                $result[$product][$key]['field_name'] = $filter['type'] . '_' . $filter['id_item'];
                switch ($filter['type']) {
                    case 'feature':
                        $result[$product][$key]['values'] = $this->indexProductFeature(
                            $filter['id_filter'],
                            $product,
                            $filter['position_inside']
                        );
                        break;
                    case 'attribute':
                        $result[$product][$key]['values'] = $this->indexProductAttribute(
                            $filter['id_filter'],
                            $product,
                            $filter['position_inside']
                        );
                        break;
                    case 'category':
                        $result[$product][$key]['values'] = $this->indexProductCategory(
                            $product,
                            $filter['position_inside']
                        );
                        break;
                    case 'other':
                        if ($filter['position_inside'] == 2) { // manufacturers
                            $result[$product][$key]['values'] = $this->indexManufacturer(
                                $product
                            );
                        }
                        if ($filter['position_inside'] == 3) { // suppliers
                            $result[$product][$key]['values'] = $this->indexSupplier(
                                $product
                            );
                        }
                        if ($filter['position_inside'] == 4) { // on sale
                            $result[$product][$key]['values'] = $this->indexProductOnSale(
                                $product
                            );
                        }
                        if ($filter['position_inside'] == 5) { //condition
                            $result[$product][$key]['values'] = $this->indexProductCondition(
                                $product
                            );
                        }
                        if ($filter['position_inside'] == 6) { // with image only
                            $result[$product][$key]['values'] = $this->indexProductImage(
                                $product
                            );
                        }
                        if ($filter['position_inside'] == 7) { //available only
                            $result[$product][$key]['values'] = $this->indexProductAvailable(
                                $product
                            );
                        }
                        break;
                }
            }
        }

        return $result;
    }

    /**
     * Reindex features fields for current product,
     * and add entries to feature index table (jxadvancedfilter_features_indexes)
     * used for rewrite entries after feature value deleted
     *
     * @param $id_filter (int) ID of filter which is reindexing now(used in child method)
     * @param $id_product (int) current product id
     * @param $id_feature_group (int) feature group id to index its entries
     *
     * @return false|null|string
     */
    protected function indexProductFeature($id_filter, $id_product, $id_feature_group)
    {
        $ids = $this->checkProductFeature($id_filter, $id_product, $id_feature_group);

        return $ids;
    }

    /**
     * Check if current feature group is related to product and build string with related feature values id,
     * and add entries to features index table (jxadvancedfilter_features_indexes)
     * used for rewrite entries after feature value deleted
     *
     * @param $id_filter    (int) ID of filter which is reindexing now
     * @param $id_product   (int) current product id
     * @param $id_feature_group (int) feature group id to index its entries
     *
     * @return bool|string ids imploded by "|"
     * @throws PrestaShopDatabaseException
     */
    protected function checkProductFeature($id_filter, $id_product, $id_feature_group)
    {
        $sql = 'SELECT fp.`id_feature_value`
                FROM '._DB_PREFIX_.'feature_product fp
                LEFT JOIN '._DB_PREFIX_.'product_shop ps
                ON(fp.`id_product` = ps.`id_product`)
                WHERE ps.`id_shop` = '.(int)$this->id_shop.'
                AND ps.`id_product` = '.(int)$id_product.'
                AND fp.`id_feature` = '.(int)$id_feature_group;

        if (!$result = Db::getInstance()->executeS($sql)) {
            return false;
        }

        foreach ($result as $id) {
            $this->updateFeaturesIndexes($id_filter, $id_product, $id_feature_group, $id['id_feature_value']);
            $ids[] = $id['id_feature_value'];
        }

        return implode('|', $ids);
    }

    /**
     * Reindex attribute fields for current product
     *
     * @param $id_filter (int) ID of filter which is reindexing now
     * @param $id_product (int) current product id
     * @param $id_attribute (int) attribute group id to index its entries
     *
     * @return bool|string
     */
    protected function indexProductAttribute($id_filter, $id_product, $id_attribute)
    {
        $ids = $this->checkProductAttribute($id_filter, $id_product, $id_attribute);

        return $ids;
    }

    /**
     * Check if current attribute group is related to product and build string with related attributes id,
     * and add entries to attribute index table (jxadvancedfilter_attribute_indexes)
     * used for rewrite entries after attribute value deleted
     *
     * @param $id_filter (int) ID of filter which is reindexing now
     * @param $id_product (int) current product id
     * @param $id_attribute (int) attribute group id to index its entries
     *
     * @return bool|string ids imploded by "|"
     * @throws PrestaShopDatabaseException
     */
    protected function checkProductAttribute($id_filter, $id_product, $id_attribute)
    {
        $ids = [];
        $sql = 'SELECT DISTINCT a.`id_attribute`
                FROM '._DB_PREFIX_.'attribute a
                LEFT JOIN '._DB_PREFIX_.'product_attribute_combination pac
                ON(a.`id_attribute` = pac.`id_attribute`)
                LEFT JOIN '._DB_PREFIX_.'product_attribute pa
                ON(pac.`id_product_attribute` = pa.`id_product_attribute`)
                LEFT JOIN '._DB_PREFIX_.'product_shop ps
                ON(pa.`id_product` = ps.`id_product`)
                WHERE a.`id_attribute_group` = '.(int)$id_attribute.'
                AND pa.`id_product` = '.(int)$id_product.'
                AND ps.`id_shop` = '.(int)$this->id_shop;
        if (!$result = Db::getInstance()->executeS($sql)) {
            return false;
        }
        foreach ($result as $id) {
            $this->updateAttributeIndexes($id_filter, $id_product, $id_attribute, $id['id_attribute']);
            $ids[] = $id['id_attribute'];
        }

        return implode('|', $ids);
    }

    /**
     * Index categories for current depth end put it to string imploded by "|",
     * example: if this products a related for several categories in this level
     * result will be like 1|2|3|5 (where numbers are the categories id)
     *
     * @param $id_product (int) current product id
     * @param $depth_level (int) depth categories level
     *
     * @return bool|string
     */
    protected function indexProductCategory($id_product, $depth_level)
    {
        $result = [];
        // get all categories for current depth
        $ids = FilterHelper::getCategoriesByDepth($depth_level, $this->id_shop);
        // if there are categories in the current level, check if current product is related to it
        if ($ids) {
            foreach ($ids as $id) {
                if ($this->checkProductInCategory($id_product, $id['id_category'])) {
                    $result[] = $id['id_category'];
                }
            }

            return implode('|', $result);
        }

        return false;
    }

    /**
     * Check if current product is related for category
     *
     * @param $id_product (int) current product id
     * @param $id_category (int) current category id
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    protected function checkProductInCategory($id_product, $id_category)
    {
        $sql = 'SELECT *
                FROM '._DB_PREFIX_.'category_product
                WHERE `id_category` = '.(int)$id_category.'
                AND `id_product` = '.(int)$id_product;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Index manufacturer if it is related to current product
     *
     * @param $id_product (int) current product id
     *
     * @return bool
     */
    protected function indexManufacturer($id_product)
    {
        $product = new Product($id_product, true);
        if ($product->id_manufacturer) {
            return $product->id_manufacturer;
        }

        return false;
    }

    /**
     * Index supplier if it is related to current product
     *
     * @param $id_product (int) current product id
     *
     * @return bool
     */
    protected function indexSupplier($id_product)
    {
        $suppliers = FilterHelper::getProductSuppliers($id_product);
        if (count($suppliers) > 1) {
            $result = [];
            foreach ($suppliers as $supplier) {
                $result[] = $supplier['id_supplier'];
            }

            return implode('|', $result);
        }
        $product = new Product($id_product, true);
        if ($product->id_supplier) {
            return $product->id_supplier;
        }

        return false;
    }

    /**
     * Index product "On Sale" status
     *
     * @param $id_product (int) current product id
     *
     * @return string
     */
    protected function indexProductOnSale($id_product)
    {
        $product = new Product($id_product, true);
        if ($product->on_sale) {
            $result = '1';
        } else {
            $result = '0';
        }

        return $result;
    }

    /**
     * Index product "condition"
     *
     * @param $id_product (int) current product id
     *
     * @return string
     */
    protected function indexProductCondition($id_product)
    {
        $conditions = [1 => 'new', 2 => 'used', 3 => 'refurbished'];
        $product = new Product($id_product, true);

        return array_search($product->condition, $conditions);
    }

    /**
     * Index product has image or not
     *
     * @param $id_product (int) current product id
     *
     * @return string
     */
    protected function indexProductImage($id_product)
    {
        $product = new Product($id_product, true);
        if ($product->getImages($this->id_lang)) {
            $result = 1;
        } else {
            $result = 0;
        }

        return $result;
    }

    /**
     * Index product is available
     *
     * @param $id_product (int) current product id
     *
     * @return string
     */
    protected function indexProductAvailable($id_product)
    {
        $product = new Product($id_product, true);
        if ($product->quantity > 0) {
            $result = 1;
        } else {
            $result = 0;
        }

        return $result;
    }

    /**
     * Generate indexes table with all products data
     *
     * @param $type (string) filter type
     * @param $data (array) all products data for current filter type
     *
     * @return bool
     */
    protected function generateIndexTable($type, $data, $part)
    {
        if ($part == 1) {
            // drop current filter type and shop table if exists
            if (!$this->dropIndexTable($type)) {
                return false;
            }
            // create new filter type table head by current filter parameters(fields used as table columns)
            if (!$this->createIndexTableHead($type, $data)) {
                return false;
            }
        }
        // fill created filter table
        if (!$this->fillIndexTable($type, $data)) {
            return false;
        }

        return true;
    }

    /**
     * Drop current filter type and shop table if exists,
     * name is generating from filter type and shop id for reduce tables size
     *
     * @param $table_name (string) used filter type
     *
     * @return bool
     */
    public function dropIndexTable($table_name)
    {
        $sql = 'DROP TABLE IF EXISTS `'._DB_PREFIX_.'jxadvancedfilter_indexed_'.$table_name.'_'.$this->id_shop.'`';
        if (!Db::getInstance()->execute($sql)) {
            return false;
        }

        return true;
    }

    /**
     * Create new filter type table head by current filter parameters(fields used as table columns)
     *
     * @param $type (string) used to know what head create
     * @param $data (array) used to generate table columns(like "category_11", "attribute_15" etc.)
     *
     * @return bool
     */
    private function createIndexTableHead($type, $data)
    {
        $names = $this->getFieldsInfo($data, 'field_name', true);
        $fields = '';
        foreach ($names as $name) {
            $fields .= '`' . $name . '` VARCHAR(100),';
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop.'` (
                  `id_index` int(11) NOT NULL AUTO_INCREMENT,
                  `id_shop` int(11) NOT NULL,
                  `id_product` int(11) NOT NULL,
                  '.$fields.'
                  PRIMARY KEY  (`id_index`)
                ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Decode data multi-array for get need information (columns names or values)
     *
     * @param      $data (array)
     * @param      $field (string) what info need (field_name or value)
     * @param bool $reset
     *
     * @return array
     */
    private function getFieldsInfo($data, $field, $reset = false)
    {
        $result = [];
        if ($reset) {
            $array = reset($data);
        } else {
            $array = $data;
        }
        foreach ($array as $element) {
            if (!isset($element[$field]) || $element[$field] == false) {
                $result[] = 0;
            } else {
                $result[] = $element[$field];
            }
        }

        return $result;
    }

    /**
     * Fill generated table by indexed data
     *
     * @param $type (string) type of filter
     * @param $data (array) indexed filter data
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    private function fillIndexTable($type, $data)
    {
        $result = true;
        foreach ($data as $id_product => $item) {
            $insert_data = [];
            $fields_names = $this->getFieldsInfo($data, 'field_name', true);
            $fields_values = $this->getFieldsInfo($item, 'values');
            foreach ($fields_names as $key => $name) {
                $insert_data['id_shop'] = $this->id_shop;
                $insert_data['id_product'] = $id_product;
                $insert_data[$name] = $fields_values[$key];
            }
            $result &= Db::getInstance()->insert('jxadvancedfilter_indexed_' . $type . '_' . $this->id_shop, $insert_data);
        }

        return $result;
    }

    /**
     * Generate indexed prices table
     *
     * @param $data (array) all products prices
     * @param $type (string) filter type
     *
     * @return bool
     */
    protected function generateIndexPriceTable($data, $type, $part, $taxes = false)
    {
        if (!$data) {
            return false;
        }
        if ($part == 1) {
            if (!$this->dropIndexPriceTable($type, $taxes)) {
                return false;
            }
            if (!$this->createIndexPriceTableHead($type, $taxes)) {
                return false;
            }
        }
        if (!$this->fillIndexPriceTable($type, $data, $taxes)) {
            return false;
        }

        return true;
    }

    /**
     * Get price indexes for products
     *
     * @param $products (array)
     *
     * @return array
     * @throws PrestaShopDatabaseException
     */
    private function generateFilterPriceIndexes($products, $taxes = false)
    {
        $result = [];
        $currency_list = Currency::getCurrencies(false, 1, new Shop($this->id_shop));
        foreach ($products as $product) {
            $min_price = [];
            $max_price = [];
            $product_min_prices = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT id_shop, id_currency, id_country, id_group, from_quantity
			    FROM `'._DB_PREFIX_.'specific_price`
			    WHERE id_product = '.(int)$product
            );
            foreach ($currency_list as $currency) {
                $price = Product::priceCalculation(
                    $this->id_shop,
                    (int)$product,
                    null,
                    $this->context->currency->id,
                    null,
                    null,
                    $currency['id_currency'],
                    Configuration::get('PS_CUSTOMER_GROUP'),
                    1,
                    (int)$taxes,
                    6,
                    false,
                    true,
                    true,
                    $null,
                    true
                );
                if (!isset($max_price[$currency['id_currency']])) {
                    $max_price[$currency['id_currency']] = 0;
                }
                if (!isset($min_price[$currency['id_currency']])) {
                    $min_price[$currency['id_currency']] = null;
                }
                if ($price > $max_price[$currency['id_currency']]) {
                    $max_price[$currency['id_currency']] = $price;
                }
                if ($price == 0) {
                    continue;
                }
                if (is_null($min_price[$currency['id_currency']]) || $price < $min_price[$currency['id_currency']]) {
                    $min_price[$currency['id_currency']] = $price;
                }
            }
            foreach ($product_min_prices as $specific_price) {
                foreach ($currency_list as $currency) {
                    if ($specific_price['id_currency'] && $specific_price['id_currency'] != $currency['id_currency']) {
                        continue;
                    }
                    $price = Product::priceCalculation(
                        (($specific_price['id_shop'] == 0) ? null : (int)$specific_price['id_shop']),
                        (int)$product,
                        null,
                        (($specific_price['id_country'] == 0) ? null : $specific_price['id_country']),
                        null,
                        null,
                        $currency['id_currency'],
                        (($specific_price['id_group'] == 0) ? null : $specific_price['id_group']),
                        $specific_price['from_quantity'],
                        (int)$taxes,
                        6,
                        false,
                        true,
                        true,
                        $null,
                        true
                    );
                    if (!isset($max_price[$currency['id_currency']])) {
                        $max_price[$currency['id_currency']] = 0;
                    }
                    if (!isset($min_price[$currency['id_currency']])) {
                        $min_price[$currency['id_currency']] = null;
                    }
                    if ($price > $max_price[$currency['id_currency']]) {
                        $max_price[$currency['id_currency']] = $price;
                    }
                    if ($price == 0) {
                        continue;
                    }
                    if (is_null($min_price[$currency['id_currency']])
                        || $price < $min_price[$currency['id_currency']]
                    ) {
                        $min_price[$currency['id_currency']] = $price;
                    }
                }
            }
            foreach ($currency_list as $currency) {
                $result[$product][$currency['id_currency']]['price_min'] = (int)$min_price[$currency['id_currency']];
                $result[$product][$currency['id_currency']]['price_max'] = (int)Tools::ps_round(
                    $max_price[$currency['id_currency']],
                    0,
                    PS_ROUND_UP
                );
            }
        }

        return $result;
    }

    /**
     * Drop indexed prices table
     *
     * @param $table_name (string) type of filter
     *
     * @return bool
     */
    public function dropIndexPriceTable($table_name, $taxes)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $sql = 'DROP TABLE IF EXISTS `'._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$table_name.'_'.$this->id_shop.'`';
        if (!Db::getInstance()->execute($sql)) {
            return false;
        }

        return true;
    }

    /**
     * Fill indexed prices table
     *
     * @param $type (string) filder type
     * @param $data (array) prices data
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    private function fillIndexPriceTable($type, $data, $taxes)
    {
        $result = true;
        if ($taxes) {
            $taxes = 'taxes_';
        }
        foreach ($data as $id_product => $currencies) {
            foreach ($currencies as $id_currency => $item) {
                $insert_data = [];
                $insert_data['id_product'] = $id_product;
                $insert_data['id_currency'] = $id_currency;
                $insert_data['price_min'] = $item['price_min'];
                $insert_data['price_max'] = $item['price_max'];
                $result &= Db::getInstance()->insert(
                    'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop,
                    $insert_data
                );
            }
        }

        return $result;
    }

    /**
     * Create indexed prices table by filter type
     *
     * @param $type (string)
     *
     * @return bool
     */
    private function createIndexPriceTableHead($type, $taxes)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop.'` (
                  `id_price_index` int(11) NOT NULL AUTO_INCREMENT,
                  `id_product` int(11) NOT NULL,
                  `id_currency` int(11) NOT NULL,
                  `price_min` VARCHAR(100),
                  `price_max` VARCHAR(100),
                  PRIMARY KEY (`id_price_index`, `id_product`, `id_currency`),
                  INDEX `id_currency` (`id_currency`),
                  INDEX `price_min` (`price_min`), INDEX `price_max` (`price_max`)
                ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Update/add index table for defined product id
     *
     * @param $type (string) filter type
     * @param $id_product (int) defined product id
     * @param $data (array) indexed product data
     *
     * @return bool
     */
    protected function updateIndexTable($type, $id_product, $data)
    {
        $result = true;
        $fields_names = $this->getFieldsInfo($data, 'field_name', true);
        $fields_values = $this->getFieldsInfo($data[$id_product], 'values');
        $insert_data = [];
        foreach ($fields_names as $key => $name) {
            $insert_data[$name] = $fields_values[$key];
        }
        if ($this->checkProductIndexesEntry($type, $id_product)) {
            $result &= Db::getInstance()->update(
                'jxadvancedfilter_indexed_' . $type . '_' . $this->id_shop,
                $insert_data,
                '`id_product` = ' . (int)$id_product
            );
        } else {
            $insert_data['id_shop'] = $this->id_shop;
            $insert_data['id_product'] = $id_product;
            $result &= Db::getInstance()->insert('jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop, $insert_data);
        }

        return $result;
    }

    /**
     * Check if table contain product indexes
     *
     * @param $type (string) filter type
     * @param $id_product (int) product id
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    protected function checkProductIndexesEntry($type, $id_product)
    {
        $sql = 'SELECT *
                FROM '._DB_PREFIX_.'jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop.'
                WHERE `id_product` = '.(int)$id_product;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Update index price table by defined product id
     *
     * @param $type (string) filter type
     * @param $id_product (int) defined product id
     * @param $prices (array) indexed product price data
     *
     * @return bool
     */
    protected function updateIndexPriceTable($type, $id_product, $prices, $taxes = false)
    {
        $result = true;
        if ($taxes) {
            $taxes = 'taxes_';
        }
        foreach ($prices as $currencies) {
            $insert_data = [];
            foreach ($currencies as $id_currency => $item) {
                $insert_data['price_min'] = $item['price_min'];
                $insert_data['price_max'] = $item['price_max'];
                if ($this->checkProductPricesIndexesEntry($type, $id_product, $id_currency, true)) {
                    $result &= Db::getInstance()->update(
                        'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop,
                        $insert_data,
                        '`id_product` = '.(int)$id_product.' AND `id_currency` = '.(int)$id_currency
                    );
                } else {
                    $insert_data = [];
                    $insert_data['id_product'] = $id_product;
                    $insert_data['id_currency'] = $id_currency;
                    $result &= Db::getInstance()->insert(
                        'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop,
                        $insert_data
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Check if price table contain product indexes
     *
     * @param $type (string) filter type
     * @param $id_product (int) filter id
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    protected function checkProductPricesIndexesEntry($type, $id_product, $id_currency, $taxes = false)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $sql = 'SELECT *
                FROM '._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop.'
                WHERE `id_product` = '.(int)$id_product.'
                AND `id_currency` = '.(int)$id_currency;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Drop index table column when parameter no more available for shop (when delete feature, attribute atc.)
     *
     * @param $type (string) filter type
     * @param $column (string) column name
     *
     * @return bool
     */
    public function dropIndexTableColumn($type, $column)
    {
        $sql = 'ALTER TABLE '._DB_PREFIX_.'jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop.'
                DROP COLUMN '.$column;

        return Db::getInstance()->execute($sql);
    }

    /**
     * Clear attributes index table before filter reindexing
     *
     * @param $id_filter (int) filter id
     *
     * @return bool
     */
    protected function clearAttributesIndexTable($id_filter)
    {
        return Db::getInstance()->delete('jxadvancedfilter_attributes_indexes', '`id_filter` = '.(int)$id_filter);
    }

    /**
     * Clear features index table before filter reindexing
     *
     * @param $id_filter (int) filter id
     *
     * @return bool
     */
    protected function clearFeaturesIndexTable($id_filter)
    {
        return Db::getInstance()->delete('jxadvancedfilter_features_indexes', '`id_filter` = '.(int)$id_filter);
    }

    /**
     * Update attribute indexes in table
     *
     * @param $id_filter
     * @param $id_product
     * @param $id_attribute
     * @param $value
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    protected function updateAttributeIndexes($id_filter, $id_product, $id_attribute, $value)
    {
        $result = true;
        $result &= Db::getInstance()->insert(
            'jxadvancedfilter_attributes_indexes',
            [
                'id_filter'          => (int)$id_filter,
                'id_product'         => (int)$id_product,
                'id_attribute'       => (int)$id_attribute,
                'id_attribute_value' => (int)$value
            ]
        );

        return $result;
    }

    /**
     * Update feature indexes in table
     *
     * @param $id_filter
     * @param $id_product
     * @param $id_feature
     * @param $value
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    protected function updateFeaturesIndexes($id_filter, $id_product, $id_feature, $value)
    {
        $result = true;
        $result &= Db::getInstance()->insert(
            'jxadvancedfilter_features_indexes',
            [
                'id_filter'        => (int)$id_filter,
                'id_product'       => (int)$id_product,
                'id_feature'       => (int)$id_feature,
                'id_feature_value' => (int)$value
            ]
        );

        return $result;
    }

    /**
     * Get attribute group id by attribute value id
     *
     * @param $id_filter
     * @param $id_attribute_value
     *
     * @return false|null|string
     */
    public static function checkAttributeIndex($id_filter, $id_attribute_value)
    {
        $sql = 'SELECT `id_attribute`
            FROM '._DB_PREFIX_.'jxadvancedfilter_attributes_indexes
            WHERE `id_filter` = '.(int)$id_filter.'
            AND `id_attribute_value` = '.(int)$id_attribute_value;

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Get feature group id by feature value id
     *
     * @param $id_filter
     * @param $id_feature_value
     *
     * @return false|null|string
     */
    public static function checkFeatureIndex($id_filter, $id_feature_value)
    {
        $sql = 'SELECT `id_feature`
            FROM '._DB_PREFIX_.'jxadvancedfilter_features_indexes
            WHERE `id_filter` = '.(int)$id_filter.'
            AND `id_feature_value` = '.(int)$id_feature_value;

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Delete attribute group id by attribute value id
     *
     * @param $id_filter
     * @param $id_attribute_value
     *
     * @return false|null|string
     */
    public static function deleteAttributeValueIndex($id_filter, $id_attribute_value)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_attributes_indexes',
            '`id_filter` = '.(int)$id_filter.' AND `id_attribute_value` = '.(int)$id_attribute_value
        );
    }

    /**
     * Delete feature value
     *
     * @param $id_filter
     * @param $id_feature_value
     *
     * @return false|null|string
     */
    public static function deleteFeatureValueIndex($id_filter, $id_feature_value)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_features_indexes',
            '`id_filter` = '.(int)$id_filter.' AND `id_feature_value` = '.(int)$id_feature_value
        );
    }

    /**
     * Delete feature group
     *
     * @param $id_filter
     * @param $id_feature
     *
     * @return false|null|string
     */
    public static function deleteFeatureIndex($id_filter, $id_feature)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_features_indexes',
            '`id_filter` = '.(int)$id_filter.' AND `id_feature` = '.(int)$id_feature
        );
    }

    /**
     * Delete attribute group
     *
     * @param $id_filter
     * @param $id_attribute_group
     *
     * @return false|null|string
     */
    public static function deleteAttributeIndex($id_filter, $id_attribute_group)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_attributes_indexes',
            '`id_filter` = '.(int)$id_filter.' AND `id_attribute` = '.(int)$id_attribute_group
        );
    }

    public function deleteAllFilterFeaturesIndexes($id_filter)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_features_indexes',
            '`id_filter` = '.(int)$id_filter
        );
    }

    public function deleteAllFilterAttributesIndexes($id_filter)
    {
        return Db::getInstance()->delete(
            'jxadvancedfilter_attributes_indexes',
            '`id_filter` = '.(int)$id_filter
        );
    }
}
