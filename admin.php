<?php

/*
* Faucet in a BOX
* https://faucetinabox.com/
*
* Copyright (c) 2014-2016 LiveHome Sp. z o. o.
*
* (ultimate) extensions and bugfixes by http://makejar.com/
*
* This file is part of Faucet in a BOX.
*
* Faucet in a BOX is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Faucet in a BOX is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Faucet in a BOX.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once 'script/common.php';
require_once 'script/admin_templates.php';
require_once 'libs/coolphpcaptcha.php';

require_once 'libs/loader.php';
Loader::register('2fa','RobThree\\Auth');
use \RobThree\Auth\TwoFactorAuth;
$mp = new \RobThree\Auth\Providers\Qr\QRLocal();
$tfa = new TwoFactorAuth('FBU [' . $faucet_settings_array['currency'] . ']', 6, 30, 'sha1', $mp);


$template_updates = array();

if (session_id()) {
    if (empty($_SESSION['csrftoken'.$session_prefix])) {
        regenerate_csrf_token();
    }
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (empty($_POST["csrftoken"]) || $_SESSION['csrftoken'.$session_prefix] != $_POST["csrftoken"]) {
            trigger_error("CSRF failed!");
            $_POST = array();
            $_REQUEST = array();
            $_SERVER["REQUEST_METHOD"] = "GET";
        }
    }
}

/*
if (!$pass) {
    // first run
    foreach ($default_data_query as $data_query) {
        $sql->query($data_query);
        usleep(25000);
    }
    $password = setNewPass();
    $page = str_replace('<:: content ::>', $pass_template, $master_template);
    $page = str_replace('<:: password ::>', $password, $page);
    die($page);
}
*/

# AntiBotLinks START
require_once('libs/antibotlinks.php');
$antibotlinks=new antibotlinks(true, 'ttf,otf');
$antibotlinks->admin_config_top();
# AntiBotLinks END

# NH START
require_once('libs/nh.php');
$nh=new nh();
$nh->admin_config_top();
# NH END


# ADMINLOG START
require_once('libs/adminlog.php');
$adminlog=new adminlog();
$adminlog->admin_config_top();
# ADMINLOG END

if ($disable_admin_panel) {
    trigger_error("Admin panel disabled in config!");
    header("Location: index.php");
    die("Please wait...");
}

if (array_key_exists('p', $_GET) && $_GET['p'] == 'logout') {
    $_SESSION = array();
    header('Location: '.basename($_SERVER['PHP_SELF']));
    exit;
}

if (array_key_exists('p', $_GET) && $_GET['p'] == 'password-reset') {
    $error = "";
    if (array_key_exists('dbpass', $_POST)) {
        $user_captcha = array_key_exists("captcha", $_POST) ? $_POST["captcha"] : "";
        $user_captcha = strtolower($user_captcha);
        $captcha = new FiabCoolCaptcha();
        $captcha->session_var = 'cool-php-captcha';
        if ($captcha->isValid($user_captcha)) {
            if ($_POST['dbpass'] == $dbpass) {
                $password = setNewPass();
                $page = str_replace('<:: content ::>', $pass_template, $master_template);
                $page = str_replace('<:: password ::>', $password, $page);
                die($page);
            } else {
                $error = $dbpass_error_template;
            }
        } else {
            $error = $captcha_error_template;
        }
    }
    $page = str_replace('<:: content ::>', $error.$pass_reset_template, $master_template);
    $page = str_replace("<:: csrftoken ::>", get_csrf_token(), $page);
    die($page);
}

$invalid_key = false;
if (array_key_exists('password', $_POST)) {
    $extra_protection_valid = true;
    // captcha
    if ($faucet_settings_array['enable_admin_captcha'] == 'on') {
        $user_captcha = array_key_exists('captcha', $_POST) ? $_POST['captcha'] : '';
        $captcha = new FiabCoolCaptcha();
        $captcha->session_var = 'cool-php-captcha';
        if (!$captcha->isValid($user_captcha)) {
            $extra_protection_valid = false;
            $admin_login_template = $captcha_error_template.$admin_login_template;
        }
    }
    // 2fa
    if (!empty($faucet_settings_array['code_2fa'])) {
        $tfa_verify = array_key_exists('tfa_verify', $_POST) ? $_POST['tfa_verify'] : '';
        if (!$tfa->verifyCode($faucet_settings_array['code_2fa'], $tfa_verify, 2)) {
            $extra_protection_valid = false;
            $admin_login_template = $tfa_error_template.$admin_login_template;
        }
    }
    // password
    if ($extra_protection_valid) {
        if ($pass == crypt($_POST['password'], $pass)) {
            $_SESSION['logged_in'.$session_prefix] = true;
            header('Location: ?session_check=0');
            die();
        } else {
            $admin_login_template = $login_error_template.$admin_login_template;
        }
    }
}

if (array_key_exists('session_check', $_GET)) {
    if (isset($_SESSION['logged_in' . $session_prefix])) {
        header('Location: ?');
        die();
    } else {
        //show alert on login screen
        $admin_login_template = $session_error_template.$admin_login_template;
    }
}

