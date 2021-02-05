<?php
/**
 * Created by Q-Solutions Studio
 *
 * @category    DataFeedWatch
 * @package     DataFeedWatch_Connector
 * @author      Wojciech M. Wnuk <wojtek@qsolutionsstudio.com>
 */

namespace DataFeedWatch\Connector\Plugin;

use DataFeedWatch\Connector\Api\Data\QtyAndStockInterface;
use DataFeedWatch\Connector\Model\QtyAndStockFactory;
use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Catalog\Api\Data\ProductExtensionFactory;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;
use Magento\GroupedProduct\Model\Product\Type\Grouped;

/**
 * Class QtyAndStock
 * @package DataFeedWatch\Connector\Plugin
 */
class QtyAndStock extends Quantity
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var State
     */
    protected $state;
    /**
     * @var RequestInterface
     */
    protected $request;
    /**
     * @var DataObjectHelper
     */
    protected $dataObjectHelper;
    /**
     * @var QtyAndStockFactory
     */
    protected $qtyAndStockFactory;

    /**
     * QtyAndStock constructor.
     * @param ProductExtensionFactory $extensionFactory
     * @param ResourceConnection $resourceConnection
     * @param Manager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     * @param State $state
     * @param RequestInterface $request
     * @param QtyAndStockFactory $qtyAndStockFactory
     * @param DataObjectHelper $dataObjectHelper
     */
    public function __construct(
        ProductExtensionFactory $extensionFactory,
        ResourceConnection $resourceConnection,
        Manager $moduleManager,
        ScopeConfigInterface $scopeConfig,
        State $state,
        RequestInterface $request,
        QtyAndStockFactory $qtyAndStockFactory,
        DataObjectHelper $dataObjectHelper
    ) {
        parent::__construct($extensionFactory, $resourceConnection, $moduleManager);
        $this->scopeConfig = $scopeConfig;
        $this->state = $state;
        $this->request = $request;
        $this->dataObjectHelper = $dataObjectHelper;
        $this->qtyAndStockFactory = $qtyAndStockFactory;
    }

    /**
     * @param Product $product
     * @return Product
     */
    protected function setExtensionAttribute(Product $product): Product
    {
        try {
            if (
                $this->state->getAreaCode() == Area::AREA_WEBAPI_REST
                && $this->request->getParam('add_stock', false)
                && !in_array($product->getTypeId(), [Configurable::TYPE_CODE, Grouped::TYPE_CODE, Bundle::TYPE_CODE])
            ) {

                $extensionAttributes = $product->getExtensionAttributes();
                $extensionAttributes = $extensionAttributes ?? $this->extensionFactory->create();
                $qtyAndStock = $this->qtyAndStockFactory->create();
                $qtyAndStockData = [
                    'qty' => $this->getQuantity($product),
                    'manage_stock' => (bool)$this->getManageStock($product),
                    'is_in_stock' => (bool)$this->getStockStatus($product),
                ];
                $this->dataObjectHelper->populateWithArray($qtyAndStock, $qtyAndStockData, QtyAndStockInterface::class);
                $extensionAttributes->setQtyAndStock($qtyAndStock);
                $product->setExtensionAttributes($extensionAttributes);
            }
        } catch (LocalizedException $e) {
            return $product;
        }
        return $product;
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function getManageStock(Product $product): bool
    {
        $connection = $this->resourceConnection->getConnection();

        $tableName = $this->resourceConnection->getTableName(self::LEGACY_STOCK_TABLE);

        $query = sprintf(
            "SELECT `manage_stock`,`use_config_manage_stock` FROM `%s` WHERE `product_id` = '%s'%s",
            $tableName, $product->getId(), $product->getWebsiteId() ? sprintf(" AND `website_id` = %s", $product->getWebsiteId()) : ''
        );

        $manageStock = $connection->fetchRow($query);

        if (!$manageStock['use_config_manage_stock']) {
            return $manageStock['manage_stock'];
        }

        return $this->scopeConfig->getValue('cataloginventory/item_options/manage_stock');
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function getStockStatus(Product $product): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::STOCK_TABLE);

        if ($this->moduleManager->isEnabled('Magento_Inventory')) {
            $query = sprintf("SELECT SUM(`status`) FROM `%s` WHERE `sku` = '%s'", $tableName, $product->getSku());
        } else {
            $tableName = $this->resourceConnection->getTableName(self::LEGACY_STOCK_TABLE);

            $query = sprintf(
                "SELECT SUM(`is_in_stock`) FROM `%s` WHERE `product_id` = '%s'%s",
                $tableName,
                $product->getId(),
                $product->getWebsiteId() ? sprintf(" AND `website_id` = %s", $product->getWebsiteId()) : ''
            );
        }

        return $connection->fetchOne($query) > 0;
    }
}