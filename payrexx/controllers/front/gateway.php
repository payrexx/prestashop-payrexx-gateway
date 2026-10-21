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
use Payrexx\Models\Response\Gateway;
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

        // Webhook is unsigned: the cart comes from the fetched transaction and must match the cart's gateway
        $cartId = $this->getCartIdByTransaction($transaction);
        $gateway = $payrexxApiService->getPayrexxGateway((int) $payrexxDbService->getCartGatewayId($cartId));
        if (!$cartId || !$gateway || !$this->isTransactionOfGateway($gateway, $transaction)) {
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

        // The cart can change after its gateway was created and a bank transfer can be paid in parts,
        // so only the sum of all confirmed transactions settles the order
        if (
            $transactionStatus === Transaction::CONFIRMED
            && (int) round($order->total_paid * 100) !== $this->getConfirmedAmount($gateway)
        ) {
            PrestaShopLoggerCore::addLog(
                'CONFIRMED AMOUNT OF GATEWAY ' . $gateway->getId() . ' DOES NOT MATCH ORDER ID: ' . $order->id
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
     * The transaction response carries no gateway id, so the gateway is asked for its transactions instead.
     *
     * @param Gateway $gateway
     * @param Transaction $transaction
     * @return bool
     */
    private function isTransactionOfGateway(Gateway $gateway, Transaction $transaction): bool
    {
        foreach ($gateway->getInvoices() ?? [] as $invoice) {
            foreach ($invoice['transactions'] ?? [] as $gatewayTransaction) {
                if ((int) ($gatewayTransaction['id'] ?? 0) === (int) $transaction->getId()) {
                    return true;
                }
            }
        }

        return false;
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
        $cartId = (int) $cartId;

        return $cartId;
    }
}
