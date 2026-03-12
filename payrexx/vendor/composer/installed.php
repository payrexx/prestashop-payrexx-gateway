<?php return array(
    'root' => array(
        'name' => 'payrexx/payrexxpaymentgateway',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => 'c939835e1ff0ed0bc02cf8caab3fca3af0e4c464',
        'type' => 'prestashop-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'payrexx/payrexx' => array(
            'pretty_version' => 'v2.0.12',
            'version' => '2.0.12.0',
            'reference' => '3c65ea106c2b2ca444fd3c823fbc78be7cfeb75a',
            'type' => 'library',
            'install_path' => __DIR__ . '/../payrexx/payrexx',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'payrexx/payrexxpaymentgateway' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'c939835e1ff0ed0bc02cf8caab3fca3af0e4c464',
            'type' => 'prestashop-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