if (isset($_SESSION['logged_in'.$session_prefix])) { // logged in to admin page

    //ajax
    if (array_key_exists("action", $_POST)) {

        header("Content-type: application/json");

        $response = array("status" => 404);

        switch ($_POST["action"]) {
        case "check_referrals":

            $referral = array_key_exists("referral", $_POST) ? trim($_POST["referral"]) : "";

            $response["status"] = 200;
            $response["addresses"] = array();
            $response["ref"] = '';

            if (strlen($referral) > 0) {
                // check if ref by someone
                $q = $sql->prepare("SELECT `a`.`address`, `r`.`address` FROM `".$dbtable_prefix."Refs` `r` LEFT JOIN `".$dbtable_prefix."Addresses` `a` ON `r`.`id` = `a`.`ref_id` WHERE `a`.`address` LIKE ? ORDER BY `a`.`last_used` DESC");
                $q->execute([$referral]);
                if ($row = $q->fetch()) {
                    $response["ref"] = $row[1];
                }

                // check the refs
                $q = $sql->prepare("SELECT `a`.`address`, `r`.`address` FROM `".$dbtable_prefix."Refs` `r` LEFT JOIN `".$dbtable_prefix."Addresses` `a` ON `r`.`id` = `a`.`ref_id` WHERE `r`.`address` LIKE ? ORDER BY `a`.`last_used` DESC");
                $q->execute([$referral]);
                while ($row = $q->fetch()) {
                    $response["addresses"][] = $row[0];
                }

            }

            break;
        }

        die(json_encode($response));

    }

    // Cleanup the DB
    $sql->exec("DELETE FROM ".$dbtable_prefix."Addresses WHERE last_used<DATE_SUB(NOW(), INTERVAL 180 DAY);");
    $sql->exec("DELETE FROM ".$dbtable_prefix."IPs WHERE last_used<DATE_SUB(NOW(), INTERVAL 7 DAY);");
    $q = $sql->prepare("SELECT id, address FROM ".$dbtable_prefix."Refs;");
    $q->execute(array());
    while ($result=$q->fetch()) {
        $q2 = $sql->prepare("SELECT address FROM ".$dbtable_prefix."Addresses WHERE ref_id=? LIMIT 1;");
        $q2->execute(array($result['id']));
        if (!$result2=$q2->fetch()) {
            $q3 = $sql->prepare("DELETE FROM ".$dbtable_prefix."Refs WHERE id=?;");
            $q3->execute(array($result['id']));
        }
    }

    if (
            array_key_exists("update_status", $_GET) &&
            in_array($_GET["update_status"], ["success", "fail"])
            ) {
        if ($_GET["update_status"] == "success") {
            $oneclick_update_alert = $oneclick_update_success_template;
        } else {
            $oneclick_update_alert = $oneclick_update_fail_template;
        }
    } else {
        $oneclick_update_alert = "";
    }

    if (array_key_exists("encoded_data", $_POST)) {
        $data = base64_decode($_POST["encoded_data"]);
        if ($data) {
            parse_str($data, $tmp);
            $_POST = array_merge($_POST, $tmp);
        }
    }

    if (array_key_exists('get_options', $_POST)) {
        if (file_exists("templates/{$_POST["get_options"]}/setup.php")) {
            require_once("templates/{$_POST["get_options"]}/setup.php");
            die(getTemplateOptions($sql, $_POST['get_options']));
        } else {
            die('<p>No template defined options available.</p>');
        }
    } else if (
            array_key_exists('reset', $_POST) &&
            array_key_exists('factory_reset_confirm', $_POST) &&
            $_POST['factory_reset_confirm'] == 'on'
            ) {
        foreach ($default_data_query as $data_query) {
            $sql->query($data_query);
            usleep(25000);
        }
        header('Location: '.basename($_SERVER['PHP_SELF']));
        exit;
    }

    $q = $sql->prepare("SELECT value FROM ".$dbtable_prefix."Settings WHERE name = ?");
    $q->execute(array('apikey'));
    $apikey = $q->fetch();
    $apikey = $apikey[0];
    $q->execute(array('currency'));
    $currency = $q->fetch();
    $currency = $currency[0];
    $q->execute(array('service'));
    $service = $q->fetch();
    $service = $service[0];
    $q->execute(array('usertoken'));
    $usertoken = $q->fetch();
    $usertoken = $usertoken[0];

    $fb = new Service($service, $apikey, $currency, $usertoken, $connection_options);
    //
    if (isset($_SESSION['password_error'])) {
        $password_error = $_SESSION['password_error'];
        $_SESSION['password_error'] = '';
    } else {
        $password_error = '';
    }
    //
    if (isset($_SESSION['wrong_2fa_error'])) {
        $wrong_2fa_error = $_SESSION['wrong_2fa_error'];
        $_SESSION['wrong_2fa_error'] = '';
    } else {
        $wrong_2fa_error = '';
    }
    //
    if (isset($_SESSION['changes_saved'])) {
        $changes_saved = $_SESSION['changes_saved'];
        $_SESSION['changes_saved'] = '';
    } else {
        $changes_saved = '';
    }
    //
    $connection_error = '';
    $curl_warning = '';
    $missing_configs_info = '';
    if (!empty($missing_configs)) {
        $list = '';
        foreach ($missing_configs as $missing_config) {
            $list .= str_replace(array("<:: config_name ::>", "<:: config_default ::>", "<:: config_description ::>"), array($missing_config['name'], $missing_config['default'], $missing_config['desc']), $missing_config_template);
        }
        $missing_configs_info = str_replace("<:: missing_configs ::>", $list, $missing_configs_template);
    }
    if ($fb->curl_warning) {
        $curl_warning = $curl_warning_template;
    }
    $currencies = array('BTC', 'ADA', 'BCH', 'BCN', 'BLK', 'BSV', 'BTG', 'CURE', 'DASH', 'DCR', 'DGB', 'DOGE', 'ETC', 'ETH', 'EXG', 'EXS', 'GAME', 'HORA', 'LSK',
                        'LTC', 'MAID', 'MUE', 'NEO', 'POT', 'PPC', 'OMNI', 'STRAT', 'TRX', 'USD', 'VTC', 'WAVES', 'XMR', 'XPM', 'XRP', 'XZC', 'XTZ', 'ZEC');
    $send_coins_message = '';
    if (array_key_exists('send_coins', $_POST)) {
        $amount = array_key_exists('send_coins_amount', $_POST) ? intval($_POST['send_coins_amount']) : 0;
        $address = array_key_exists('send_coins_address', $_POST) ? trim($_POST['send_coins_address']) : '';
        if ($currency=='DOGE') {
            $amount = $amount/100000000;
        }
        $fb = new Service($service, $apikey, $currency, $usertoken, $connection_options);
        $ret = $fb->send($address, $amount, getIP());

        if (isset($ret['success'])) {
            $send_coins_message = str_replace(array('{{amount}}','{{address}}'), array($amount,$address), $send_coins_success_template);
        } else {
            $send_coins_message = str_replace(array('{{amount}}','{{address}}','{{error}}'), array($amount,$address,$ret['message']), $send_coins_error_template);
        }
    }

    if (array_key_exists('save_settings', $_POST)) {
        $service = $_POST['service'];
        $currency = $_POST['currency'];
        $usertoken = $_POST['usertoken'];
        $fb = new Service($service, $_POST['apikey'], $currency, $usertoken, $connection_options);
        $ret = $fb->getBalance();
        if ($fb->communication_error) {
            $connection_error = $connection_error_template;
        }

        if ((isset($ret['status'])) || (isset($ret['balance']))) {
            if ($ret['status'] == 403) {
                $invalid_key = true;
            } elseif ($ret['status'] == 405) {
                $sql->query("UPDATE ".$dbtable_prefix."Settings SET `value` = 0 WHERE name = 'balance'");
            } elseif (array_key_exists('balance', $ret)) {
                $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET `value` = ? WHERE name = 'balance'");
                if ($currency != 'DOGE') {
                    $q->execute(array($ret['balance']));
                } else {
                    if (isset($ret['balance_bitcoin'])) {
                        $balance = $ret['balance_bitcoin'];
                    } else {
                        $balance = $ret['balance']/100000000;
                    }
                    $q->execute(array($balance));
                }
            }
        }

        $q = $sql->prepare("INSERT IGNORE INTO ".$dbtable_prefix."Settings (`name`, `value`) VALUES (?, ?)");
        $template = $_POST["template"];
        preg_match_all('/\$data\[([\'"])(custom_(?:(?!\1).)*)\1\]/', file_get_contents("templates/$template/index.php"), $matches);
        foreach ($matches[2] as $box) {
            $q->execute(array("{$box}_$template", ''));
        }

# save shortlinks
        $shortlink_data=array();
        if ((isset($_POST['shortlinks_apikey']))&&(is_array($_POST['shortlinks_apikey']))) {
            $update_data = array();
            $update_data['shortlink_providers'] = array();
            $q = $sql->prepare("SELECT value FROM ".$dbtable_prefix."Settings WHERE name = ?;");
            $q->execute(array('update_data'));
            if ($row = $q->fetch()) {
                $update_data = @json_decode($row[0], true);
            }

            foreach ($_POST['shortlinks_apikey'] as $shortlinks_key=>$shortlinks_value) {
                if (!empty($_POST['shortlinks_apikey'][$shortlinks_key])) {
                    if (empty($_POST['shortlinks_enabled'][$shortlinks_key])) {
                        $_POST['shortlinks_enabled'][$shortlinks_key]=0;
                    }
                    if (!isset($update_data['shortlink_providers'][$shortlinks_key]['days'])) {
                        $update_data['shortlink_providers'][$shortlinks_key]['days'] = 0;
                    }
                    $shortlink_data[$shortlinks_key]=array(
                    'apikey'=>trim($_POST['shortlinks_apikey'][$shortlinks_key]),
                    'apilink'=>$update_data['shortlink_providers'][$shortlinks_key]['link_api'],
                    'enabled'=>(int)$_POST['shortlinks_enabled'][$shortlinks_key],
                    'priority'=>(int)$_POST['shortlinks_priority'][$shortlinks_key],
                    'limit'=>(int)$_POST['shortlinks_limit'][$shortlinks_key],
                    'days'=>(int)$update_data['shortlink_providers'][$shortlinks_key]['days']
                    );
                }
            }
            //print_r($shortlink_data);exit;
            // sort shortlink data using priority
            uasort($shortlink_data, 'sort_shortlink_data');

            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE name = ?");
            $q->execute(array(json_encode($shortlink_data), 'shortlink_data'));
            //unset($_POST['shortlinks_apilink']);
            unset($_POST['shortlinks_apikey']);
            unset($_POST['shortlinks_enabled']);
            unset($_POST['shortlinks_limit']);
            //unset($_POST['shortlinks_days']);
            unset($_POST['shortlinks_priority']);
        }

        // 2fa
        if (!empty($_POST['enable_2fa'])) {
            if (!empty($_POST['code_2fa_verify'])) {
                if (!$tfa->verifyCode($_POST['code_2fa'], $_POST['code_2fa_verify'], 2)) {
                    // invalid verify code
                    $_POST['code_2fa'] = '';
                    $_SESSION['wrong_2fa_error'] = $wrong_2fa_error_template;
                }
            } else {
                unset($_POST['code_2fa']);
            }
        } else {
            $_POST['code_2fa'] = '';
        }

        // new_password
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password'])<8) {
                $_SESSION['password_error'] = $password_error_template;
            } else {
                setNewPass($_POST['new_password']);
            }
        }

        $sql->beginTransaction();
        $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE name = ?");
        $ipq = $sql->prepare("INSERT INTO ".$dbtable_prefix."Pages (url_name, name, html) VALUES (?, ?, ?)");
        $sql->exec("DELETE FROM ".$dbtable_prefix."Pages");
        foreach ($_POST as $k => $v) {
            if ($k == 'apikey' && $invalid_key)
            continue;
            if ($k == 'pages') {
                foreach ($_POST['pages'] as $p) {
                    $url_name = strtolower(preg_replace("/[^A-Za-z0-9_\-]/", '', $p["name"]));
                    $i = 0;
                    $success = false;
                    while (!$success) {
                        try {
                            if ($i)
                            $ipq->execute(array($url_name.'-'.$i, $p['name'], $p['html']));
                            else
                            $ipq->execute(array($url_name, $p['name'], $p['html']));
                            $success = true;
                        } catch(PDOException $e) {
                            $i++;
                        }
                    }
                }
                continue;
            }
            $q->execute(array($v, $k));
        }
        foreach (['reward_in_USD', 'block_adblock', 'iframe_sameorigin_only', 'nastyhosts_enabled', 'iphub_enabled', 'proxycheck_enabled', 'shortlink_required', 'reverse_proxy', 'disable_refcheck', 'show_recent_payouts', 'disallow_www', 'disallow_email_claiming', 'enable_admin_captcha', 'show_referred_users'] as $key) {
            if (!array_key_exists($key, $_POST)) $q->execute(array("", $key));
        }

        # load the USD rate if needed
        if ((isset($_POST['reward_in_USD'])) && (!empty($_POST['reward_in_USD']))) {
            $faucet_settings_array['currency'] = $_POST['currency'];
            getUSDrate(true);
        }

        $sql->commit();

        $_SESSION['changes_saved'] = $changes_saved_template;

        # ultimate - fix post on refresh
        header('Location: '.basename($_SERVER['PHP_SELF']));
        exit;
    }
    $captcha_enabled = false;
    $faucet_disabled = false;
    $page = str_replace('<:: content ::>', $admin_template, $master_template);

