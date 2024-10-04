<?php
/**
 * Payrexx FrontController
 *
 * @author Payrexx <integration@payrexx.com>
 * @copyright  2023 Payrexx
 * @license MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Payrexx\PayrexxException;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxApiService;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxDbService;

class PayrexxPayrexxModuleFrontController extends ModuleFrontController
{
    private $supportedLang = ['nl', 'fr', 'de', 'it', 'nl', 'pt', 'tr', 'pl', 'es', 'dk'];
    private $defaultLang = 'en';

    public function postProcess()
    {
        try {
            // Collect Gateway data
            if (version_compare(_PS_VERSION_, '1.7.6', '<')) {
                $payrexxDbService = new PayrexxDbService();
                $payrexxApiService = new PayrexxApiService();
            } else {
                $payrexxDbService = $this->get('payrexx.payrexxpaymentgateway.payrexxdbservice');
                $payrexxApiService = $this->get('payrexx.payrexxpaymentgateway.payrexxapiservice');
            }
            $context = Context::getContext();

            $cart = $context->cart;
            $customer = $context->customer;

            $invoiceAddress = new Address($cart->id_address_invoice);
            $deliveryAddress = new Address($cart->id_address_delivery);

            $billingAddress = [
                'firstname' => $invoiceAddress->firstname,
                'lastname' => $invoiceAddress->lastname,
                'company' => $invoiceAddress->company,
                'street' => $invoiceAddress->address1 . ' ' . $invoiceAddress->address2,
                'postcode' => $invoiceAddress->postcode,
                'city' => $invoiceAddress->city,
                'country' => Country::getIsoById($invoiceAddress->id_country),
                'phone' => $invoiceAddress->phone,
            ];

            $shippingAddress = [
                'firstname' => $deliveryAddress->firstname,
                'lastname' => $deliveryAddress->lastname,
                'company' => $deliveryAddress->company,
                'street' => $deliveryAddress->address1 . ' ' . $deliveryAddress->address2,
                'postcode' => $deliveryAddress->postcode,
                'city' => $deliveryAddress->city,
                'country' => Country::getIsoById($deliveryAddress->id_country),
            ];
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $currency = $context->currency->iso_code;

            $redirectUrls = [
                'success' => $context->link->getModuleLink($this->module->name, 'validation', [], true),
                'cancel' => $context->link->getModuleLink($this->module->name, 'validation', ['payrexxError' => 'cancel'], true),
                'failed' => $context->link->getModuleLink($this->module->name, 'validation', ['payrexxError' => 'fail'], true),
            ];
            $currencyIsoCode = !empty($currency) ? $currency : 'USD';

            if ($gatewayId = $payrexxDbService->getCartGatewayId($cart->id)) {
                $payrexxApiService->deletePayrexxGateway($gatewayId);
            }
            $paymentMethod = Tools::getValue('payrexxPaymentMethod');
            $pm = ($paymentMethod != 'payrexx') ? [$paymentMethod] : [];
            $gateway = $payrexxApiService->createPayrexxGateway(
                $total,
                $currencyIsoCode,
                $redirectUrls,
                $cart,
                $customer,
                $billingAddress,
                $shippingAddress,
                $pm
            );

            if (!$gateway) {
                throw new PayrexxException();
            }
            $context->cookie->paymentId = $gateway->getId();
            $payrexxDbService->insertGatewayInfo(
                $cart->id,
                $gateway->getId(),
                $paymentMethod
            );
            $lang = Language::getIsoById($context->cookie->id_lang);

            if (!in_array($lang, $this->supportedLang)) {
                $lang = $this->defaultLang;
            }

            $link = $gateway->getLink();
            $gatewayUrl = str_replace('?', $lang . '/?', $link);

            Tools::redirect($gatewayUrl);
        } catch (PayrexxException $e) {
            Tools::redirect(
                Context::getContext()->link->getModuleLink(
                    $this->module->name,
                    'validation',
                    [
                        'payrexxError' => 'config',
                    ],
                    true
                )
            );
        }
    }
}
