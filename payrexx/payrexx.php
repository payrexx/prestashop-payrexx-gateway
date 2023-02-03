<?php
/**
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author Payrexx <integration@payrexx.com>
 * @copyright  2022 Payrexx
 * @license MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

use \Payrexx\PayrexxPaymentGateway\Util\ConfigurationUtil;
use \Payrexx\PayrexxPaymentGateway\Service\PayrexxApiService;

class Payrexx extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'payrexx';
        $this->tab = 'payments_gateways';
        $this->module_key = '0c4dbfccbd85dd948fd9a13d5a4add90';
        $this->version = '1.2.2';
        $this->author = 'Payrexx';
        $this->is_eu_compatible = 1;
        $this->ps_versions_compliancy = array('min' => '1.6');
        $this->controllers = array('payment', 'validation', 'gateway');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Payrexx';
        $this->description = 'Accept payments using Payrexx Payment gateway';
        $this->confirmUninstall = 'Are you sure you want to uninstall?';
        $this->validateDb();
    }

    private function validateDb()
    {
        Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'payrexx_gateway` (
                id_cart INT(11) NOT NULL UNIQUE,
                id_gateway INT(11) UNSIGNED DEFAULT "0" NOT NULL,
                PRIMARY KEY (`id_cart`)
            ) DEFAULT CHARSET=utf8');
    }

    public function install()
    {
        // Install default
        if (!parent::install() || !$this->installDb() || !$this->registrationHook()) {
            return false;
        }

        foreach (ConfigurationUtil::getConfigKeys() as $configKey) {
            if (!Configuration::updateValue($configKey, '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install DataBase table
     * @return boolean if install was successfull
     */
    private function installDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'payrexx_gateway` (
                id_cart INT(11) NOT NULL UNIQUE,
                id_gateway INT(11) UNSIGNED DEFAULT "0" NOT NULL,
                PRIMARY KEY (`id_cart`)
            ) DEFAULT CHARSET=utf8');
    }

    /**
     * [registrationHook description]
     * @return [type] [description]
     */
    private function registrationHook()
    {
        if (_PS_VERSION_ >= '1.7' && !$this->registerHook('paymentOptions')) {
            return false;
        } elseif (_PS_VERSION_ < '1.7' &&
            (!$this->registerHook('payment') || !$this->registerHook('paymentReturn'))) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $config = ConfigurationUtil::getConfigKeys();
        foreach ($config as $var) {
            Configuration::deleteByName($var);
        }

        //Uninstall DataBase
        if (!$this->uninstallDb()) {
            return false;
        }

        // Uninstall default
        if (!parent::uninstall()) {
            return false;
        }
        return true;
    }

    /**
     * Uninstall DataBase table
     * @return boolean if install was successfull
     */
    private function uninstallDb()
    {
        return Db::getInstance()->execute('DROP TABLE `' . _DB_PREFIX_ . 'payrexx_gateway`');
    }

    public function getContent()
    {
        $this->postProcess();

        foreach (ConfigurationUtil::getPlatforms() as $url => $platformName) {
            $platforms[] = [
                'url' => $url,
                'name' => $platformName,
            ];
        }

        $fields_form = [];
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => 'Settings',
                'icon' => 'icon-cogs',
            ],
            'input' => [
                [
                    'type' => 'select',
                    'label' => 'Payment Platform',
                    'name' => 'payrexx_platform',
                    'desc' => 'Choose the platform provider from the list',
                    'multiple' => false,
                    'options' => [
                        'query' => $platforms,
                        'id' => 'url',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => 'API Secret',
                    'name' => 'payrexx_api_secret',
                    'desc' => 'Paste here your API key from the Integrations page of your Payrexx merchant backend.',
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => 'INSTANCE NAME',
                    'name' => 'payrexx_instance_name',
                    'desc' => 'INSTANCE NAME is a part of the url where you access your payrexx installation.
                    https://INSTANCE.payrexx.com',
                    'required' => true
                ],
                [
                    'type' => 'text',
                    'label' => 'Look and Feel Profile Id',
                    'name' => 'payrexx_look_and_feel_id',
                    'desc' => 'Enter a profile ID if you wish to use a specific Look&Feel profile.',
                ],
            ],
            'submit' => [
                'title' => 'Save',
                'class' => 'btn btn-default pull-right'
            ],
        ];
        foreach (ConfigurationUtil::getConfigKeys() as $configKey) {
            $fieldsValue[strtolower($configKey)] = Configuration::get($configKey);
        }
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'payrexx_config';
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->tpl_vars = array(
            'fields_value' => $fieldsValue,
            'id_language' => $this->context->language->id,
            'back_url' => $this->context->link->getAdminLink('AdminModules')
                . '&configure=' . $this->name
                . '&tab_module=' . $this->tab
                . '&module_name=' . $this->name
                . '#paypal_params'
        );
        $form = $helper->generateForm($fields_form);

        return $form;
    }

    private function postProcess()
    {
        if (!Tools::isSubmit('payrexx_config')) {
            return;
        }
        $payrexxApiService = new PayrexxApiService();
        $signatureCheck = $payrexxApiService->validateSignature(
            Tools::getValue('payrexx_instance_name'),
            Tools::getValue('payrexx_api_secret'),
            Tools::getValue('payrexx_platform')
        );
        if (!$signatureCheck) {
            $this->context->controller->errors[] = 'Please enter valid credentials! Try again.';
            return false;
        }
        foreach (ConfigurationUtil::getConfigKeys() as $configKey) {
            $configValue = Tools::getValue(strtolower($configKey));
            Configuration::updateValue($configKey, $configValue);
        }
        $this->context->controller->confirmations[] = 'Settings are successfully updated.';
    }

    // Payment hook for version < 1.7
    public function hookPaymentReturn($params)
    {
        // By default Prestashop v1.6 will display the order confirmation message for the guest users
        if ($this->context->customer->is_guest) {
            return;
        }

        $invoice_url = null;
        if ($params['objOrder'] && !empty($params['objOrder']->id)) {
            $invoice_url = $this->context->link->getPageLink(
                'pdf-invoice',
                true,
                $this->context->language->id,
                "id_order={$params['objOrder']->id}"
            );
        }
        $customer_email = null;
        if ($params['cart'] && !empty($params['cart']->id_customer)) {
            $customer = new Customer($params['cart']->id_customer);
            $customer_email = $customer->email;
        }
        $this->smarty->assign(array(
            'invoice_url' => $invoice_url,
            'customer_email' => $customer_email,
        ));
        return $this->display(__FILE__, 'confirmation.tpl');
    }

    // Payment hook for version < 1.7
    public function hookPayment($params)
    {
        $action_text = $this->l('Payrexx payment method title');
        $this->smarty->assign(array(
            'payrexx_url' => $this->context->link->getModuleLink($this->name, 'payrexx'),
            'image_path' => $this->_path,
            'title' => $action_text,
        ));
        return $this->display(__FILE__, 'payrexx_payment.tpl');
    }

    /**
     * Return payment options available for PS 1.7+
     *
     * @param array Hook parameters
     *
     * @return array
     */
    public function hookPaymentOptions($params)
    {
        $payment_option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $action_text = $this->l('Payrexx payment method title');
        $this->context->smarty->assign(array(
            'path' => $this->_path,
        ));
        $payment_option->setCallToActionText($action_text);
        $payment_option->setAction($this->context->link->getModuleLink($this->name, 'payrexx'));

        $payment_option->setAdditionalInformation($this->l('Payrexx payment method description'));

        $payment_options = array(
            $payment_option,
        );

        return $payment_options;
    }
}
