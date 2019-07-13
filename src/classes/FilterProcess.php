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

class FilterProcess
{
    public $id_shop;
    public $id_lang;
    public $id_currency;
    public $module;

    public function __construct()
    {
        $this->id_shop = Context::getContext()->shop->id;
        $this->id_lang = Context::getContext()->language->id;
        $this->id_currency = Context::getContext()->currency->id;
        $this->module = new Jxadvancedfilter();
    }

    /**
     * Get all products id appropriate to parameters
     *
     * @param $type (string) filter type (to know in what table search)
     * @param $parameters (array) all parameters for searching
     *
     * @return array|bool
     * @throws PrestaShopDatabaseException
     */
    public function filter($type, $parameters)
    {
        if (!$parameters || !$type) {
            return false;
        }
        $query = '';
        $join = '';
        $taxes = '';
        $ids = [];
        $id_filter = $this->module->repository->checkFilterExists($type);
        $price_parameter = '';
        if ($id_filter && $id_price_filed = $this->module->repository->getPriceFieldId($id_filter)) {
            $price_parameter = 'other_'.$this->module->repository->getPriceFieldId($id_filter);
        }
        // if it wasn't a price field check it maybe it is a price with taxes included field
        if (!$price_parameter) {
            $price_parameter = 'other_'.$this->module->repository->getPriceTaxInclFieldId($id_filter);
            $taxes = 'taxes_';
        }
        foreach ($parameters as $name => $parameter) {
            if (is_array($parameter)) {
                $parameter = $parameter[0];
            }
            if ($parameter && $name != $price_parameter) {
                if (strpos($parameter, '|')) {
                    $values = explode('|', $parameter);
                    foreach ($values as $k => $value) {
                        $query .= $this->buildLikeMultiQuery($k, $name, $value, count($values));
                    }
                } else {
                    $query .= $this->buildLikeSimpleQuery($name, $parameter);
                }
            } elseif ($parameter && $name == $price_parameter) {
                $join = 'LEFT JOIN '._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$this->id_shop.' jxpi
                        ON(jxpi.`id_product` = jxi.`id_product`)';
                $query .= $this->buildPriceQuery($parameter);
            }
        }
        $sql = 'SELECT jxi.`id_product`
                FROM '._DB_PREFIX_.'jxadvancedfilter_indexed_'.$type.'_'.$this->id_shop.' jxi
                '.$join.'
                WHERE jxi.`id_shop` = '.(int)$this->id_shop.$query;
        if (!$result = Db::getInstance()->executeS($sql)) {
            return false;
        }
        foreach ($result as $id) {
            $ids[] = $id['id_product'];
        }

        return $ids;
    }

    /**
     * Build simple "Like" query
     *
     * @param $name (string) name of field to searching
     * @param $parameter (string) searching values
     *
     * @return string
     */
    private function buildLikeSimpleQuery($name, $parameter)
    {
        return ' AND (jxi.`'.$name.'` LIKE \''.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'\' OR jxi.`'.$name.'` = '.$parameter.')';
    }

    /**
     * Build "Like" query for search(multi)
     *
     * @param $k (int) current step of multi query
     * @param $name (string) name of field to searching
     * @param $parameter (string) searching values
     * @param $num (int) num of searching values
     *
     * @return string
     */
    private function buildLikeMultiQuery($k, $name, $parameter, $num)
    {
        if ($k == 0) {
            $query = ' AND ((jxi.`'.$name.'` LIKE \''.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'\' OR jxi.`'.$name.'` = '.$parameter.')';
        } else {
            $query = ' OR (jxi.`'.$name.'` LIKE \''.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'|%\' OR jxi.`'.$name.'` LIKE \'%|'.$parameter.'\' OR jxi.`'.$name.'` = '.$parameter.')';
        }
        if ($k == $num - 1) {
            $query .= ')';
        }

        return $query;
    }

    /**
     * Build price query
     *
     * @param $parameters
     *
     * @return string
     */
    private function buildPriceQuery($parameters)
    {
        if (!$parameters) {
            return '';
        }
        $query = '';
        $parameters = explode('|', $parameters);
        if ($parameters[0]) {
            $query .= ' AND jxpi.`price_min` >= '.(int)$parameters[0];
        }
        if ($parameters[1]) {
            $query .= ' AND jxpi.`price_max` <= '.(int)$parameters[1];
        }
        $query .= ' AND jxpi.`id_currency` = '.(int)$this->id_currency;

        return $query;
    }

    /**
     * Get maximum price in indexed prices table
     *
     * @param $type (string) filter name
     * @param $id_shop (id) shop id
     * @param $id_currency (int) currency id
     *
     * @return false|null|string
     */
    public static function getMaxIndexPrice($type, $id_shop, $id_currency, $taxes = false)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $sql = 'SELECT MAX(`price_max`)
                FROM '._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$id_shop.'
                WHERE `id_currency` = '.(int)$id_currency;

        return Db::getInstance()->getValue($sql);
    }

    /**
     * Get minimum price in indexed prices table
     *
     * @param $type (string) filter name
     * @param $id_shop (id) shop id
     * @param $id_currency (int) currency id
     *
     * @return false|null|string
     */
    public static function getMinIndexPrice($type, $id_shop, $id_currency, $taxes = false)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $sql = 'SELECT MIN(`price_min`)
                FROM '._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$id_shop.'
                WHERE `id_currency` = '.(int)$id_currency;

        return Db::getInstance()->getValue($sql);
    }

    /**
     *  Get all indexed prices values to build price range
     *
     * @param $type (string)
     * @param $id_shop (int)
     * @param $id_currency (int)
     *
     * @return array
     * @throws PrestaShopDatabaseException
     */
    public static function getAllIndexPrices($type, $id_shop, $id_currency, $taxes = false)
    {
        if ($taxes) {
            $taxes = 'taxes_';
        }
        $prices = [];
        $sql = 'SELECT `price_min`, `price_max`
                FROM '._DB_PREFIX_.'jxadvancedfilter_price_'.$taxes.'indexed_'.$type.'_'.$id_shop.'
                WHERE `id_currency` = '.(int)$id_currency;
        if (!$result = Db::getInstance()->executeS($sql)) {
            return $prices;
        }
        foreach ($result as $price) {
            if ($price['price_min'] != $price['price_max']) {
                $prices[] = $price['price_min'];
            }
            $prices[] = $price['price_max'];
        }

        return $prices;
    }
}