# check version
    $q = $sql->prepare("SELECT value FROM ".$dbtable_prefix."Settings WHERE name = ?;");
    $q->execute(array('update_last_check'));
    if ($row = $q->fetch()) {
        $update_last_check=(int)$row[0];
        if ($update_last_check<time()-120) {
            // update last check
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value=? WHERE name = ?;");
            $q->execute(array(time(), 'update_last_check'));
            // metrix
            $metrix_array = array();
            if ($faucet_settings_array['enable_metrix']=='on') {
                $metrix_array['shortlinks'] = array();
                $metrix_query = $sql->query("
                    SELECT
                        shortlink,
                        sum(users_sent) AS sum_users_sent_7d,
                        sum(users_returned) AS sum_users_returned_7d
                    FROM
                        ".$dbtable_prefix."Shortlinks
                    WHERE
                        time>".(time()-86400*7)."
                    GROUP BY shortlink;");
                while ($metrix_row = $metrix_query->fetch(PDO::FETCH_ASSOC)) {
                    $metrix_array['shortlinks'][$metrix_row['shortlink']]['snt'] = $metrix_row['sum_users_sent_7d'];
                    $metrix_array['shortlinks'][$metrix_row['shortlink']]['rtn'] = $metrix_row['sum_users_returned_7d'];
                }
            }
            // check for update
            if ($ch = curl_init()) {
                $sendp = explode(basename($_SERVER['SCRIPT_NAME']), $_SERVER['REQUEST_URI']);
                $sendp = $sendp[0];
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_URL, 'https://www.makejar.com/fb/version.php?v='.$version.'&h='.base64_encode($_SERVER['HTTP_HOST']).'&p='.base64_encode($sendp));
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:76.0) Gecko/20100101 Firefox/76.0');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($metrix_array));
                $update_data = curl_exec($ch);
                curl_close($ch);

                if (!empty($update_data)) {
                    $update_data_decoded = @json_decode($update_data, true);
                    /*
                    // Uncomment to add your own COMPATIBLE shortlink services
                    $update_data_decoded['shortlink_providers']['myshortlink1'] = array(
                        'link_reg' => 'https://example.com/',
                        'link_api' => 'https://example.com/api/',
                        'text' => 'example.com',
                        'details' => 'CPM $X.xx+<br />More details here.'
                    );
                    $update_data_decoded['shortlink_providers']['myshortlink2'] = array(
                        'link_reg' => 'https://example.com/',
                        'link_api' => 'https://example.com/api/',
                        'text' => 'example.com',
                        'details' => 'CPM $X.xx+<br />More details here.'
                    );
                    $update_data = json_encode($update_data_decoded);
                    */
                    if (is_array($update_data_decoded)) {
                        $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value=? WHERE name = ?;");
                        $q->execute(array($update_data, 'update_data'));
                    }
                }
            }
        }
    }

