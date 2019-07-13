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

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrderFactory;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;

class PrivateFilterProvider implements ProductSearchProviderInterface
{
    private $translator;
    private $sortOrderFactory;

    public function __construct(
        TranslatorInterface $translator
    ) {
        $this->translator = $translator;
        $this->sortOrderFactory = new SortOrderFactory($this->translator);
    }
    private function filter($make,$model,$year)
    {
        $query = '';
        $join = '';
        $taxes = '';
        $ids = [];
        if($model != ''){
            $join .= ' LEFT JOIN '._DB_PREFIX_.'feature_product model on make.`id_product` = model.`id_product` ';
        }
        if($year != ''){
            $join .= ' LEFT JOIN '._DB_PREFIX_.'feature_product year on make.`id_product` = year.`id_product` ';
        }
        $sql = 'SELECT make.`id_product`
                FROM '._DB_PREFIX_.'feature_product make
                '.$join;
        if($make=='') $sql .=' WHERE 1 group by make.`id_product`';
        else $sql .=' WHERE make.`id_feature_value` = '.(int)$make;
        if($model != ''){
            $sql .= ' and model.`id_feature_value` = '.(int)$model;
        }
        if($year != ''){
            $sql .= ' and year.`id_feature_value` = '.(int)$year;
        }
        if (!$result = Db::getInstance()->executeS($sql)) {
            return false;
        }
        foreach ($result as $id) {
            $ids[] = $id['id_product'];
        }

        return $ids;
    }

    private function getProductsOrCount(
        ProductSearchContext $context,
        ProductSearchQuery $query,
        $type = 'products'
    ) {
        $all_parameters = Tools::getAllValues();
        $p = abs((int)(Tools::getValue('page', 1)));
        if (!$all_parameters) {
            if ($type == 'products') {
                return [];
            } else {
                return 0;
            }
        } else {
            $make = isset($all_parameters['make'])?$all_parameters['make']:'';
            $model = isset($all_parameters['model'])?$all_parameters['model']:'';
            $year = isset($all_parameters['year'])?$all_parameters['year']:'';
            $result_products = $this->filter($make,$model,$year);
            if ($result_products) {
                $n = abs((int)(Tools::getValue('n', $query->getResultsPerPage())));
                $p = abs((int)(Tools::getValue('page', 1)));
                $order_by = $query->getSortOrder()->toLegacyOrderBy();
                $order_way = $query->getSortOrder()->toLegacyOrderWay();
                $nbProducts = count($result_products);
                if(!class_exists('FilterHelper')){
                    require_once(dirname(__FILE__).'/classes/FilterHelper.php');
                }
                $products = FilterHelper::getProducts(
                    $result_products,
                    Context::getContext()->language->id,
                    $p,
                    $n,
                    $order_by,
                    $order_way
                );
                if ($type == 'products') {
                    return $products;
                } else {
                    return count($result_products);
                }
            }
        }

        return false;
    }

    public function runQuery(
        ProductSearchContext $context,
        ProductSearchQuery $query
    ) {
        if (!$products = $this->getProductsOrCount($context, $query)) {
            $products = array();
        }
        $count = $this->getProductsOrCount($context, $query, 'count');
        $result = new ProductSearchResult();
        $result
            ->setProducts($products)
            ->setTotalProductsCount($count);
        $result->setAvailableSortOrders(
            array(
                (new SortOrder('product', 'date_add', 'desc'))->setLabel(
                    $this->translator->trans('Date add, newest to oldest', array(), 'Shop.Theme.Catalog')
                ),
                (new SortOrder('product', 'date_add', 'asc'))->setLabel(
                    $this->translator->trans('Date add, oldest to newest', array(), 'Shop.Theme.Catalog')
                ),
                (new SortOrder('product', 'name', 'asc'))->setLabel(
                    $this->translator->trans('Name, A to Z', array(), 'Shop.Theme.Catalog')
                ),
                (new SortOrder('product', 'name', 'desc'))->setLabel(
                    $this->translator->trans('Name, Z to A', array(), 'Shop.Theme.Catalog')
                ),
                (new SortOrder('product', 'price', 'asc'))->setLabel(
                    $this->translator->trans('Price, low to high', array(), 'Shop.Theme.Catalog')
                ),
                (new SortOrder('product', 'price', 'desc'))->setLabel(
                    $this->translator->trans('Price, high to low', array(), 'Shop.Theme.Catalog')
                ),
            )
        );

        return $result;
    }
}
