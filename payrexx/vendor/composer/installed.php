<?php return array(
    'root' => array(
        'name' => 'payrexx/payrexxpaymentgateway',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '5ca0eb17e9fefe64d89da39c32edff39d7a3cdcd',
        'type' => 'prestashop-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'payrexx/payrexx' => array(
            'pretty_version' => 'v2.0.12',
            'version' => '2.0.12.0',
            'reference' => '6d249753a2b0aaf4b82ea73614c1ad0498d216be',
            'type' => 'library',
            'install_path' => __DIR__ . '/../payrexx/payrexx',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'payrexx/payrexxpaymentgateway' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '5ca0eb17e9fefe64d89da39c32edff39d7a3cdcd',
            'type' => 'prestashop-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
