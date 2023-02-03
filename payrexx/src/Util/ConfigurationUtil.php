<?php

namespace Payrexx\PayrexxPaymentGateway\Util;

class ConfigurationUtil
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
}
