<?php
/**
 * Payrexx Payment Gateway - upgrade the module
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright Payrexx AG
 * @license   MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_13($module)
{
    // WIRpay is no longer supported by Payrexx and a remaining row would show it in the checkout without a title.
    return Db::getInstance()->delete('payrexx_payment_methods', 'pm = \'wirpay\'');
}