# show shortlinks
    $q = $sql->prepare("SELECT value FROM ".$dbtable_prefix."Settings WHERE name = ?;");
    $q->execute(array('update_data'));
    if ($row = $q->fetch()) {
        $update_data = @json_decode($row[0], true);
        if ($version<(int)$update_data['version']) {
            $oneclick_update_alert = '<div class="alert alert-info">';
            $oneclick_update_alert.= 'New Faucet In A Box <sup>Ultimate</sup> version available.<br />';
            $oneclick_update_alert.= strip_tags($update_data['version']).' changes:<br />';
            $oneclick_update_alert.= strip_tags($update_data['version_info'], '<b><br><u><i><small><span><div><a>').'<br />';
            $oneclick_update_alert.= 'Download from: <a target="_blank" href="'.strip_tags($update_data['version_link']).'">'.strip_tags($update_data['version_link']).'</a>';
            $oneclick_update_alert.= '</div>';
            $page = str_replace('<:: oneclick_update_alert ::>', $oneclick_update_alert, $page);
        }
        $shortlink_services='';
        if (!empty($update_data['shortlink_providers_details'])) {
            $shortlink_services.=strip_tags($update_data['shortlink_providers_details'], '<br><b>').'<br />';
        }
        if ((!empty($update_data['shortlink_providers']))&&(is_array($update_data['shortlink_providers']))) {
            // get current config
            $q = $sql->prepare("SELECT value FROM ".$dbtable_prefix."Settings WHERE name = ?;");
            $q->execute(array('shortlink_data'));
            if ($row = $q->fetch()) {
                $shortlink_data = @json_decode($row[0], true);
                if (!is_array($shortlink_data)) {
                    $shortlink_data = array();
                }
            }

            // grab shortlink performance data
            $shortlink_performance_array = array();
            $qsp_query = $sql->query("SELECT shortlink, count(shortlink) as count_shortlink, sum(reward) as sum_reward FROM ".$dbtable_prefix."Claimlog WHERE time>".(time()-86400)." AND address!='' GROUP BY shortlink;");
            while ($qsp_row = $qsp_query->fetch(PDO::FETCH_ASSOC)) {
                $shortlink_performance_array[$qsp_row['shortlink']]['count_shortlink'] = $qsp_row['count_shortlink'];
                $shortlink_performance_array[$qsp_row['shortlink']]['sum_reward'] = $qsp_row['sum_reward'];
            }
            $shortlink_quality_query = $sql->query("SELECT shortlink, sum(users_sent) AS sum_users_sent_24h, sum(users_returned) AS sum_users_returned_24h FROM ".$dbtable_prefix."Shortlinks WHERE time>".(time()-86400)." GROUP BY shortlink;");
            while ($shortlink_quality_row = $shortlink_quality_query->fetch(PDO::FETCH_ASSOC)) {
                $shortlink_performance_array[$shortlink_quality_row['shortlink']]['sum_users_sent_24h'] = $shortlink_quality_row['sum_users_sent_24h'];
                $shortlink_performance_array[$shortlink_quality_row['shortlink']]['sum_users_returned_24h'] = $shortlink_quality_row['sum_users_returned_24h'];
            }
            $shortlink_quality_query = $sql->query("SELECT shortlink, sum(users_sent) AS sum_users_sent_7d, sum(users_returned) AS sum_users_returned_7d FROM ".$dbtable_prefix."Shortlinks WHERE time>".(time()-86400*7)." GROUP BY shortlink;");
            while ($shortlink_quality_row = $shortlink_quality_query->fetch(PDO::FETCH_ASSOC)) {
                $shortlink_performance_array[$shortlink_quality_row['shortlink']]['sum_users_sent_7d'] = $shortlink_quality_row['sum_users_sent_7d'];
                $shortlink_performance_array[$shortlink_quality_row['shortlink']]['sum_users_returned_7d'] = $shortlink_quality_row['sum_users_returned_7d'];
            }

            // merge shortlink_providers and shortlink_data
            $shortlink_order=0;
            foreach ($update_data['shortlink_providers'] as $shortlink_key => $shortlink_value) {
                if (!empty($shortlink_data[$shortlink_key]['apikey'])) {
                    if (isset($shortlink_data[$shortlink_key])) {
                        $update_data['shortlink_providers'][$shortlink_key]=array_merge($update_data['shortlink_providers'][$shortlink_key], $shortlink_data[$shortlink_key]);
                    }
                    if (isset($shortlink_performance_array[$shortlink_key])) {
                        $update_data['shortlink_providers'][$shortlink_key]=array_merge($update_data['shortlink_providers'][$shortlink_key], $shortlink_performance_array[$shortlink_key]);
                    }
                }
                $shortlink_order++;
                $update_data['shortlink_providers'][$shortlink_key]['order']=$shortlink_order;
                if (!isset($update_data['shortlink_providers'][$shortlink_key]['priority'])) {
                    $update_data['shortlink_providers'][$shortlink_key]['priority']=0;
                }
                if (!isset($update_data['shortlink_providers'][$shortlink_key]['enabled'])) {
                    $update_data['shortlink_providers'][$shortlink_key]['enabled']=0;
                }
                if (!isset($update_data['shortlink_providers'][$shortlink_key]['limit'])) {
                    $update_data['shortlink_providers'][$shortlink_key]['limit']=0;
                }
                if (!isset($update_data['shortlink_providers'][$shortlink_key]['apikey'])) {
                    $update_data['shortlink_providers'][$shortlink_key]['apikey']='';
                }
            }

            // sort by priority
            uasort($update_data['shortlink_providers'], 'sort_shortlink_data');

            foreach ($update_data['shortlink_providers'] as $shortlink_key => $shortlink_value) {
                if (empty($shortlink_value['details'])) {
                    $shortlink_value['details'] = '';
                }
                if ((!empty($shortlink_value['min_version'])) && ($shortlink_value['min_version']>$version)) {
                    continue;
                }
                $html_checked = '';
                $html_apikey = '';
                $html_priority = 0;
                $html_limit = 0;
                $html_performance = '';
                if (!empty($shortlink_value['apikey'])) {
                    if ($shortlink_value['enabled']==1) {
                        $html_checked = ' checked="checked"';
                    }
                    $html_apikey = $shortlink_value['apikey'];
                }
                if (!empty($shortlink_value['priority'])) {
                    $html_priority = (int)$shortlink_value['priority'];
                }
                if (!empty($shortlink_value['limit'])) {
                    $html_limit = (int)$shortlink_value['limit'];
                }

                $html_performance = '';
                if (!empty($shortlink_value['count_shortlink'])) {
                    $html_performance.= '<span title="24 hours stats">&#x1F555;</span> ';
                    $html_performance.= '<span title="Claimed users count">&#x1F464;</span> '.$shortlink_value['count_shortlink'] . ' &nbsp; ';
                    $html_performance.= '<span title="Estimated direct rewards">&#x1F4B4;</span> '.$shortlink_value['sum_reward'];
                    if ($currency == 'DOGE') {
                        $html_performance.= ' '.$currency;
                    } else {
                        $html_performance.= ' satothi';
                    }
                    $html_performance.= ' &nbsp; ';
                }
                // 7d
                if (isset($shortlink_value['sum_users_sent_7d'])) {
                    $html_performance.= '<br /><span title="Local 7 days stats">&#x1F5D3;</span> ';
                    $html_performance.= '<span title="Users sent">&#x1F517;</span> '.$shortlink_value['sum_users_sent_7d'].' &nbsp; ';
                    $html_performance.= '<span title="Users returned">&#x1F44D;</span> '.$shortlink_value['sum_users_returned_7d'].' &nbsp; ';
                    // show percentage
                    if ($shortlink_value['sum_users_sent_7d']>0) {
                        $html_performance.= '<span title="PTR">&#x2696;</span> '.round($shortlink_value['sum_users_returned_7d']*100/$shortlink_value['sum_users_sent_7d'], 1).'%';
                    } else {
                        $html_performance.= '<span title="PTR">&#x2696;</span> N/A';
                    }
                }

                $css = '';
                if (strpos($shortlink_value['details'], 'title="Scam">SCAM</b>')!==false) {
                    $css = 'border-color: #EE9999;';
                }
                $shortlink_services.= '<div class="shortlink-data" style="border: 1px solid #DDDDDD; border-radius: 5px; margin-bottom: 5px; '.$css.'">';
                $shortlink_services.= '  <div>';
                $shortlink_services.= '    <div class="col-lg-2" style="margin: 2px 0px;">';
                $shortlink_services.= '      <a target="_blank" href="'.strip_tags($shortlink_value['link_reg']).'" style="overflow: hidden; white-space: nowrap; font-weight: bold; text-overflow: ellipsis; display: block; font-size: 16px; line-height: 35px;">'.strip_tags($shortlink_value['text']).'</a>';
                $shortlink_services.= '    </div>';
                $shortlink_services.= '    <div class="col-lg-1" style="margin: 2px 0px;">';
                //$shortlink_services.= '      <div class="input-group">';
                $shortlink_services.= '        <input type="checkbox" data-width="87" data-toggle="toggle" data-on="Enabled" data-off="Disabled" data-onstyle="success" data-offstyle="danger" name="shortlinks_enabled['.$shortlink_key.']" value="1"'.$html_checked.'>';
                //$shortlink_services.= '      </div>';
                $shortlink_services.= '    </div>';
                $shortlink_services.= '    <div class="col-lg-2" style="margin: 2px 0px;">';
                $shortlink_services.= '      <div class="input-group">';
                $shortlink_services.= '      <span class="input-group-addon">Priority</span>';
                $shortlink_services.= '      <input type="text" class="form-control" placeholder="Priority" title="Priority" name="shortlinks_priority['.$shortlink_key.']" value="'.$html_priority.'" size="3" maxlength="3" />';
                $shortlink_services.= '      </div>';
                $shortlink_services.= '    </div>';
                $shortlink_services.= '    <div class="col-lg-2" style="margin: 2px 0px;">';
                $shortlink_services.= '      <div class="input-group">';
                $shortlink_services.= '      <span class="input-group-addon">Limit</span>';
                $shortlink_services.= '      <input type="text" class="form-control" placeholder="Limit" title="Limit" name="shortlinks_limit['.$shortlink_key.']" value="'.$html_limit.'" size="3" maxlength="3" />';
                $shortlink_services.= '      </div>';
                $shortlink_services.= '    </div>';
                $shortlink_services.= '    <div class="col-lg-5" style="margin: 2px 0px;">';
                $shortlink_services.= '      <div class="input-group">';
                $shortlink_services.= '      <span class="input-group-addon">API Key</span>';
                $shortlink_services.= '      <input type="text" class="form-control short-api-key" placeholder="API Key" title="API Key" name="shortlinks_apikey['.$shortlink_key.']" value="'.$html_apikey.'" maxlength="64" />';
                $shortlink_services.= '      </div>';
                $shortlink_services.= '    </div>';
                $shortlink_services.= '  </div>';
                //$shortlink_services.= '  </div>';
                //if (!isset($shortlink_value['days'])) {
                //    $shortlink_value['days'] = 7;
                //}
                //<input type="hidden" name="shortlinks_apilink['.$shortlink_key.']" value="'.strip_tags($shortlink_value['link_api']).'"><input type="hidden" name="shortlinks_days['.$shortlink_key.']" value="'.strip_tags($shortlink_value['days']).'">
                $shortlink_services.= '  <div>';
                if (!empty($html_performance)) {
                    $shortlink_services.= '    <div class="col-lg-12" style="margin: 5px 0px;">';
                    $shortlink_services.= $html_performance.'  <br />';
                    $shortlink_services.= '    </div>';
                }
                $shortlink_services.= '    <div class="col-lg-12">';
                $shortlink_services.= strip_tags($shortlink_value['details'], '<b><br><u><i><small><span><div><a>');
                $shortlink_services.= '    </div>';
                $shortlink_services.= '  </div>';
                $shortlink_services.= '  <div style="clear: both;"></div>';
                $shortlink_services.= '</div>';
            }
            $shortlink_services.= ''."\n";
        }

        $shortlink_services.= '<script type="text/javascript">'."\n";
        $shortlink_services.= 'function setShortlinkChecked() {'."\n";

        $shortlink_services.= '  $(\'.shortlink-data input:checkbox\').each(function() {'."\n";
        $shortlink_services.= '    if($(this).is(\':checked\')) {'."\n";
        $shortlink_services.= '      $(this).parent().parent().parent().parent().css(\'background-color\', \'#DDFFDD\');'."\n";
        $shortlink_services.= '    } else {'."\n";
        $shortlink_services.= '      $(this).parent().parent().parent().parent().css(\'background-color\', \'#FFFFFF\');'."\n";
        $shortlink_services.= '    }'."\n";
        $shortlink_services.= '  })'."\n";

        $shortlink_services.= '}'."\n";

        $shortlink_services.= '$(function(){'."\n";
        $shortlink_services.= '  setShortlinkChecked();'."\n";
        $shortlink_services.= '  $(\'.shortlink-data input:checkbox\').click(function(){'."\n";
        $shortlink_services.= '    setShortlinkChecked();'."\n";
        $shortlink_services.= '  });'."\n";

        $shortlink_services.= '});'."\n";
        $shortlink_services.= '</script>'."\n";

        $page = str_replace('<:: shortlink_services ::>', $shortlink_services, $page);
    }

