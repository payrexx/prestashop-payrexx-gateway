<?php return array(
    'root' => array(
        'name' => 'payrexx/payrexxpaymentgateway',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '9ac0fdeed24c287a5b96e63d1a0c80e4ca6bf4e5',
        'type' => 'prestashop-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'payrexx/payrexx' => array(
            'pretty_version' => 'v2.0.8',
            'version' => '2.0.8.0',
            'reference' => '95473689ba082d327effbbc43fc2302d36c6c8bd',
            'type' => 'library',
            'install_path' => __DIR__ . '/../payrexx/payrexx',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'payrexx/payrexxpaymentgateway' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '9ac0fdeed24c287a5b96e63d1a0c80e4ca6bf4e5',
            'type' => 'prestashop-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
