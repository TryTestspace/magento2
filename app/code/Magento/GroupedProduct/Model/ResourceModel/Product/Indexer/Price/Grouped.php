<?php
/**
 * Grouped Products Price Indexer Resource model
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\GroupedProduct\Model\ResourceModel\Product\Indexer\Price;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\DimensionalIndexerInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Catalog\Model\Indexer\Product\Price\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\IndexTableStructureFactory;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\IndexTableStructure;
use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\PriceModifierInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;
use Magento\Customer\Model\Indexer\CustomerGroupDimensionProvider;

/**
 * Calculate minimal and maximal prices for Grouped products
 * Use calculated price for relation products
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Grouped implements DimensionalIndexerInterface
{
    /**
     * Prefix for temporary table support.
     */
    const TRANSIT_PREFIX = 'transit_';

    /**
     * @var BaseFinalPrice
     */
    private $baseFinalPrice;

    /**
     * @var IndexTableStructureFactory
     */
    private $indexTableStructureFactory;

    /**
     * @var TableMaintainer
     */
    private $tableMaintainer;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var string
     */
    private $connectionName;

    /**
     * @var AdapterInterface
     */
    private $connection;

    /**
     * @var PriceModifierInterface[]
     */
    private $priceModifiers;

    /**
     * @var Config
     */
    private $eavConfig;

    /**
     * @var bool
     */
    private $fullReindexAction;

    /**
     * @param BaseFinalPrice $baseFinalPrice
     * @param IndexTableStructureFactory $indexTableStructureFactory
     * @param TableMaintainer $tableMaintainer
     * @param MetadataPool $metadataPool
     * @param Config $eavConfig
     * @param ResourceConnection $resource
     * @param string $connectionName
     * @param bool $fullReindexAction
     * @param array $priceModifiers
     */
    public function __construct(
        BaseFinalPrice $baseFinalPrice,
        IndexTableStructureFactory $indexTableStructureFactory,
        TableMaintainer $tableMaintainer,
        MetadataPool $metadataPool,
        Config $eavConfig,
        ResourceConnection $resource,
        $connectionName = 'indexer',
        $fullReindexAction = false,
        array $priceModifiers = []
    ) {
        $this->baseFinalPrice = $baseFinalPrice;
        $this->indexTableStructureFactory = $indexTableStructureFactory;
        $this->tableMaintainer = $tableMaintainer;
        $this->connectionName = $connectionName;
        $this->priceModifiers = $priceModifiers;
        $this->metadataPool = $metadataPool;
        $this->resource = $resource;
        $this->eavConfig = $eavConfig;
        $this->fullReindexAction = $fullReindexAction;
        $this->connection = $this->resource->getConnection($this->connectionName);
    }

    /**
     * Get main table
     *
     * @param array $dimensions
     * @return string
     */
    private function getMainTable($dimensions)
    {
        if ($this->fullReindexAction) {
            return $this->tableMaintainer->getMainReplicaTable($dimensions);
        }
        return $this->tableMaintainer->getMainTable($dimensions);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    public function executeByDimension(array $dimensions, \Traversable $entityIds = null)
    {
        /** @var IndexTableStructure $temporaryPriceTable */
        $temporaryPriceTable = $this->indexTableStructureFactory->create([
            'tableName' => $this->tableMaintainer->getMainTmpTable($dimensions),
            'entityField' => 'entity_id',
            'customerGroupField' => 'customer_group_id',
            'websiteField' => 'website_id',
            'taxClassField' => 'tax_class_id',
            'originalPriceField' => 'price',
            'finalPriceField' => 'final_price',
            'minPriceField' => 'min_price',
            'maxPriceField' => 'max_price',
            'tierPriceField' => 'tier_price',
        ]);
        $this->fillFinalPrice($dimensions, $entityIds, $temporaryPriceTable);
        $this->applyPriceModifiers($temporaryPriceTable);

//        if (!$this->tableStrategy->getUseIdxTable()) {
//            $additionalIdxTable = $this->cteateTempTable($temporaryPriceTable);
//            $this->fillTemporaryTable($entityIds, $additionalIdxTable);
//            $this->updateIdxTable($additionalIdxTable, $temporaryPriceTable->getTableName());
//            $this->connection->dropTemporaryTable($additionalIdxTable);
//        } else {
            $query = $this->_prepareGroupedProductPriceDataSelect($dimensions, iterator_to_array($entityIds))
                ->insertFromSelect($temporaryPriceTable->getTableName());
            $this->connection->query($query);
//        }
    }

    /**
     * Apply price modifiers to temporary price index table
     *
     * @param IndexTableStructure $temporaryPriceTable
     * @return void
     */
    private function applyPriceModifiers(IndexTableStructure $temporaryPriceTable)
    {
        foreach ($this->priceModifiers as $priceModifier) {
            $priceModifier->modifyPrice($temporaryPriceTable);
        }
    }

    /**
     * Prepare data index select for Grouped products prices
     * @param $dimensions
     * @param int|array $entityIds the parent entity ids limitation
     * @return \Magento\Framework\DB\Select
     */
    protected function _prepareGroupedProductPriceDataSelect($dimensions, $entityIds = null)
    {
        $select = $this->connection->select();

        $select->from(
            ['e' => $this->getTable('catalog_product_entity')],
            'entity_id'
        );

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $select->joinLeft(
            ['l' => $this->getTable('catalog_product_link')],
            'e.' . $linkField . ' = l.product_id AND l.link_type_id=' . Link::LINK_TYPE_GROUPED,
            []
        );
        //aditional infromation about inner products
        $select->joinLeft(
            ['le' => $this->getTable('catalog_product_entity')],
            'le.entity_id = l.linked_product_id',
            []
        );
        $select->columns(
            [
                'i.customer_group_id',
                'i.website_id',
            ]
        );
        $taxClassId = $this->connection->getCheckSql('MIN(i.tax_class_id) IS NULL', '0', 'MIN(i.tax_class_id)');
        $minCheckSql = $this->connection->getCheckSql('le.required_options = 0', 'i.min_price', 0);
        $maxCheckSql = $this->connection->getCheckSql('le.required_options = 0', 'i.max_price', 0);
        $select->joinLeft(
            ['i' => $this->getMainTable($dimensions)],
            'i.entity_id = l.linked_product_id',
            [
                'tax_class_id' => $taxClassId,
                'price' => new \Zend_Db_Expr('NULL'),
                'final_price' => new \Zend_Db_Expr('NULL'),
                'min_price' => new \Zend_Db_Expr('MIN(' . $minCheckSql . ')'),
                'max_price' => new \Zend_Db_Expr('MAX(' . $maxCheckSql . ')'),
                'tier_price' => new \Zend_Db_Expr('NULL'),
            ]
        );
        $select->group(
            ['e.entity_id', 'i.customer_group_id', 'i.website_id']
        );
        $select->where(
            'e.type_id=?',
            GroupedType::TYPE_CODE
        );

        if ($entityIds !== null) {
            $select->where('e.entity_id IN(?)', $entityIds);
        }

        foreach ($dimensions as $dimension) {
            if ($dimension->getName() === WebsiteDimensionProvider::DIMENSION_NAME) {
                $select->where('`i`.website_id = ?', $dimension->getValue());
            }
            if ($dimension->getName() === CustomerGroupDimensionProvider::DIMENSION_NAME) {
                $select->where('`i`.customer_group_id = ?', $dimension->getValue());
            }
        }

        return $select;
    }

    /**
     * Add website data join to select
     * If add default store join also limitation of only has default store website
     * Joined table has aliases
     *  cw for website table,
     *  csg for store group table (joined by website default group)
     *  cs for store table (joined by website default store)
     *
     * @param \Magento\Framework\DB\Select $select the select object
     * @param bool $store add default store join
     * @param string|\Zend_Db_Expr $joinCondition the limitation for website_id
     * @return $this
     */
    protected function _addWebsiteJoinToSelect($select, $store = true, $joinCondition = null)
    {
        if ($joinCondition !== null) {
            $joinCondition = 'cw.website_id = ' . $joinCondition;
        }

        $select->join(['cw' => $this->getTable('store_website')], $joinCondition, []);

        if ($store) {
            $select->join(
                ['csg' => $this->getTable('store_group')],
                'csg.group_id = cw.default_group_id',
                []
            )->join(
                ['cs' => $this->getTable('store')],
                'cs.store_id = csg.default_store_id',
                []
            );
        }

        return $this;
    }

    private function getTable($tableName)
    {
        return $this->resource->getTableName($tableName, $this->connectionName);
    }

    /**
     * @param array $dimensions
     * @param \Traversable $entityIds
     * @param $temporaryPriceTable
     */
    private function fillFinalPrice(array $dimensions, \Traversable $entityIds, $temporaryPriceTable): void
    {
        $select = $this->baseFinalPrice->getQuery(
            $dimensions,
            GroupedType::TYPE_CODE,
            iterator_to_array($entityIds)
        );
        $query = $select->insertFromSelect($temporaryPriceTable->getTableName(), [], false);
        $this->tableMaintainer->getConnection()->query($query);
    }
     
    /**
     * @param $table
     * @return mixed
     */
    private function cteateTempTable($table)
    {

        $temporaryOptionsTableName = 'catalog_product_index_price_cfg_opt_temp';
        $this->connection->createTemporaryTableLike(
            $temporaryOptionsTableName,
            $this->getTable('catalog_product_index_price_cfg_opt_tmp'),
            true
        );
        $additionalIdxTable = $this->connection->getTableName(self::TRANSIT_PREFIX . $this->getIdxTable());
        $this->connection->createTemporaryTableLike($additionalIdxTable, $table);
        return $additionalIdxTable;
    }

    /**
     * @param $entityIds
     * @param $additionalIdxTable
     */
    private function fillTemporaryTable($entityIds, $additionalIdxTable)
    {
        $query = $this->connection->insertFromSelect(
            $this->_prepareGroupedProductPriceDataSelect($entityIds),
            $additionalIdxTable,
            []
        );
        $this->connection->query($query);
    }

    /**
     * @param $additionalIdxTable
     * @param $table
     */
    private function updateIdxTable($additionalIdxTable, $table): void
    {
        $select = $this->connection->select()->from($additionalIdxTable);
        $query = $this->connection->insertFromSelect(
            $select,
            $table,
            [],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $this->connection->query($query);
    }
}
