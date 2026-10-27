<?php
/**
 * Payrexx Validation Module FrontController
 *
 * @author    Payrexx <support@payrexx.com>
 * @copyright 2023 Payrexx
 * @license   MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
use Payrexx\PayrexxPaymentGateway\Service\PayrexxDbService;

class PayrexxValidationModuleFrontController extends ModuleFrontController
{
    const ERROR_CONFIG = 'config';
    const ERROR_CANCEL = 'cancel';
    const ERROR_FAIL = 'fail';

    public function __construct()
    {
        parent::__construct();
    }

    public function initContent()
    {
        if (Tools::getIsset('payrexxError')) {
            $this->handleError(Tools::getValue('payrexxError'));
            exit;
        }

        if (version_compare(_PS_VERSION_, '1.7.6', '<')) {
            $payrexxDbService = new PayrexxDbService();
        } else {
            $payrexxDbService = $this->get('payrexx.payrexxpaymentgateway.payrexxdbservice');
        }
        $gatewayId = $this->context->cookie->paymentId;
        $cartId = $payrexxDbService->getGatewayCartId($gatewayId);

        // Redirect to success page if successful order already exists
        $order = Order::getByCartId($cartId);
        if ($order && in_array($order->current_state, [2, 9, 10])) {
            Tools::redirect(
                'index.php?controller=order-confirmation&id_cart=' . $cartId .
                '&id_module=' . $this->module->id .
                '&id_order=' . $order->id .
                '&key=' . $order->secure_key
            );
        }

        $this->handleError(self::ERROR_CONFIG);
    }

    /**
     * @param $payrexxError
     * @return void
     */
    private function handleError($payrexxError)
    {
        switch ($payrexxError) {
            case self::ERROR_CONFIG:
                $errMsg = $this->trans(
                    'The connection to the payment provider failed. Please contact the Shop owner',
                    [],
                    'Modules.Payrexx.Shop'
                );
                break;
            case self::ERROR_CANCEL:
                $errMsg = $this->trans('The transaction was cancelled. Please try again', [], 'Modules.Payrexx.Shop');
                break;
            case self::ERROR_FAIL:
            default:
                $errMsg = $this->trans('The transaction failed. Please try again', [], 'Modules.Payrexx.Shop');
                break;
        }

        $this->errors[] = $errMsg;
        $this->redirectWithNotifications('index.php?controller=order&step=1');
    }
}
