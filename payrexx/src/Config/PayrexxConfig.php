<?php
/**
 * Payrexx Payment Gateway config
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright 2024 Payrexx
 * @license   MIT License
 */
namespace Payrexx\PayrexxPaymentGateway\Config;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PayrexxConfig
{
    /**
     * Get payrexx platforms.
     *
     * @return array
     */
    public static function getPlatforms(): array
    {
        return [
            'payrexx.com' => 'Payrexx',
            'shop-and-pay.com' => 'Shop and Pay',
            'ideal-pay.ch' => 'Ideal Pay',
            'payzzter.com' => 'Payzzter',
            'paydirect.ch' => 'Paydirect',
        ];
    }

    /**
     * Get configuration keys.
     *
     * @return array
     */
    public static function getConfigKeys(): array
    {
        return [
            'PAYREXX_PLATFORM',
            'PAYREXX_API_SECRET',
            'PAYREXX_INSTANCE_NAME',
            'PAYREXX_LOOK_AND_FEEL_ID',
        ];
    }

    /**
     * Get payment methods
     *
     * @return array
     */
    public static function getPaymentMethods(): array
    {
        return [
            'payrexx' => 'Payrexx Payment Methods',
            'masterpass' => 'Masterpass',
            'mastercard' => 'Mastercard',
            'visa' => 'Visa',
            'apple-pay' => 'Apple Pay',
            'maestro' => 'Maestro',
            'jcb' => 'JCB',
            'american-express' => 'American Express',
            'wirpay' => 'WIRpay',
            'paypal' => 'PayPal',
            'bitcoin' => 'Bitcoin',
            'klarna' => 'Klarna',
            'airplus' => 'Airplus',
            'billpay' => 'Billpay',
            'bonuscard' => 'Bonus card',
            'cashu' => 'CashU',
            'cb' => 'Carte Bleue',
            'diners-club' => 'Diners Club',
            'sepa-direct-debit' => 'Direct Debit',
            'discover' => 'Discover',
            'elv' => 'ELV',
            'ideal' => 'iDEAL',
            'invoice' => 'Invoice',
            'myone' => 'My One',
            'paysafecard' => 'Paysafe Card',
            'post-finance-pay' => 'Post Finance Pay',
            'swissbilling' => 'SwissBilling',
            'twint' => 'TWINT',
            'barzahlen' => 'Barzahlen/Viacash',
            'bancontact' => 'Bancontact',
            'giropay' => 'GiroPay',
            'eps' => 'EPS',
            'google-pay' => 'Google Pay',
            'wechat-pay' => 'WeChat Pay',
            'alipay' => 'Alipay',
            'centi' => 'Centi',
            'heidipay' => 'Heidipay',
            'bob-invoice' => 'Bob Invoice',
            'bank-transfer' => 'Purchase on Invoice',
            'samsung-pay' => 'Samsung Pay',
            'pay-by-bank' => 'Pay by Bank',
            'powerpay' => 'Powerpay',
            'cembrapay' => 'Purchase on Account (CembraPay)',
            'crypto' => 'Crypto',
            'verd-cash' => 'VERD.cash',
        ];
    }

    /**
     * @param string $pm payment method key
     * @return string
     */
    public static function getPaymentMethodNameByPm(string $pm): string
    {
        $paymentMethods = self::getPaymentMethods();
        if (empty($pm) || $pm === 'payrexx' || !isset($paymentMethods[$pm])) {
            return 'Payrexx';
        }
        return $paymentMethods[$pm] . ' by Payrexx';
    }
}
