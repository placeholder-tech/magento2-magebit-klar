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

use ICT\Klar\Api\Data\DiscountInterface;
use ICT\Klar\Api\Data\DiscountInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Sales\Api\Data\OrderItemInterface as SalesOrderItemInterface;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\RuleFactory;

class LineItemDiscountsBuilder extends \ICT\Klar\Model\Builders\LineItemDiscountsBuilder
{
    private DiscountInterfaceFactory $discountFactory;
    private RuleRepositoryInterface $salesRuleRepository;
    private RuleFactory $ruleFactory;

    /**
     * LineItemDiscountsBuilder constructor.
     *
     * @param DateTimeFactory $dateTimeFactory
     * @param DiscountInterfaceFactory $discountFactory
     * @param RuleRepositoryInterface $salesRuleRepository
     * @param RuleFactory $ruleFactory
     */
    public function __construct(
        DateTimeFactory $dateTimeFactory,
        DiscountInterfaceFactory $discountFactory,
        RuleRepositoryInterface $salesRuleRepository,
        RuleFactory $ruleFactory
    ) {
        parent::__construct($dateTimeFactory, $discountFactory, $salesRuleRepository, $ruleFactory);
        $this->discountFactory = $discountFactory;
        $this->salesRuleRepository = $salesRuleRepository;
        $this->ruleFactory = $ruleFactory;
    }

    /**
     * Build line item discounts array from sales order item.
     *
     * @param SalesOrderItemInterface $salesOrderItem
     *
     * @return array
     */
    public function buildFromSalesOrderItem(SalesOrderItemInterface $salesOrderItem): array
    {
        $discounts = [];

        $discountAmountTotal = (float)$salesOrderItem->getDiscountAmount();
        $quantity = (int)$salesOrderItem->getQtyOrdered();
        $discountAmount = $discountAmountTotal / $quantity;

        if ((float)$salesOrderItem->getPriceInclTax() == 0.0) {
            $discountAmount = (float)$salesOrderItem->getOriginalPrice() * $quantity;
        }

        if ($discountAmount && $salesOrderItem->getAppliedRuleIds()) {
            $ruleIds = explode(',', $salesOrderItem->getAppliedRuleIds());

            foreach ($ruleIds as $ruleId) {
                $discount = $this->buildRuleDiscount(
                    (int)$ruleId,
                    (float)$salesOrderItem->getPriceInclTax(),
                    $quantity
                );

                if (!empty($discount)) {
                    $discounts[] = $discount;

                    if ((float)$discount['discountAmount'] > 0) {
                        $discountAmount -= (float)$discount['discountAmount'];
                    }
                }
            }
        }

        if ($discountAmount > 0.02) { // Just to be safe regarding any rounding issues
            $discounts[] = $this->buildSpecialPriceDiscount($discountAmount);
        }

        return $discounts;
    }

    /**
     * Build discount array from sales rule.
     *
     * @param int $ruleId
     * @param float $baseItemPrice
     * @param int $quantity
     *
     * @return array
     */
    private function buildRuleDiscount(int $ruleId, float $baseItemPrice, int $quantity): array
    {
        try {
            $salesRule = $this->salesRuleRepository->getById($ruleId);
        } catch (NoSuchEntityException|LocalizedException $e) {
            // Rule doesn't exist, manual calculation is not possible.
            return [];
        }

        if (!(float)$salesRule->getDiscountAmount()) {
            return [];
        }

        if ($salesRule->getCouponType() != RuleInterface::COUPON_TYPE_SPECIFIC_COUPON) {
            return [];
        }

        $discount = $this->discountFactory->create();

        try {
            $couponCode = $this->ruleFactory->create()->load($ruleId)->getCouponCode();
        } catch (\Exception $exception) {
            return [];
        }

        $discount->setTitle($salesRule->getName());
        $discount->setDescriptor($salesRule->getDescription());

        $discount->setIsVoucher(true);
        $discount->setVoucherCode($couponCode);

        if ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_BY_PERCENT) {
            $discountPercent = $salesRule->getDiscountAmount() / 100;
            $discount->setDiscountAmount($baseItemPrice * $discountPercent);
        } elseif ($salesRule->getSimpleAction() === RuleInterface::DISCOUNT_ACTION_FIXED_AMOUNT) {
            $discount->setDiscountAmount((float)$salesRule->getDiscountAmount());
        } else {
            return []; // Disallow other action types
        }

        if ((float)$discount->getDiscountAmount() > 0) {
            $discount->setDiscountAmount((float)$discount->getDiscountAmount() / $quantity);
        }

        return $this->snakeToCamel($discount->toArray());
    }

    /**
     * Build discount array for remaining discount (special price).
     *
     * @param float $discountAmount
     * @return array
     */
    private function buildSpecialPriceDiscount(float $discountAmount): array
    {
        $discount = $this->discountFactory->create();

        $discount->setTitle(DiscountInterface::SPECIAL_PRICE_DISCOUNT_TITLE);
        $discount->setDescriptor(DiscountInterface::SPECIAL_PRICE_DISCOUNT_DESCRIPTOR);
        $discount->setDiscountAmount($discountAmount);

        return $this->snakeToCamel($discount->toArray());
    }
}
