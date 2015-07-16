<?php

$config = array(
    'merchantid' => '',
    'password' => '',
    'currency' => array(
        'code' => 203,
        'format' => '%d Kč'
    ),
);

Configure::write('GlobalPayments', $config);

App::import(
    'Vendor',
    'Payment.CSignature',
    array('file' => 'GlobalPayments/signature.php')
);

App::import(
    'Vendor',
    'Payment.WebPayRequest',
    array('file' => 'Sebik/webpay.php')
);
