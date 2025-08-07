<?php
/**
 * Payrexx Payment Gateway.
 *
 * @author    Payrexx <integration@payrexx.com>
 * @copyright 2024 Payrexx
 * @license   MIT License
 */
if ( ! defined('_PS_VERSION_')) {
    exit;
}

use Payrexx\PayrexxPaymentGateway\Config\PayrexxConfig;
use Payrexx\PayrexxPaymentGateway\Service\PayrexxApiService;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! class_exists(PayrexxConfig::class)) {
    $autoloadLocation = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoloadLocation)) {
        require_once $autoloadLocation;
    }
}

class Payrexx extends PaymentModule
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name                   = 'payrexx';
        $this->tab                    = 'payments_gateways';
        $this->module_key             = '0c4dbfccbd85dd948fd9a13d5a4add90';
        $this->version                = '1.6.0';
        $this->author                 = 'Payrexx';
        $this->is_eu_compatible       = 1;
        $this->ps_versions_compliancy = ['min' => '1.7'];
        $this->controllers            = ['payment', 'validation', 'gateway'];
        $this->bootstrap              = true;

        parent::__construct();

        $this->displayName      = 'Payrexx';
        $this->description      = 'Accept payments using Payrexx Payment gateway';
        $this->confirmUninstall = 'Are you sure you want to uninstall?';
    }

    /**
     * {@inheritdoc}
     */
    public function install()
    {
        // Install default
        if ( ! parent::install()
             || ! $this->installDb()
             || ! $this->registerHook('paymentOptions')
             || ! $this->registerHook('actionFrontControllerSetMedia')
        ) {
            return false;
        }

        return true;
    }

    /**
     * Install DataBase table
     *
     * @return bool if install was successfull
     */
    private function installDb()
    {
        $installed = include dirname(__FILE__) . '/sql/install.php';

        return $installed;
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall()
    {
        $config = PayrexxConfig::getConfigKeys();
        foreach ($config as $var) {
            Configuration::deleteByName($var);
        }

        // Uninstall DataBase
        if ( ! $this->uninstallDb()) {
            return false;
        }

        if ( ! parent::uninstall()) {
            return false;
        }

        return true;
    }

    /**
     * Uninstall DataBase table
     *
     * @return bool if uninstall was successfull
     */
    private function uninstallDb()
    {
        $unInstalled = include dirname(__FILE__) . '/sql/uninstall.php';

        return $unInstalled;
    }

    public function getContent()
    {
        $this->postProcess();

        if (!$this->isRegisteredInHook('actionAdminControllerSetMedia')) {
            $this->registerHook('actionAdminControllerSetMedia');
        }

        foreach (PayrexxConfig::getPlatforms() as $url => $platformName) {
            $platforms[] = [
                'url'  => $url,
                'name' => $platformName,
            ];
        }

        $fields_form            = [];
        $fields_form[0]['form'] = [
            'legend'  => [
                'title' => 'Settings',
                'icon'  => 'icon-cogs',
            ],
            'input'   => [
                [
                    'type'     => 'select',
                    'label'    => 'Payment Platform',
                    'name'     => 'payrexx_platform',
                    'id'       => 'payrexx-platform-select',
                    'desc'     => 'Choose the platform provider from the list',
                    'multiple' => false,
                    'options'  => [
                        'query' => $platforms,
                        'id'    => 'url',
                        'name'  => 'name',
                    ],
                ],
                [
                    'type'     => 'text',
                    'label'    => 'API Secret',
                    'name'     => 'payrexx_api_secret',
                    'id'       => 'payrexx-api-secret-input',
                    'desc'     => 'Paste here your API key from the Integrations page of your Payrexx merchant backend.',
                    'required' => true,
                ],
                [
                    'type'     => 'text',
                    'label'    => 'INSTANCE NAME',
                    'name'     => 'payrexx_instance_name',
                    'id'       => 'payrexx-instance-name-input',
                    'desc'     => 'INSTANCE NAME is a part of the url where you access your payrexx installation.
                    https://INSTANCE.payrexx.com',
                    'required' => true,
                ],
                [
                    'type'  => 'text',
                    'label' => 'Look and Feel Profile Id',
                    'name'  => 'payrexx_look_and_feel_id',
                    'id'       => 'payrexx-look-and-feel-id-input',
                    'desc'  => 'Enter a profile ID if you wish to use a specific Look&Feel profile.',
                ],
            ],
            'buttons' => [
                [
                    'title'   => 'Connect With Platform',
                    'name'    => 'connect_with_platform',
                    'id'      => 'connect-with-platform-button',
                    'type'    => 'button',
                    'class'   => 'btn btn-default pull-left',
                    'js'      => 'connect()',
                ],
            ],
            'submit'  => [
                'title' => 'Save',
                'class' => 'btn btn-default pull-right',
                'id'    => 'save-settings-button',
            ],
        ];
        foreach (PayrexxConfig::getConfigKeys() as $configKey) {
            $fieldsValue[strtolower($configKey)] = Configuration::get($configKey);
        }
        $helper                           = new HelperForm();
        $helper->module                   = $this;
        $helper->name_controller          = $this->name;
        $helper->token                    = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex             = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->title                    = $this->displayName;
        $helper->show_toolbar             = false;
        $helper->submit_action            = 'payrexx_config';
        $default_lang                     = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->default_form_language    = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->tpl_vars                 = [
            'fields_value' => $fieldsValue,
            'id_language'  => $this->context->language->id,
            'back_url'     => $this->context->link->getAdminLink('AdminModules')
                              . '&configure=' . $this->name
                              . '&tab_module=' . $this->tab
                              . '&module_name=' . $this->name
                              . '#paypal_params',
        ];
        $form                             = $helper->generateForm($fields_form) . $this->renderAdditionalPaymentMethodsList();

        return $form;
    }

    /**
     * Rendered payment method list.
     */
    protected function renderAdditionalPaymentMethodsList()
    {
        $this->fieldsList = [
            'active'   => [
                'title'  => 'Status',
                'active' => 'status',
                'type'   => 'bool',
            ],
            'pm'       => [
                'title' => 'Payment Method',
                'type'  => 'text',
            ],
            'position' => [
                'title' => 'Sorting',
                'type'  => 'text',
            ],
        ];

        $adminLinkController             = Context::getContext()->link->getAdminLink('AdminPayrexxPaymentMethods',
            false);
        $token                           = Tools::getAdminTokenLite('AdminPayrexxPaymentMethods');
        $helperList                      = new HelperList();
        $helperList->table               = 'payrexx_payment_methods';
        $helperList->shopLinkType        = '';
        $helperList->position_identifier = 'position';
        $helperList->simple_header       = true;
        $helperList->identifier          = 'id';
        $helperList->actions             = ['edit'];
        $helperList->show_toolbar        = false;
        $helperList->title               = 'Payment Methods';
        $helperList->currentIndex        = $adminLinkController;
        $helperList->token               = $token;

        $content               = $this->getPaymentMethodsList(false);
        $helperList->listTotal = count($content);

        return $helperList->generateList($content, $this->fieldsList);
    }

    /**
     * Get payment methods list.
     *
     * @param bool $filterActive
     *
     * @return array
     */
    public function getPaymentMethodsList($filterActive = false): array
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('payrexx_payment_methods');
        if ($filterActive) {
            $sql->where('active = 1');
            $sql->orderBy('position');
        }

        return Db::getInstance()->ExecuteS($sql);
    }

    /**
     * Process config values.
     */
    private function postProcess()
    {
        if ( ! Tools::isSubmit('payrexx_config')) {
            return;
        }
        $payrexxApiService = new PayrexxApiService();
        $signatureCheck    = $payrexxApiService->validateSignature(
            Tools::getValue('payrexx_instance_name'),
            Tools::getValue('payrexx_api_secret'),
            Tools::getValue('payrexx_platform')
        );
        if ( ! $signatureCheck) {
            $this->context->controller->errors[] = 'Please enter valid credentials! Try again.';

            return false;
        }
        foreach (PayrexxConfig::getConfigKeys() as $configKey) {
            $configValue = Tools::getValue(strtolower($configKey));
            Configuration::updateValue($configKey, $configValue);
        }
        $this->context->controller->confirmations[] = 'Settings are successfully updated.';
    }

    /**
     * Add asset for Shop Front Office
     *
     * @see https://devdocs.prestashop.com/1.7/themes/getting-started/asset-management/#without-a-front-controller-module
     *
     * @param array $params
     */
    public function hookActionFrontControllerSetMedia(array $params)
    {
        $this->context->controller->registerStylesheet(
            'payrexx-payment-method-icon',
            '/modules/' . $this->name . '/views/css/custom.css'
        );
        $paymentMeans = array_column($this->getPaymentMethodsList(true), 'pm');
        if ( ! in_array('apple-pay', $paymentMeans) && ! in_array('google-pay', $paymentMeans)) {
            return;
        }

        // Apple pay device check related js
        if (in_array('apple-pay', $paymentMeans)) {
            $this->context->controller->registerJavascript(
                'payrexx-payment-method-apple-pay',
                '/modules/' . $this->name . '/views/js/applepay.js',
                [
                    'priority' => 996,
                    'position' => 'bottom',
                ]
            );
        }
        // Google pay device check related js
        if (in_array('google-pay', $paymentMeans)) {
            $this->context->controller->registerJavascript(
                'payrexx-payment-method-google-pay-lib',
                'https://pay.google.com/gp/p/js/pay.js',
                [
                    'priority' => 996,
                    'server'   => 'remote',
                    'position' => 'bottom',
                ]
            );
            $this->context->controller->registerJavascript(
                'payrexx-payment-method-google-pay',
                '/modules/' . $this->name . '/views/js/googlepay.js',
                [
                    'priority' => 997,
                    'position' => 'bottom',
                ]
            );
        }
    }

    /**
     * Return payment options
     *
     * @param array $params parameters
     *
     * @return array
     */
    public function hookPaymentOptions($params)
    {
        // Additional payment methods
        $this->loadTranslationsInUi();
        $action         = $this->context->link->getModuleLink($this->name, 'payrexx');
        $paymentMethods = [];
        foreach ($this->getPaymentMethodsList(true) as $paymentMethod) {
            if ( ! $this->allowedPaymentMethodToPay($paymentMethod)) {
                continue;
            }

            $configPaymentMethods = PayrexxConfig::getPaymentMethods();
            $title                = $configPaymentMethods[$paymentMethod['pm']];
            $imageSrc             = $this->_path . 'views/img/cardicons/card_' .
                                    str_replace('-', '_', $paymentMethod['pm']) . '.svg';

            $paymentOption = new PaymentOption();
            $paymentOption->setAction($action);
            $paymentOption->setModuleName($this->name);
            $paymentOption->setCallToActionText($this->l($title));
            $paymentOption->setInputs(
                [
                    'pm' => [
                        'name'  => 'payrexxPaymentMethod',
                        'type'  => 'hidden',
                        'value' => $paymentMethod['pm'],
                    ],
                ]
            );
            $paymentOption->setLogo($imageSrc);
            if ($paymentMethod['pm'] == 'payrexx') {
                $paymentOption->setLogo('');
                $paymentOption->setAdditionalInformation(
                    $this->l('Payrexx payment method description')
                );
            }
            $paymentMethods[] = $paymentOption;
        }

        return $paymentMethods;
    }

    public function hookActionAdminControllerSetMedia(array $params)
    {
        // We only want to load this JS on our module's configuration page
        if (Tools::getValue('configure') === $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/connect.js');
        }
    }

    /**
     * Allowed Payment Method to pay
     *
     * @param array $paymentMethod payment method
     *
     * @return true|false
     */
    public function allowedPaymentMethodToPay(array $paymentMethod): bool
    {
        $allowedCountries      = json_decode($paymentMethod['country'], true);
        $allowedCurrencies     = json_decode($paymentMethod['currency'], true);
        $allowedCustomerGroups = json_decode($paymentMethod['customer_group'], true);
        if ( ! empty($allowedCountries) && ! in_array($this->context->country->id, $allowedCountries)) {
            return false;
        }
        if ( ! empty($allowedCurrencies) && ! in_array($this->context->currency->id, $allowedCurrencies)) {
            return false;
        }
        if ( ! empty($allowedCustomerGroups)
             && empty(array_intersect(
                $this->context->customer->getGroups(),
                $allowedCustomerGroups
            ))
        ) {
            return false;
        }

        return true;
    }

    /**
     * To load the translation texts in Ui
     * Refer: https://devdocs.prestashop-project.org/8/development/internationalization/translation/translation-tips/
     *
     * @return void
     */
    public function loadTranslationsInUi()
    {
        $this->l('Payrexx Payment Methods');
        $this->l('Masterpass');
        $this->l('Mastercard');
        $this->l('Visa');
        $this->l('Apple Pay');
        $this->l('Maestro');
        $this->l('JCB');
        $this->l('American Express');
        $this->l('WIRpay');
        $this->l('PayPal');
        $this->l('Bitcoin');
        $this->l('Airplus');
        $this->l('Billpay');
        $this->l('Bonus card');
        $this->l('CashU');
        $this->l('Carte Bleue');
        $this->l('Diners Club');
        $this->l('Direct Debit');
        $this->l('Discover');
        $this->l('ELV');
        $this->l('iDEAL');
        $this->l('Invoice');
        $this->l('My One');
        $this->l('Paysafe Card');
        $this->l('Post Finance Pay');
        $this->l('SwissBilling');
        $this->l('TWINT');
        $this->l('Barzahlen/Viacash');
        $this->l('Bancontact');
        $this->l('GiroPay');
        $this->l('EPS');
        $this->l('Google Pay');
        $this->l('WeChat Pay');
        $this->l('Alipay');
        $this->l('Centi');
        $this->l('Heidipay');
        $this->l('Bob Invoice');
        $this->l('Purchase on Invoice');
        $this->l('Klarna');
        $this->l('Samsung Pay');
        $this->l('Pay by Bank');
        $this->l('Powerpay');
        $this->l('CembraPay');
    }
}
