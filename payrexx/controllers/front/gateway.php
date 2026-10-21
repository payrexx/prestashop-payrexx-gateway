<?php
/**
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright 2024 Payrexx
 * @license   MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
use Payrexx\Models\Response\Transaction;
use Payrexx\PayrexxPaymentGateway\Config\PayrexxConfig;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxApiService;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxDbService;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxOrderService;

class PayrexxGatewayModuleFrontController extends ModuleFrontController
{
    /**
     * Process post values.
     */
    public function postProcess()
    {
        try {
            $this->processWebhook();
            echo 'Webhook processed successfully';
        } catch (Exception $e) {
            echo 'Webhook Error: ' . $e->getMessage();
        }
        exit; // Avoid template load error.
    }

    /**
     * Process webhook values
     */
    private function processWebhook()
    {
        if (version_compare(_PS_VERSION_, '1.7.6', '<')) {
            $payrexxOrderService = new PayrexxOrderService();
            $payrexxDbService = new PayrexxDbService();
            $payrexxApiService = new PayrexxApiService();
        } else {
            $payrexxOrderService = $this->get('payrexx.payrexxpaymentgateway.payrexxorderservice');
            $payrexxDbService = $this->get('payrexx.payrexxpaymentgateway.payrexxdbservice');
            $payrexxApiService = $this->get('payrexx.payrexxpaymentgateway.payrexxapiservice');
        }

        $transaction = $this->getVerifiedTransaction(Tools::getValue('transaction'), $payrexxApiService);
        if (!$transaction) {
            return;
        }

        // The webhook is public and unsigned, so the cart is read from the transaction fetched with our own
        // credentials and must belong to the gateway this shop created for that cart. Taken from the request,
        // any real transaction could be bound to a foreign cart.
        $cartId = $this->getCartIdByTransaction($transaction);
        $invoice = $transaction->getInvoice();
        $gatewayId = (int) ($invoice['paymentRequestId'] ?? 0);
        if (!$cartId || !$gatewayId || (int) $payrexxDbService->getCartGatewayId($cartId) !== $gatewayId) {
            PrestaShopLoggerCore::addLog(
                'TRANSACTION ' . $transaction->getId() . ' DOES NOT BELONG TO A GATEWAY OF CART ID: ' . $cartId
            );
            return;
        }

        $transactionStatus = $transaction->getStatus();
        if (!$prestaStatus = $payrexxOrderService->getPrestaStatusByPayrexxStatus($transactionStatus)) {
            return;
        }

        $pm = $payrexxDbService->getPaymentMethodByCartId($cartId);
        $paymentMethod = PayrexxConfig::getPaymentMethodNameByPm($pm);
        $order = Order::getByCartId($cartId);

        // Create order if transaction successful
        if (!$order && in_array($transactionStatus, [Transaction::CONFIRMED, Transaction::WAITING])) {
            $payrexxOrderService->createOrder(
                $cartId,
                $prestaStatus,
                $transaction->getAmount(),
                $paymentMethod,
                [
                    'transaction_id' => $transaction->getId(),
                ]
            );
            return;
        }

        if (!$order || $order->module !== $this->module->name) {
            return;
        }

        // Record received money only - a refund arrives with a negative amount, which OrderPayment rejects.
        // Payrexx retries webhooks, so a transaction is recorded once only.
        if (
            $transactionStatus === Transaction::CONFIRMED
            && !$payrexxOrderService->hasOrderPayment($order, $transaction->getId())
        ) {
            $payrexxOrderService->createOrderPayment(
                $order,
                $transaction,
                $paymentMethod
            );
        }

        // The cart can still change after its gateway was created, so a payment only settles the order in full
        if (
            $transactionStatus === Transaction::CONFIRMED
            && (int) round($order->total_paid * 100) !== (int) $transaction->getAmount()
        ) {
            PrestaShopLoggerCore::addLog(
                'TRANSACTION ' . $transaction->getId() . ' AMOUNT DOES NOT MATCH ORDER ID: ' . $order->id
            );
            return;
        }

        // Update status if transition allowed
        if ($payrexxOrderService->transitionAllowed($prestaStatus, $order->current_state)) {
            $payrexxOrderService->updateOrderStatus($prestaStatus, $order);
        }
    }

    /**
     * Fetch the posted transaction from Payrexx and make sure the posted status is its current one.
     *
     * @param mixed $requestTransaction
     * @param PayrexxApiService $payrexxApiService
     * @return Transaction|null
     */
    private function getVerifiedTransaction($requestTransaction, $payrexxApiService): ?Transaction
    {
        if (empty($requestTransaction['id']) || empty($requestTransaction['status'])) {
            return null;
        }

        $transaction = $payrexxApiService->getPayrexxTransaction((int) $requestTransaction['id']);
        if (!$transaction || empty($transaction->getStatus())) {
            return null;
        }

        if ($transaction->getStatus() !== $requestTransaction['status']) {
            return null;
        }

        return $transaction;
    }

    /**
     * The gateway sets the cart id as referenceId. The API returns it at the top level and in the invoice,
     * depending on the transaction type.
     *
     * @param Transaction $transaction
     * @return int
     */
    private function getCartIdByTransaction(Transaction $transaction): int
    {
        $cartId = $transaction->getReferenceId();
        if (empty($cartId)) {
            $invoice = $transaction->getInvoice();
            $cartId = $invoice['referenceId'] ?? 0;
        }

        return (int) $cartId;
    }
}
