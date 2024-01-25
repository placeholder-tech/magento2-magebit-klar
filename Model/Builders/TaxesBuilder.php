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

use ICT\Klar\Api\Data\TaxInterfaceFactory;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\ResourceModel\Order\Tax\Item as TaxItemResource;

class TaxesBuilder extends \ICT\Klar\Model\Builders\TaxesBuilder
{
    public const TAXABLE_ITEM_TYPE_PRODUCT = 'product';
    public const TAXABLE_ITEM_TYPE_SHIPPING = 'shipping';

    private TaxItemResource $taxItemResource;
    private TaxInterfaceFactory $taxFactory;

    /**
     * TaxesBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param TaxItemResource $taxItemResource
     * @param TaxInterfaceFactory $taxFactory
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        TaxItemResource $taxItemResource,
        TaxInterfaceFactory $taxFactory
    ) {
        parent::__construct($dateTimeFactory, $taxItemResource, $taxFactory);
        $this->taxItemResource = $taxItemResource;
        $this->taxFactory = $taxFactory;
    }

    /**
     * Get taxes from sales order by type.
     *
     * @param int $salesOrderId
     * @param OrderItemInterface|null $salesOrderItem
     * @param string $taxableItemType
     *
     * @return array
     */
    public function build(
        int $salesOrderId,
        OrderItemInterface $salesOrderItem = null,
        string $taxableItemType = self::TAXABLE_ITEM_TYPE_PRODUCT
    ): array {
        $taxes = [];
        $taxItems = $this->taxItemResource->getTaxItemsByOrderId($salesOrderId);

        foreach ($taxItems as $taxItem) {
            $taxRate = (float)($taxItem['tax_percent'] / 100);

            if ($taxItem['taxable_item_type'] === self::TAXABLE_ITEM_TYPE_PRODUCT &&
                $salesOrderItem !== null) {
                $salesOrderItemId = (int)$salesOrderItem->getId();

                if ((int)$taxItem['item_id'] !== $salesOrderItemId) {
                    continue;
                }

                $qty = $salesOrderItem->getQtyOrdered() ? $salesOrderItem->getQtyOrdered() : 1;
                $itemPrice = (float)$salesOrderItem->getOriginalPrice() - ((float)$salesOrderItem->getDiscountAmount() / $qty);
                $taxAmount = $itemPrice - ($itemPrice / (1+ $taxRate));
            } else {
                $taxAmount = (float)$taxItem['real_amount'];
            }

            if ($taxItem['taxable_item_type'] === $taxableItemType) {
                $tax = $this->taxFactory->create();

                $tax->setTitle($taxItem['title']);
                $tax->setTaxRate($taxRate);
                $tax->setTaxAmount($taxAmount);

                $taxes[$taxableItemType][] = $this->snakeToCamel($tax->toArray());
            }
        }

        if (!empty($taxes)) {
            return $taxes[$taxableItemType];
        }

        return $taxes;
    }
}
