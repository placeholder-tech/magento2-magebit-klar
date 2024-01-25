<?php

/**
 * @copyright Copyright (c) 2023 Magebit (https://magebit.com/)
 * @author    <info@magebit.com>
 * @license   GNU General Public License ("GPL") v3.0
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Magebit\Klar\Plugin;

use ICT\Klar\Observer\SendOrderToKlar;
use Magento\Framework\Event\Observer;
use Magento\OfflinePayments\Model\Banktransfer;
use Magento\Sales\Model\Order;

class ValidateKlarOrder
{
    /**
     * Validates order status to prevent double send with certain payment methods.
     *
     * @param SendOrderToKlar $subject
     * @param callable $proceed
     * @param Observer $observer
     * @return void
     */
    public function aroundExecute(SendOrderToKlar $subject, callable $proceed, Observer $observer): void
    {
        if ($order = $observer->getEvent()->getOrder()) {
            // Only attempt to send to Klar if the order is paid for or bank transfer.
            if ($order->getState() === Order::STATE_PROCESSING
                || $order->getState() === Order::STATE_COMPLETE
                || $order->getPayment()->getMethod() === Banktransfer::PAYMENT_METHOD_BANKTRANSFER_CODE
            ) {
                $proceed($observer);
            }
        }
    }
}
