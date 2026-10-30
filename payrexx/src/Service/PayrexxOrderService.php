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

use Payrexx\Models\Response\Gateway;
use Payrexx\Models\Response\Transaction;

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

    // ID 4
    const PS_STATUS_SHIPPING = 'PS_OS_SHIPPING';

    // ID 5
    const PS_STATUS_DELIVERED = 'PS_OS_DELIVERED';

    // ID 14
    const PS_CHECKOUT_STATE_PENDING = 'PS_CHECKOUT_STATE_PENDING';

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
        array $extraVars = []
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
     * Webhook and return page both settle the cart, so they are serialized per cart to create one order only.
     *
     * @param int $cartId
     * @param Gateway $gateway
     * @param Transaction $transaction
     * @param string $paymentMethod
     * @return void
     * @throws \PrestaShopException
     */
    public function processTransaction($cartId, Gateway $gateway, Transaction $transaction, $paymentMethod)
    {
        $lockName = '\'payrexx_cart_' . (int) $cartId . '\'';
        $db = \Db::getInstance();
        if (!(int) $db->getValue('SELECT GET_LOCK(' . $lockName . ', 10)', false)) {
            throw new \PrestaShopException('Could not lock cart ' . (int) $cartId);
        }

        try {
            $this->settleTransaction((int) $cartId, $gateway, $transaction, $paymentMethod);
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(' . $lockName . ')', false);
        }
    }

    /**
     * @param int $cartId
     * @param Gateway $gateway
     * @param Transaction $transaction
     * @param string $paymentMethod
     * @return void
     */
    private function settleTransaction(int $cartId, Gateway $gateway, Transaction $transaction, $paymentMethod)
    {
        $transactionStatus = $transaction->getStatus();
        if (!$prestaStatus = $this->getPrestaStatusByPayrexxStatus($transactionStatus)) {
            return;
        }

        // An order loaded before the lock stays cached for the request and would hide the other request's changes
        \Cache::clean('objectmodel_Order_*');
        $order = \Order::getByCartId($cartId);

        // Create order if transaction successful
        if (!$order && in_array($transactionStatus, [Transaction::CONFIRMED, Transaction::WAITING])) {
            $paidAmount = (int) $transaction->getAmount();
            $orderAmount = $paidAmount;

            // PrestaShop sets the order to payment error if the paid amount differs from the cart total,
            // so a covered cart is ordered with its total and the real amount is recorded afterwards
            $cartAmount = (int) round((new \Cart($cartId))->getOrderTotal(true, \Cart::BOTH) * 100);
            if (
                $transactionStatus === Transaction::CONFIRMED
                && $this->getConfirmedAmount($gateway) >= $cartAmount
            ) {
                $orderAmount = $cartAmount;
            }

            $this->createOrder(
                $cartId,
                $prestaStatus,
                $orderAmount,
                $paymentMethod,
                [
                    'transaction_id' => $transaction->getId(),
                ]
            );
            if ($orderAmount !== $paidAmount) {
                $this->updateOrderPaymentAmount(\Order::getByCartId($cartId), $transaction);
            }
            return;
        }

        if (!$order || $order->module !== 'payrexx') {
            return;
        }

        // Record received money only - a refund arrives with a negative amount, which OrderPayment rejects.
        // Payrexx retries webhooks, so a transaction is recorded once only.
        if (
            $transactionStatus === Transaction::CONFIRMED
            && !$this->hasOrderPayment($order, $transaction->getId())
        ) {
            $this->createOrderPayment(
                $order,
                $transaction,
                $paymentMethod
            );
        }

        // The cart can change after its gateway was created and a bank transfer can be paid in parts,
        // so only the sum of all confirmed transactions settles the order.
        // An overpayment still settles it, otherwise the order would stay open since the webhook is not resent.
        if (
            $transactionStatus === Transaction::CONFIRMED
            && $this->getConfirmedAmount($gateway) < (int) round($order->total_paid * 100)
        ) {
            \PrestaShopLoggerCore::addLog(
                'CONFIRMED AMOUNT OF GATEWAY ' . $gateway->getId() . ' IS LOWER THAN THE TOTAL OF ORDER ID: '
                . $order->id
            );
            return;
        }

        // Update status if transition allowed
        if ($this->transitionAllowed($prestaStatus, $order->current_state)) {
            $this->updateOrderStatus($prestaStatus, $order);
        }
    }

    /**
     * @param Gateway $gateway
     * @return int
     */
    private function getConfirmedAmount(Gateway $gateway): int
    {
        $amount = 0;
        foreach ($gateway->getInvoices() ?? [] as $invoice) {
            foreach ($invoice['transactions'] ?? [] as $gatewayTransaction) {
                if (($gatewayTransaction['status'] ?? '') === Transaction::CONFIRMED) {
                    $amount += (int) ($gatewayTransaction['amount'] ?? 0);
                }
            }
        }

        return $amount;
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
            case \Payrexx\Models\Response\Transaction::PARTIALLY_REFUNDED:
                $prestaStatus = self::PS_STATUS_REFUND;
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
        $refundStatusId = (int) \Configuration::get(self::PS_STATUS_REFUND);
        $newStatusId = (int) \Configuration::get($newStatus);
        if ($oldStatusId === $newStatusId && $newStatusId !== $refundStatusId) {
            return false;
        }
        $orderFinalStatuses = [
            (int) \Configuration::get(self::PS_STATUS_REFUND),
            (int) \Configuration::get(self::PS_STATUS_PAYMENT),
            (int) \Configuration::get(self::PS_STATUS_SHIPPING),
            (int) \Configuration::get(self::PS_STATUS_DELIVERED),
        ];

        switch ($newStatus) {
            case self::PS_STATUS_ERROR:
            case self::PS_STATUS_PAYMENT:
            case self::PS_STATUS_BANKWIRE:
                return !in_array($oldStatusId, $orderFinalStatuses);
            case self::PS_STATUS_REFUND:
                return in_array(
                    $oldStatusId,
                    [
                        (int) \Configuration::get(self::PS_STATUS_PAYMENT),
                        (int) \Configuration::get(self::PS_STATUS_REFUND),
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

    /**
     * @param \Order $order
     * @param int $transactionId
     * @return bool
     */
    public function hasOrderPayment($order, $transactionId): bool
    {
        foreach (\OrderPayment::getByOrderReference($order->reference) as $payment) {
            if ((string) $payment->transaction_id === (string) $transactionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Order $order
     * @param \Payrexx\Models\Response\Transaction $transaction
     * @return void
     */
    public function updateOrderPaymentAmount($order, $transaction)
    {
        $amount = (float) $transaction->getAmount() / 100;
        foreach (\OrderPayment::getByOrderReference($order->reference) as $payment) {
            if ((string) $payment->transaction_id !== (string) $transaction->getId()) {
                continue;
            }

            $order->total_paid_real += $amount - (float) $payment->amount;
            $order->update();
            $payment->amount = $amount;
            $payment->update();
        }
    }

    /**
     * @param \Order $order
     * @param \Payrexx\Models\Response\Transaction $transaction
     * @param string $paymentMethod
     * @return void
     */
    public function createOrderPayment($order, $transaction, $paymentMethod)
    {
        $payment = new \OrderPayment();
        $payment->order_reference = $order->reference;
        $payment->id_currency = $order->id_currency;
        $payment->amount = (float) $transaction->getAmount() / 100;
        $payment->payment_method = $paymentMethod;
        $payment->transaction_id = $transaction->getId();
        $payment->conversion_rate = 1;
        $payment->add();
    }
}
