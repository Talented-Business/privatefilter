<?php
/**
 *
 *
 *
 * @author    Lazutina
 * @copyright 2019 Lazutina
 */

use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrderFactory;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;
use Symfony\Component\Translation\TranslatorInterface;

class PrivateFiltersFilterModuleFrontController extends ProductListingFrontController
{
    //public $php_self = 'filter';

    /**
     * Initializes controller.
     *
     * @see FrontController::init()
     *
     * @throws PrestaShopException
     */
    public function init()
    {
        parent::init();
        $this->initContent();        
        $this->doProductSearch('catalog/listing/search', array('entity' => 'search'));
    }
    public function checkAccess(){
        return true;
    }
    protected function getProductSearchQuery()
    {
        $query = new ProductSearchQuery();
        $query
            ->setQueryType('jxsearch')
            ->setSortOrder(new SortOrder('product', 'date_add', 'desc'));

        return $query;
    }

    protected function getDefaultProductSearchProvider()
    {
        return new PrivateFilterProvider(
            $this->getTranslator()
        );
    }

    public function getListingLabel()
    {
        return $this->trans(
            'Search by Filters',
            array(),
            'Shop.Theme.Catalog'
        );
    }
}
