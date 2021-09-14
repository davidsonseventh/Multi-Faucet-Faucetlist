<?php

$dbhost = 'localhost';
$dbuser = 'id17237007_faucetpay';
$dbpass = 'D4v1d@777321';
$dbname = 'id17237007_faucet';
$dbtable_prefix = ''; // default empty
$dbtable_shortlink_pool_prefix = ''; // default empty
$display_errors = true; // change to false once installed and tested
$disable_admin_panel = false;

$expresscrypto_ref_url = 'https://expresscrypto.io/signup?referral=246990';
$kswallet_ref_url = 'https://www.kswallet.net/register?r=makejar.com';
$cedsonhub_ref_url = 'https://cedsonhub.site/#/referral?r=MakeJar';
$faucetpay_ref_url = 'https://faucetpay.io/?r=366233';
$microwallet_ref_url = 'https://www.microwallet.co/ref/MakeJar';
$walcrypt_ref_url = 'https://www.walcrypt.com/register?ref=1174';
$microcoinpay_ref_url = 'https://microcoinpay.com/r/319';

$connection_options = array(
    'disable_curl' => false,
    'local_cafile' => false,
    'force_ipv4' => false    // cURL only
);

// dsn - Data Source Name
// if you use MySQL, leave it as is
// more information:
// http://php.net/manual/en/pdo.construct.php
$dbdsn = "mysql:host=$dbhost;dbname=$dbname";

// Uncomment the line below if you are getting Warning: strtotime(): It is not safe to rely on the system's timezone settings.
//date_default_timezone_set('UTC');
