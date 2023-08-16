<?php
/**
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright 2023 Payrexx
 * @license   MIT License
 */
use Payrexx\Models\Response\Transaction;
use Payrexx\PayrexxPaymentGateway\Config\PayrexxConfig;

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
        } catch(Exception $e) {
            echo 'Webhook Error: ' . $e->getMessage();
        }
        exit(); // Avoid template load error.
    }

    /**
     * Process webhook values
     */
    private function processWebhook() {
        $payrexxOrderService = $this->get('payrexx.payrexxpaymentgateway.payrexxorderservice');
        $payrexxDbService = $this->get('payrexx.payrexxpaymentgateway.payrexxdbservice');

        $transaction = Tools::getValue('transaction');
        $cartId = $transaction['invoice']['referenceId'];
        $requestStatus = $transaction['status'];
        $order = Order::getByCartId($cartId);

        if (!$this->validRequest($transaction, $cartId, $requestStatus)) {
            return;
        }

        if (!$prestaStatus = $payrexxOrderService->getPrestaStatusByPayrexxStatus($requestStatus)) {
            return;
        }

        $pm = $payrexxDbService->getPaymentMethodByCartId($cartId);
        $paymentMethod = PayrexxConfig::getPaymentMethodNameByPm($pm);

        // Create order if transaction successful
        if (!$order && in_array($requestStatus, [Transaction::CONFIRMED, Transaction::WAITING])) {
            $payrexxOrderService->createOrder(
                $cartId,
                $prestaStatus,
                $transaction['amount'],
                $paymentMethod,
                [
                    'transaction_id' => $transaction['id'],
                ]
            );
            return;
        }

        if ($order->module !== $this->module->name) {
            return;
        }

        // Update status if transition allowed
        if ($order && $payrexxOrderService->transitionAllowed($prestaStatus, $order->current_state)) {
            $payrexxOrderService->updateOrderStatus($prestaStatus, $order);
        }
        return;
    }

    private function validRequest($transaction, $cartId, $requestStatus): bool
    {
        // check required data
        if (!$cartId || !$requestStatus || !$transaction['id']) {
            return false;
        }

        $payrexxApiService = $this->get('payrexx.payrexxpaymentgateway.payrexxapiservice');
        $gateway = $payrexxApiService->getPayrexxGateway((int) $transaction['invoice']['paymentRequestId']);

        // Validate request by gateway ID
        if (!$gateway) {
            PrestaShopLoggerCore::addLog('GATEWAY FOR CART ID: ' . $cartId . ' NOT FOUND');
        }

        $transactionObj = $payrexxApiService->getPayrexxTransaction($transaction['id']);

        $payrexxAmount = $transactionObj->getAmount();

        if (empty($payrexxAmount) || $payrexxAmount !== (int) $transaction['amount']) {
            return false;
        }

        $payrexxStatus = $transactionObj->getStatus();
        if (empty($payrexxStatus) || $payrexxStatus !== $requestStatus) {
            return false;
        }

        return true;
    }
}
