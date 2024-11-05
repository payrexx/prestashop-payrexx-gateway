<?php
/**
 * Payrexx Payment Gateway.
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright 2024 Payrexx
 * @license   MIT License
 */

namespace Payrexx\PayrexxPaymentGateway\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PayrexxOrderService
{
    // ID 8
    const PS_STATUS_ERROR = 'PS_OS_ERROR';

    // ID 7
    const PS_STATUS_REFUND = 'PS_OS_REFUND';

    // ID 2
    const PS_STATUS_PAYMENT = 'PS_OS_PAYMENT';

    // ID 10
    const PS_STATUS_BANKWIRE = 'PS_OS_BANKWIRE';

    const PS_STATUS_SHIPPING = 'PS_OS_SHIPPING';

    const PS_STATUS_DELIVERED = 'PS_OS_DELIVERED';

    const PS_CHECKOUT_STATE_PARTIALLY_REFUNDED = 'PS_CHECKOUT_STATE_PARTIALLY_REFUNDED';
    /**
     * @param $cartId
     * @param $prestaStatus
     * @param $amount
     * @param $paymentMethod
     * @param array $extraVars
     * @return void
     */
    public function createOrder(
        $cartId,
        $prestaStatus,
        $amount,
        $paymentMethod,
        array $extraVars = [],
    ) {
        $payrexxModule = \Module::getInstanceByName('payrexx');
        $cart = new \Cart($cartId);
        $customer = new \Customer($cart->id_customer);
        $statusId = (int) \Configuration::get($prestaStatus);

        $payrexxModule->validateOrder(
            (int) $cart->id,
            $statusId,
            (float) $amount / 100,
            $paymentMethod,
            null,
            $extraVars,
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );
        $context = \Context::getContext();
        $context->cart = $cart;
    }

    /**
     * @param $transactionStatus
     * @return string|null
     */
    public function getPrestaStatusByPayrexxStatus($transactionStatus)
    {
        $prestaStatus = null;
        switch ($transactionStatus) {
            case \Payrexx\Models\Response\Transaction::CANCELLED:
            case \Payrexx\Models\Response\Transaction::DECLINED:
            case \Payrexx\Models\Response\Transaction::ERROR:
            case \Payrexx\Models\Response\Transaction::EXPIRED:
                $prestaStatus = self::PS_STATUS_ERROR;
                break;
            case \Payrexx\Models\Response\Transaction::REFUNDED:
                $prestaStatus = self::PS_STATUS_REFUND;
                break;
            case \Payrexx\Models\Response\Transaction::PARTIALLY_REFUNDED:
                $prestaStatus = self::PS_CHECKOUT_STATE_PARTIALLY_REFUNDED;
                break;
            case \Payrexx\Models\Response\Transaction::CONFIRMED:
                $prestaStatus = self::PS_STATUS_PAYMENT;
                break;
            case \Payrexx\Models\Response\Transaction::WAITING:
                $prestaStatus = self::PS_STATUS_BANKWIRE;
                break;
        }

        return $prestaStatus;
    }

    /**
     * @param $newStatus
     * @param $oldStatusId
     * @return bool|void
     */
    public function transitionAllowed($newStatus, $oldStatusId)
    {
        $newStatusId = (int) \Configuration::get($newStatus);
        if ($oldStatusId === $newStatusId) {
            return false;
        }
        $orderFinalStatuses = [
            (int) \Configuration::get(self::PS_STATUS_REFUND),
            (int) \Configuration::get(self::PS_STATUS_PAYMENT),
            (int) \Configuration::get(self::PS_STATUS_SHIPPING),
            (int) \Configuration::get(self::PS_STATUS_DELIVERED),
            (int) \Configuration::get(self::PS_CHECKOUT_STATE_PARTIALLY_REFUNDED),
        ];

        switch ($newStatus) {
            case self::PS_STATUS_ERROR:
            case self::PS_STATUS_PAYMENT:
            case self::PS_STATUS_BANKWIRE:
                return !in_array($oldStatusId, $orderFinalStatuses);
            case self::PS_STATUS_REFUND:
            case sef::PS_CHECKOUT_STATE_PARTIALLY_REFUNDED:
                return in_array(
                    $oldStatusId,
                    [
                        (int) \Configuration::get(self::PS_STATUS_PAYMENT),
                    ]
                );
        }
        return false;
    }

    /**
     * @param $prestaStatus
     * @param $order
     * @return void
     */
    public function updateOrderStatus($prestaStatus, $order)
    {
        $orderHistory = new \OrderHistory();
        $prestaStatusId = \Configuration::get($prestaStatus);

        $orderHistory->id_order = (int) $order->id;
        $orderHistory->changeIdOrderState($prestaStatusId, $order, true);
        $orderHistory->addWithemail();
    }

    /**
     * Get Gateway id from the cart id
     *
     * @param int $id_cart cart id
     * @return int
     */
    public function getCartGatewayId($id_cart)
    {
        if (empty($id_cart)) {
            return 0;
        }

        return (int) \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT id_gateway FROM `' . _DB_PREFIX_ . 'payrexx_gateway`
            WHERE id_cart = ' . (int) $id_cart);
    }
}
