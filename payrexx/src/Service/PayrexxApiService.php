<?php
/**
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright Payrexx AG
 * @license   MIT License
 */

namespace Payrexx\PayrexxPaymentGateway\Service;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Payrexx\Models\Request\SignatureCheck;
use Payrexx\Models\Response\Gateway;
use Payrexx\Models\Response\Transaction;
use Payrexx\PayrexxException;
use Payrexx\PayrexxPaymentGateway\Util\BasketUtil;

class PayrexxApiService
{
    private $instanceName;
    private $apiKey;
    private $platform;

    public function __construct()
    {
        $this->instanceName = \Configuration::get('PAYREXX_INSTANCE_NAME');
        $this->apiKey = \Configuration::get('PAYREXX_API_SECRET');
        $this->platform = \Configuration::get('PAYREXX_PLATFORM');
    }

    /**
     * @param int $gatewayId
     *
     * @return Gateway|null
     */
    public function getPayrexxGateway($gatewayId): ?Gateway
    {
        if (!$gatewayId) {
            return null;
        }

        $payrexx = $this->getInterface($this->instanceName, $this->apiKey, $this->platform);
        $gateway = new \Payrexx\Models\Request\Gateway();
        $gateway->setId($gatewayId);

        try {
            return $payrexx->getOne($gateway);
        } catch (PayrexxException $e) {
        }
        return null;
    }

    public function getTransactionByGateway($payrexxGateway): ?Transaction
    {
        if (!in_array($payrexxGateway->getStatus(), [Transaction::CONFIRMED, Transaction::WAITING])) {
            return null;
        }
        $invoices = $payrexxGateway->getInvoices();

        if (!$invoices || !$invoice = end($invoices)) {
            return null;
        }

        if (!$transactions = $invoice['transactions']) {
            return null;
        }

        return $this->getPayrexxTransaction(end($transactions)['id']);
    }

    /**
     * @param int $transactionId
     *
     * @return Transaction|null
     */
    public function getPayrexxTransaction($transactionId): ?Transaction
    {
        if (!$transactionId) {
            return null;
        }

        $payrexx = $this->getInterface($this->instanceName, $this->apiKey, $this->platform);
        $transaction = new \Payrexx\Models\Request\Transaction();
        $transaction->setId($transactionId);

        try {
            return $payrexx->getOne($transaction);
        } catch (PayrexxException $e) {
            return null;
        }
    }

    public function createPayrexxGateway(
        float $total,
        string $currency,
        array $redirectUrls,
        $cart,
        $customer,
        array $billingAddress,
        array $shippingAddress,
        array $pm,
        array $metaData
    ): ?Gateway {
        $basket = BasketUtil::createBasketByCart($cart);
        $basketAmount = BasketUtil::getBasketAmount($basket);
        $purpose = BasketUtil::createPurposeByCart($cart);

        $payrexx = $this->getInterface($this->instanceName, $this->apiKey, $this->platform);

        $gateway = new \Payrexx\Models\Request\Gateway();

        // Fallback for basket feature
        if ((int) $basketAmount === (int) ($total * 100)) {
            $gateway->setBasket($basket);
        } else {
            $gateway->setPurpose($purpose);
        }

        $gateway->setAmount((int)(string)($total * 100));
        $gateway->setCurrency($currency);
        $gateway->setSuccessRedirectUrl($redirectUrls['success']);
        $gateway->setCancelRedirectUrl($redirectUrls['cancel']);
        $gateway->setFailedRedirectUrl($redirectUrls['failed']);
        $gateway->setPsp([]);
        $gateway->setPm($pm);
        $gateway->setReferenceId($cart->id);
        $gateway->setSkipResultPage(true);
        $gateway->setValidity(15);

        if (\Configuration::get('PAYREXX_LOOK_AND_FEEL_ID')) {
            $gateway->setLookAndFeelProfile(\Configuration::get('PAYREXX_LOOK_AND_FEEL_ID'));
        }

        $gateway->addField('title', '');
        $gateway->addField('forename', $customer->firstname);
        $gateway->addField('surname', $customer->lastname);
        $gateway->addField('company', $customer->company);
        $gateway->addField('street', $billingAddress['street']);
        $gateway->addField('postcode', $billingAddress['postcode']);
        $gateway->addField('place', $billingAddress['city']);
        $gateway->addField('country', $billingAddress['country']);
        $gateway->addField('phone', $billingAddress['phone']);
        $gateway->addField('email', $customer->email);
        $gateway->addField('custom_field_1', $cart->id, 'Prestashop ID');

        $gateway->addField('delivery_forename', $shippingAddress['firstname']);
        $gateway->addField('delivery_surname', $shippingAddress['lastname']);
        $gateway->addField('delivery_company', $shippingAddress['company']);
        $gateway->addField('delivery_street', $shippingAddress['street']);
        $gateway->addField('delivery_postcode', $shippingAddress['postcode']);
        $gateway->addField('delivery_place', $shippingAddress['city']);
        $gateway->addField('delivery_country', $shippingAddress['country']);

        try {
            $payrexx->setHttpHeaders($metaData);
            return $payrexx->create($gateway);
        } catch (PayrexxException $e) {
        }
        return null;
    }

    public function deletePayrexxGateway($gatewayId)
    {
        $payrexx = $this->getInterface($this->instanceName, $this->apiKey, $this->platform);

        $gateway = new \Payrexx\Models\Request\Gateway();
        $gateway->setId($gatewayId);

        try {
            $payrexx->delete($gateway);
        } catch (PayrexxException $e) {
        }
    }

    /**
     * Get payrexx object
     *
     * @param string $instance
     * @param string $apiKey
     * @param string $platform
     * @return Payrexx
     */
    private function getInterface(string $instance, string $apiKey, string $platform): \Payrexx\Payrexx
    {
        return new \Payrexx\Payrexx($instance, $apiKey, '', $platform);
    }

    /**
     * validate the api signature
     *
     * @param string $instance
     * @param string $apiKey
     * @param string $platform
     * @return true|false
     */
    public function validateSignature(string $instance, string $apiKey, string $platform): bool
    {
        $payrexx = $this->getInterface($instance, $apiKey, $platform);
        try {
            $payrexx->getOne(new SignatureCheck());
            return true;
        } catch (PayrexxException $e) {
            return false;
        }
    }
}
