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

class FilterHelper
{
    public static function getCategoriesDepth($id_shop)
    {
        $sql = 'SELECT DISTINCT c.`level_depth`
                FROM '._DB_PREFIX_.'category c
                LEFT JOIN '._DB_PREFIX_.'category_shop cs
                ON(cs.`id_category` = c.`id_category`)
                WHERE cs.`id_shop` = '.(int)$id_shop.'
                AND c.`level_depth` > 0';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get all categories from the depth
     *
     * @param $level_depth (int)
     * @param $id_shop (int)
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    public static function getCategoriesByDepth($level_depth, $id_shop)
    {
        $sql = 'SELECT c.`id_category`, c.`id_parent`
                FROM '._DB_PREFIX_.'category c
                LEFT JOIN '._DB_PREFIX_.'category_shop cs
                ON(cs.`id_category`=c.`id_category`)
                WHERE cs.`id_shop` = '.(int)$id_shop.'
                AND c.`level_depth` = '.(int)$level_depth.'
                AND c.`active` = 1';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get all categories children ids
     *
     * @param $id_category (int)
     *
     * @return array of children ids
     */
    public static function getCategoryChildrenIds($id_category)
    {
        $ids = [];
        $category = new Category($id_category);
        foreach ($category->getParentsCategories() as $id) {
            $ids[] = $id['id_category'];
        }

        return $ids;
    }

    /**
     * Get all products related to category
     *
     * @param $id_category (int)
     * @param $id_shop (int)
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    public static function getAllCategoryProducts($id_category, $id_shop)
    {
        $sql = 'SELECT cp.`id_product`
                FROM '._DB_PREFIX_.'category_product cp
                LEFT JOIN '._DB_PREFIX_.'category_shop cs
                ON(cs.`id_category` = cp.`id_category`)
                WHERE cp.`id_category` = '.(int)$id_category.'
                AND cs.`id_shop` = '.(int)$id_shop;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get all manufacturers list for shop by shop id
     *
     * @param $id_shop (int)
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    public static function getManufacturers($id_shop)
    {
        $sql = 'SELECT m.`id_manufacturer`
                FROM '._DB_PREFIX_.'manufacturer m
                LEFT JOIN '._DB_PREFIX_.'manufacturer_shop ms
                ON(ms.`id_manufacturer` = m.`id_manufacturer`)
                WHERE ms.`id_shop` = '.(int)$id_shop.'
                AND m.`active` = 1';

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    }

    /**
     * Get all suppliers list for shop by shop id
     *
     * @param $id_shop (int)
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    public static function getSuppliers($id_shop)
    {
        $sql = 'SELECT s.`id_supplier`
                FROM '._DB_PREFIX_.'supplier s
                LEFT JOIN '._DB_PREFIX_.'supplier_shop ss
                ON(ss.`id_supplier` = s.`id_supplier`)
                WHERE ss.`id_shop` = '.(int)$id_shop.'
                AND s.`active` = 1';

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    }

    /**
     * Get all suppliers related to product by product id
     *
     * @param $id_product (int)
     *
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     */
    public static function getProductSuppliers($id_product)
    {
        $sql = 'SELECT ps.`id_supplier`
                FROM '._DB_PREFIX_.'product_supplier ps
                WHERE ps.`id_product` = '.(int)$id_product;

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get all products information appropriate to selected parameters for frontend listing
     *
     * @param      $products_ids (array) list of products
     * @param      $id_lang (int)
     * @param      $p (int) products pr
     * @param      $n
     * @param null $order_by
     * @param null $order_way
     *
     * @return array|bool
     * @throws PrestaShopDatabaseException
     */
    public static function getProducts($products_ids, $id_lang, $p, $n, $order_by = null, $order_way = null)
    {
        $context = Context::getContext();
        if ($p < 1) {
            $p = 1;
        }
        if (empty($order_by) || $order_by == 'position') {
            $order_by = 'name';
        }
        if (empty($order_way)) {
            $order_way = 'ASC';
        }
        if (!Validate::isOrderBy($order_by) || !Validate::isOrderWay($order_way)) {
            die(Tools::displayError());
        }
        if (strpos($order_by, '.') > 0) {
            $order_by = explode('.', $order_by);
            $order_by = pSQL($order_by[0]) . '.`' . pSQL($order_by[1]) . '`';
        }
        if ($order_by == 'price') {
            $alias = 'product_shop.';
        } elseif ($order_by == 'name') {
            $alias = 'pl.';
        } elseif ($order_by == 'manufacturer_name') {
            $order_by = 'name';
            $alias = 'm.';
        } elseif ($order_by == 'quantity') {
            $alias = 'stock.';
        } else {
            $alias = 'p.';
        }
        $sql = 'SELECT p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity'
            . (Combination::isFeatureActive() ? ', product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity, IFNULL(product_attribute_shop.`id_product_attribute`,0) id_product_attribute' : '') . '
			, pl.`description`, pl.`description_short`, pl.`link_rewrite`, pl.`meta_description`, pl.`meta_keywords`,
			pl.`meta_title`, pl.`name`, pl.`available_now`, pl.`available_later`, image_shop.`id_image` id_image, il.`legend`, m.`name` AS manufacturer_name,
				DATEDIFF(
					product_shop.`date_add`,
					DATE_SUB(
						"' . date('Y-m-d') . ' 00:00:00",
						INTERVAL ' . (Validate::isUnsignedInt(Configuration::get('PS_NB_DAYS_NEW_PRODUCT')) ? Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20) . ' DAY
					)
				) > 0 AS new'
            . ' FROM `' . _DB_PREFIX_ . 'product` p
			' . Shop::addSqlAssociation('product', 'p') .
            (Combination::isFeatureActive() ? 'LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` product_attribute_shop
						ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop=' . (int)$context->shop->id . ')' : '') . '
			LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
				ON (p.`id_product` = pl.`id_product` AND pl.`id_lang` = ' . (int)$id_lang . Shop::addSqlRestrictionOnLang('pl') . ')
				LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` image_shop
					ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop=' . (int)$context->shop->id . ')
			LEFT JOIN `' . _DB_PREFIX_ . 'image_lang` il
				ON (image_shop.`id_image` = il.`id_image` AND il.`id_lang` = ' . (int)$id_lang . ')
			LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
				ON (m.`id_manufacturer` = p.`id_manufacturer`)
			' . Product::sqlStock('p', 0);
        $sql .= '
				WHERE p.`id_product` IN (' . implode(',', $products_ids) . ')
				AND product_shop.`active` = 1
				AND product_shop.`visibility` IN ("both", "catalog")
				GROUP BY p.id_product
				ORDER BY ' . $alias . '`' . bqSQL($order_by) . '` ' . pSQL($order_way) . '
				LIMIT ' . (((int)$p - 1) * (int)$n) . ',' . (int)$n;
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        if (!$result) {
            return false;
        }
        if ($order_by == 'price') {
            Tools::orderbyPrice($result, $order_way);
        }

        return Product::getProductsProperties($id_lang, $result);
    }
}
