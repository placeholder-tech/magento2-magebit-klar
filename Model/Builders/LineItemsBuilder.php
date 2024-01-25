<?php

/**
 * @copyright Copyright (c) 2023 Magebit (https://magebit.com/)
 * @author    <nils@magebit.com>
 * @license   GNU General Public License ("GPL") v3.0
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Magebit\Klar\Model\Builders;

use ICT\Klar\Api\Data\LineItemInterfaceFactory;
use ICT\Klar\Helper\Config;
use ICT\Klar\Model\Builders\LineItemDiscountsBuilder;
use ICT\Klar\Model\Builders\TaxesBuilder;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderInterface as SalesOrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;

class LineItemsBuilder extends \ICT\Klar\Model\Builders\LineItemsBuilder
{
    private LineItemInterfaceFactory $lineItemFactory;
    private CategoryRepositoryInterface $categoryRepository;
    private TaxesBuilder $taxesBuilder;
    private Config $config;
    private LineItemDiscountsBuilder $discountsBuilder;

    /**
     * @param DateTimeFactory $dateTimeFactory
     * @param LineItemInterfaceFactory $lineItemFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param TaxesBuilder $taxesBuilder
     * @param Config $config
     * @param LineItemDiscountsBuilder $discountsBuilder
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        LineItemInterfaceFactory $lineItemFactory,
        CategoryRepositoryInterface $categoryRepository,
        TaxesBuilder $taxesBuilder,
        Config $config,
        LineItemDiscountsBuilder $discountsBuilder
    ) {
        parent::__construct(
            $dateTimeFactory,
            $lineItemFactory,
            $categoryRepository,
            $taxesBuilder,
            $config,
            $discountsBuilder
        );

        $this->lineItemFactory = $lineItemFactory;
        $this->categoryRepository = $categoryRepository;
        $this->taxesBuilder = $taxesBuilder;
        $this->config = $config;
        $this->discountsBuilder = $discountsBuilder;
    }

    /**
     * Build line items array from sales order.
     *
     * @param SalesOrderInterface $salesOrder
     *
     * @return array
     */
    public function buildFromSalesOrder(SalesOrderInterface $salesOrder): array
    {
        $lineItems = [];

        foreach ($salesOrder->getItems() as $salesOrderItem) {
            $product = $salesOrderItem->getProduct();
            $productVariant = $this->getProductVariant($salesOrderItem);
            $categoryName = $this->getCategoryName($salesOrderItem);

            $discountAmount = (float)$salesOrderItem->getDiscountAmount();
            $taxAmount = (float)$salesOrderItem->getTaxAmount();
            $priceInclTax = (float)$salesOrderItem->getOriginalPrice();
            $quantity = (int)$salesOrderItem->getQtyOrdered();

            if ((float)$salesOrderItem->getPriceInclTax() == 0.0) {
                $discountAmount = $priceInclTax * $quantity;
            }

            $totalBeforeTaxesAndDiscounts = $priceInclTax * $quantity;
            $totalAfterTaxesAndDiscounts = $totalBeforeTaxesAndDiscounts - $taxAmount - $discountAmount;

            $weightInGrams = 0;
            if ($product) {
                $weightInGrams = $this->getWeightInGrams($product);
            }

            $lineItem = $this->lineItemFactory->create();
            $lineItem->setId((string)$salesOrderItem->getItemId());
            $lineItem->setProductName($salesOrderItem->getName());
            $lineItem->setProductId((string)$salesOrderItem->getProductId());

            if ($productVariant) {
                $lineItem->setProductVariantName($productVariant['name']);
                $lineItem->setProductVariantId((string)$productVariant['id']);
            }

            if ($categoryName) {
                $lineItem->setProductCollection($categoryName);
            }

            $lineItem->setProductCogs((float)$salesOrderItem->getBaseCost());
            $lineItem->setProductGmv($priceInclTax);
            $lineItem->setProductShippingWeightInGrams($weightInGrams);
            $lineItem->setSku($salesOrderItem->getSku());
            $lineItem->setQuantity($quantity);
            $lineItem->setDiscounts($this->discountsBuilder->buildFromSalesOrderItem($salesOrderItem));
            $lineItem->setTaxes(
                $this->taxesBuilder->build((int)$salesOrderItem->getOrderId(), $salesOrderItem)
            );
            $lineItem->setTotalAmountBeforeTaxesAndDiscounts($totalBeforeTaxesAndDiscounts);
            $lineItem->setTotalAmountAfterTaxesAndDiscounts($totalAfterTaxesAndDiscounts);

            $lineItems[] = $this->snakeToCamel($lineItem->toArray());
        }

        return $lineItems;
    }

    /**
     * Get product variant name and ID.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return array|null
     */
    private function getProductVariant(SalesOrderItemInterface $salesOrderItem): ?array
    {
        $productOptions = $salesOrderItem->getProductOptions();

        if (isset($productOptions['simple_name'], $productOptions['simple_sku'])) {
            return [
                'name' => $productOptions['simple_name'],
                'id' => $productOptions['simple_sku'],
            ];
        }

        return null;
    }

    /**
     * Get the highest level category name.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return string|null
     */
    private function getCategoryName(SalesOrderItemInterface $salesOrderItem): ?string
    {
        $product = $salesOrderItem->getProduct();

        if (!$product) {
            return null;
        }

        $categoryIds = $product->getCategoryIds();
        $categoryNames = [];

        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            $categoryLevel = $category->getLevel();
            $categoryName = $category->getName();
            $categoryNames[$categoryLevel] = $categoryName;
        }

        if (!empty($categoryNames)) {
            krsort($categoryNames);

            return reset($categoryNames);
        }

        return null;
    }

    /**
     * Get product weight in grams.
     *
     * @param Product $product
     *
     * @return float
     */
    private function getWeightInGrams(Product $product): float
    {
        $productWeightInKgs = 0.00;
        $weightUnit = $this->config->getWeightUnit();
        $productWeight = (float)$product->getWeight();

        if ($productWeight) {
            // Convert LBS to KGS if unit is LBS
            if ($weightUnit === Config::WEIGHT_UNIT_LBS) {
                $productWeightInKgs = $this->convertLbsToKgs($productWeight);
            }

            return $productWeightInKgs * 1000;
        }

        return $productWeightInKgs;
    }

    /**
     * Convert lbs to kgs.
     *
     * @param float $weightLbs
     *
     * @return float
     */
    private function convertLbsToKgs(float $weightLbs): float
    {
        $conversionFactor = 0.45359237;
        $weightInKgs = $weightLbs * $conversionFactor;

        return round($weightInKgs, 3);
    }
}