# AntiBotLinks START
    $antibotlinks->admin_config();
# AntiBotLinks END
# NH START
    $nh->admin_config();
# NH END

# ADMINLOG START
    $adminlog->admin_config();
# ADMINLOG END

    $query = $sql->query("SELECT name, value FROM ".$dbtable_prefix."Settings");
    while ($row = $query->fetch()) {
        if ($row[0] == 'template') {
            if (file_exists("templates/{$row[1]}/index.php")) {
                $current_template = $row[1];
            } else {
                $templates = glob("templates/*");
                if ($templates)
                $current_template = substr($templates[0], strlen('templates/'));
                else
                die(str_replace("<:: content ::>", "<div class='alert alert-danger' role='alert'>No templates found! Please reinstall your faucet.</div>", $master_template));
            }
        } else {
            if (in_array($row[0], ['reward_in_USD', 'block_adblock', 'iframe_sameorigin_only', 'nastyhosts_enabled', 'iphub_enabled', 'proxycheck_enabled', 'shortlink_required', 'reverse_proxy', 'disable_refcheck', 'show_recent_payouts', 'disallow_www', 'disallow_email_claiming', 'enable_admin_captcha', 'show_referred_users'])) {
                $row[1] = $row[1] == "on" ? "checked" : "";
            }
            if (in_array($row[0], ["apikey", "rewards"]) && empty($row[1])) {
                $faucet_disabled = true;
            }
            if (strpos($row[0], "recaptcha_") !== false ||
                    strpos($row[0], "solvemedia_") !== false ||
                    strpos($row[0], "raincaptcha_") !== false ||
                    strpos($row[0], "hcaptcha_") !== false
                    ) {
                if (!empty($row[1])) {
                    $captcha_enabled = true;
                }
            }
            // 2fa
            if ($row[0] == 'code_2fa') {
                $html_data_2fa = '';
                if (empty($row[1])) {
                    $row[1] = $tfa->createSecret(160);
                    $page = str_replace('<:: enable_2fa ::>', '', $page);
                    $html_data_2fa.= 'Scan the code:<br />';
                    $html_data_2fa.= '<img src="' . $tfa->getQRCodeImageAsDataUri($_SERVER['HTTP_HOST'], $row[1]) . '" alt="" /><br />';
                    $html_data_2fa.= 'Or enter:<br /><code>' . $row[1] . '</code><br />';
                    $html_data_2fa.= '<input type="hidden" name="code_2fa" value="<:: code_2fa ::>" />';
                    $html_data_2fa.= '<label class="control-label">Verify ( * * * * * * ):</label><br />';
                    $html_data_2fa.= '<input type="text" name="code_2fa_verify" value="" minlength="6" maxlength="6" class="form-control" autocomplete="off" inputmode="numeric" pattern="[0-9]*" />';
                } else {
                    $page = str_replace('<:: enable_2fa ::>', 'checked', $page);
                }
                $page = str_replace('<:: html_data_2fa ::>', $html_data_2fa, $page);
            }
            $page = str_replace("<:: {$row[0]} ::>", $row[1], $page);
        }
    }

    $faucet_disabled_message = $faucet_disabled_template;
    if (!$faucet_disabled && $captcha_enabled) {
        $faucet_disabled_message = "";
    }
    $page = str_replace("<:: faucet_disabled ::>", $faucet_disabled_message, $page);


    $templates = '';
    foreach (glob("templates/*") as $template) {
        $template = basename($template);
        if ($template == $current_template) {
            $templates .= "<option selected>$template</option>";
        } else {
            $templates .= "<option>$template</option>";
        }
    }
    $page = str_replace('<:: templates ::>', $templates, $page);
    $page = str_replace('<:: current_template ::>', $current_template, $page);


    if (file_exists("templates/{$current_template}/setup.php")) {
        require_once("templates/{$current_template}/setup.php");
        $page = str_replace('<:: template_options ::>', getTemplateOptions($sql, $current_template), $page);
    } else {
        $page = str_replace('<:: template_options ::>', '<p>No template defined options available.</p>', $page);
    }

    $template_string = file_get_contents("templates/{$current_template}/index.php");
    $template_updates_info = '';
    foreach ($template_updates as $update) {
        if (!preg_match($update["test"], $template_string)) {
            $template_updates_info .= str_replace("<:: message ::>", $update["message"], $template_update_template);
        }
    }
    if (!empty($template_updates_info)) {
        $template_updates_info = str_replace("<:: template_updates ::>", $template_updates_info, $template_updates_template);
    }

    $q = $sql->query("SELECT name, html FROM ".$dbtable_prefix."Pages ORDER BY id");
    $pages = '';
    $pages_nav = '';
    $i = 1;
    while ($userpage = $q->fetch()) {
        $html = htmlspecialchars($userpage['html']);
        $name = htmlspecialchars($userpage['name']);
        $pages .= str_replace(array('<:: i ::>', '<:: page_name ::>', '<:: html ::>'),
        array($i, $name, $html), $page_form_template);
        $pages_nav .= str_replace('<:: i ::>', $i, $page_nav_template);
        ++$i;
    }
    $page = str_replace('<:: pages ::>', $pages, $page);
    $page = str_replace('<:: pages_nav ::>', $pages_nav, $page);
    $currencies_select = "";
    foreach ($currencies as $c) {
        if ($currency == $c)
        $currencies_select .= "<option value='$c' selected>$c</option>";
        else
        $currencies_select .= "<option value='$c'>$c</option>";
    }
    $page = str_replace('<:: currency ::>', $currency, $page);
    $page = str_replace('<:: currencies ::>', $currencies_select, $page);


    if ($invalid_key)
    $page = str_replace('<:: invalid_key ::>', $invalid_key_error_template, $page);
    else
    $page = str_replace('<:: invalid_key ::>', '', $page);

    $services = "";
    foreach($fb->getServices() as $s => $name) {
        if($s == $service) {
            $services .= "<option value='$s' selected>$name</option>";
        } else {
            $services .= "<option value='$s'>$name</option>";
        }
    }
    $page = str_replace('<:: services ::>', $services, $page);

    $page = str_replace('<:: page_form_template ::>',
    json_encode($page_form_template),
    $page);
    $page = str_replace('<:: page_nav_template ::>',
    json_encode($page_nav_template),
    $page);

    $new_files = [];
    foreach (new RecursiveIteratorIterator (new RecursiveDirectoryIterator ('templates')) as $file) {
        $file = $file->getPathname();
        if (substr($file, -4) == ".new") {
            $new_files[] = $file;
        }
    }

    if ($new_files) {
        $new_files = implode("\n", array_map(function($v) { return "<li>$v</li>"; }, $new_files));
        $new_files = str_replace("<:: new_files ::>", $new_files, $new_files_template);
    } else {
        $new_files = "";
    }
    $page = str_replace("<:: new_files ::>", $new_files, $page);
    $page = str_replace('<:: current_version ::>', $version, $page);

    $page = str_replace('<:: detected_reverse_proxy_name ::>', detectRevProxyProvider(), $page);

    $page = str_replace('<:: password_error ::>', $password_error, $page);
    $page = str_replace('<:: wrong_2fa_error ::>', $wrong_2fa_error, $page);
    $page = str_replace('<:: connection_error ::>', $connection_error, $page);
    $page = str_replace('<:: curl_warning ::>', $curl_warning, $page);
    $page = str_replace('<:: send_coins_message ::>', $send_coins_message, $page);
    $page = str_replace('<:: missing_configs ::>', $missing_configs_info, $page);
    $page = str_replace('<:: template_updates ::>', $template_updates_info, $page);
    $page = str_replace('<:: changes_saved ::>', $changes_saved, $page);
    $page = str_replace('<:: oneclick_update_alert ::>', $oneclick_update_alert, $page);
    $page = str_replace("<:: csrftoken ::>", get_csrf_token(), $page);
    $page = str_replace("<:: supported_services ::>", json_encode(Service::$services), $page);
    $page = str_replace("<:: fiab_version ::>", "r".$version, $page);

    die($page);
} else {
    // requested admin page without session
    $page = str_replace('<:: content ::>', $admin_login_template, $master_template);
    $page = str_replace("<:: csrftoken ::>", get_csrf_token(), $page);
    die($page);
}
