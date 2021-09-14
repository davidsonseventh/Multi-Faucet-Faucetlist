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

$unique_request_id = mt_rand(10000, 99999);

// ultimate - not needed
if (!empty($_POST['mmc'])) {
    exit;
}

require_once("script/common.php");

if (array_key_exists("p", $_GET) && in_array($_GET["p"], ["admin", "password-reset"])) {
    header("Location: admin.php?p={$_GET["p"]}");
    die("Please wait...");
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    if (isset($_SESSION['address_input_name'.$session_prefix])) {
        if (isset($_SESSION['just_claimed_successfully'.$session_prefix])) {
            header('Location: .');
            exit;
        }
    }
}
unset($_SESSION['just_claimed_successfully'.$session_prefix]);

// Check protocol
if (is_ssl()) {
    $protocol = "https://";
} else {
    $protocol = "http://";
}

// disallow www
if ((isset($faucet_settings_array['disallow_www'])) && ($faucet_settings_array['disallow_www']!='')) {
    if (substr($_SERVER['HTTP_HOST'], 0, 4) == 'www.') {
        header('Location: '.$protocol.'://' . substr($_SERVER['HTTP_HOST'], 4) . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
}

header('Referrer-Policy: unsafe-url');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    # ADMINLOG START
    require_once('libs/adminlog.php');
    $adminlog=new adminlog();
    # ADMINLOG END
}

// data array
$data = array(
    "paid" => false,
    "disable_admin_panel" => $disable_admin_panel,
    "address" => "",
    "captcha_valid" => true, //for people who won't update templates
    "captcha" => false,
    "enabled" => false,
    "error" => false,
    "address_eligible" => true,
    "reflink" => $protocol.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'], '?').'?r='
);

// Get settings from DB
foreach ($faucet_settings_array as $faucet_settings_name=>$faucet_settings_value) {
    if ($faucet_settings_name == 'safety_limits_end_time') {
        $time = strtotime($faucet_settings_value);
        if ($time !== false && $time < time()) {
            $faucet_settings_value = '';
        }
    }
    $data[$faucet_settings_name] = $faucet_settings_value;
}

// Set unit name
$data['unit'] = 'satoshi';
if ($data['currency'] == 'DOGE') {
    $data['unit'] = 'DOGE';
}

// Get address
if (isset($_SESSION['address_input_name'.$session_prefix]) && array_key_exists($_SESSION['address_input_name'.$session_prefix], $_POST)) {
    $_POST["address"] = textSanitize(trim($_POST[$_SESSION['address_input_name'.$session_prefix]]));
} else {
    if ($_SERVER['REQUEST_METHOD'] == "POST") {
        if (isset($_SESSION['address_input_name'.$session_prefix])) {
            trigger_error("Post request, but invalid address input name.");
        } else {
            trigger_error("Post request, but session is invalid.");
        }
    }
    unset($_POST["address"]);
}

// Generate ref link
if (array_key_exists('address', $_POST)) {
    $data["reflink"] .= trim($_POST['address']);
    $data["address"] = trim($_POST['address']);
} else if (array_key_exists('address_'.$data['currency'], $_COOKIE)) {
    $data["reflink"] .= textSanitize(trim($_COOKIE['address_'.$data['currency']]));
    $data["address"] = textSanitize(trim($_COOKIE['address_'.$data['currency']]));
} else if (array_key_exists('address', $_COOKIE)) {
    $data["reflink"] .= textSanitize(trim($_COOKIE['address']));
    $data["address"] = textSanitize(trim($_COOKIE['address']));
} else {
    $data["reflink"] .= 'Your_Address';
}

// Get template
$template = $faucet_settings_array['template'];
if (!file_exists("templates/{$template}/index.php")) {
    $templates = glob("templates/*");
    if ($templates) {
        $template = substr($templates[0], strlen("templates/"));
    } else {
        die(str_replace('<:: content ::>', "<div class='alert alert-danger' role='alert'>No templates found!</div>", $master_template));
    }
}

// Update balance
if (time() - $data['last_balance_check'] > 60*30) {
    $fb = new Service($data['service'], $data['apikey'], $data['currency'], $data['usertoken'], $connection_options);
    $ret = $fb->getBalance();
    if (!empty($ret)) {
        if (array_key_exists('balance', $ret)) {
            if ($data['currency'] != 'DOGE') {
                $balance = $ret['balance'];
            } else {
                if (isset($ret['balance_bitcoin'])) {
                    $balance = $ret['balance_bitcoin'];
                } else {
                    $balance = $ret['balance']/100000000;
                }
            }
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE name = ?");
            $q->execute(array(time(), 'last_balance_check'));
            $q->execute(array($balance, 'balance'));
            $data['balance'] = $balance;
            $data['last_balance_check'] = time();
        }
        if ((!empty($ret['status'])) && (($ret['status']==403) || ($ret['status']==452))) {
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE name = ?");
            $q->execute(array('', 'apikey'));
        }
    }
}

#MuliCaptcha: Firstly check chosen captcha system
$captcha = array('available' => array(), 'selected' => null);
if ($data['recaptcha_public_key'] && $data['recaptcha_private_key']) {
    $captcha['available'][] = 'reCaptcha';
}
if ($data['solvemedia_challenge_key'] && $data['solvemedia_verification_key'] && $data['solvemedia_auth_key']) {
    $captcha['available'][] = 'SolveMedia';
}
if ($data['hcaptcha_site_key'] && $data['hcaptcha_secret_key']) {
    $captcha['available'][] = 'hCaptcha';
}
if ($data['raincaptcha_public_key'] && $data['raincaptcha_secret_key']) {
    $captcha['available'][] = 'RainCaptcha';
}


#MuliCaptcha: Secondly check if user switched captcha or choose default
if (array_key_exists('cc', $_GET) && in_array($_GET['cc'], $captcha['available'])) {
    $captcha['selected'] = $captcha['available'][array_search($_GET['cc'], $captcha['available'])];
    $_SESSION['selected_captcha'.$session_prefix] = $captcha['selected'];
} elseif (isset($_SESSION['selected_captcha'.$session_prefix]) && in_array($_SESSION['selected_captcha'.$session_prefix], $captcha['available'])) {
    $captcha['selected'] = $_SESSION['selected_captcha'.$session_prefix];
} else {
    if ($captcha['available']) {
        $captcha['selected'] = $captcha['available'][0];
    }
    if (in_array($data['default_captcha'], $captcha['available'])) {
        $captcha['selected'] = $data['default_captcha'];
    } else if ($captcha['available']) {
        $captcha['selected'] = $captcha['available'][0];
    }
}

#MuliCaptcha: And finally handle chosen captcha system
# -> checkCaptcha()
switch ($captcha['selected']) {
    case 'SolveMedia':
        require_once('libs/solvemedialib.php');
        $data['captcha'] = solvemedia_get_html($data['solvemedia_challenge_key'], null, is_ssl());
    break;
    case 'reCaptcha':
        #reCaptcha template
        $recaptcha_template = '
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <div class="g-recaptcha" data-sitekey="<:: your_site_key ::>"></div>
        <noscript>
          <div style="width: 302px; height: 352px;">
            <div style="width: 302px; height: 352px; position: relative;">
              <div style="width: 302px; height: 352px; position: absolute;">
                <iframe src="https://www.google.com/recaptcha/api/fallback?k=<:: your_site_key ::>"
                        frameborder="0" scrolling="no"
                        style="width: 302px; height:352px; border-style: none;">
                </iframe>
              </div>
              <div style="width: 250px; height: 80px; position: absolute; border-style: none;
                          bottom: 21px; left: 25px; margin: 0px; padding: 0px; right: 25px;">
                <textarea id="g-recaptcha-response" name="g-recaptcha-response"
                          class="g-recaptcha-response"
                          style="width: 250px; height: 80px; border: 1px solid #c1c1c1;
                                 margin: 0px; padding: 0px; resize: none;" value="">
                </textarea>
              </div>
            </div>
          </div>
        </noscript>
        ';
        $data['captcha'] = str_replace('<:: your_site_key ::>', $data['recaptcha_public_key'], $recaptcha_template);
    break;
    case 'hCaptcha':
        $data['captcha'] = '
<script src="https://hcaptcha.com/1/api.js" async defer></script>
<div class="h-captcha" data-sitekey="'.$faucet_settings_array['hcaptcha_site_key'].'"></div>';
    break;
    case 'RainCaptcha':
      $data['captcha'] = '<div style="text-align:center;"><script src="https://raincaptcha.com/base.js" type="text/javascript"></script><div id="rain-captcha" data-key="'.$data['raincaptcha_public_key'].'"></div></div>';
    break;
}

$data['captcha_info'] = $captcha;

# AntiBotLinks START
require_once('libs/antibotlinks.php');
$antibotlinks = new antibotlinks(true, 'ttf,otf');// true if GD is on on the server, false is less secure, also you can enable ttf and/or otf
if (array_key_exists('address', $_POST)) {
  if (!$antibotlinks->check()) {
    // suggested (it is way better to have more word universes than more links)
    // 4 links should be enough to discourage (and make easy to detect) brute-force
    $antibotlinks->generate(4, true);// number of links once they fail, the second param MUST BE true
  }
} else {
  // suggested (it is way better to have more word universes than more links)
  // 4 links should be enough to discourage (and make easy to detect) brute-force
  $antibotlinks->generate(4);// initial number of links
}
# AntiBotLinks END


// Check if faucet's enabled
if ($data['captcha'] && $data['apikey'] && $data['rewards']) {
    $data['enabled'] = true;
}

// check if IP eligible
$data["eligible"] = checkTimeForIP(getIP(), $time_left);
if (($data["eligible"]) && (!empty($data["address"]))) {
    $data["eligible"] = checkTimeForAddress(trim($data["address"]), $time_left);
}
$data['time_left'] = $time_left." minutes";

// get USD
$usdRate = 1;
if (!empty($faucet_settings_array['reward_in_USD'])) {
    $usdRate = 1/getUSDrate();
    if ($faucet_settings_array['currency']!='DOGE') {
        $usdRate = $usdRate / 0.00000001;
    }
}

// Rewards
$rewards = explode(',', $data['rewards']);
$total_weight = 0;
$nrewards = array();
foreach ($rewards as $reward) {
    $reward = explode('*', trim($reward));
    if (count($reward) < 2) {
        $reward[1] = $reward[0];
        $reward[0] = 1;
    }
    // apply USD rate to rewards
    if (strpos($reward[1], '-')!==false) {
        $reward_rates = explode('-', $reward[1]);
        $reward[1] = roundRate($reward_rates[0] * $usdRate) . '-' . roundRate($reward_rates[1] * $usdRate);
    } else {
        $reward[1] = roundRate($reward[1] * $usdRate);
    }
    $total_weight += round($reward[0], 2);
    $nrewards[] = $reward;
}
$rewards = $nrewards;
if (count($rewards) > 1) {
    $possible_rewards = array();
    foreach ($rewards as $r) {
        $chance_per = 100 * $r[0]/$total_weight;
        if ($chance_per < 0.01) {
            $chance_per = '< 0.01%';
        } else {
            $chance_per = round(floor($chance_per*100)/100, 2).'%';
        }
        $possible_rewards[] = $r[1].' ('.$chance_per.')';
    }
} else {
    $possible_rewards = array($rewards[0][1]);
}



if (array_key_exists('address', $_POST) && $data['enabled'] && $data['eligible']) {

    $address = trim($_POST["address"]);

    if(empty($data['address'])) {
        $data['address'] = $address;
    }

    $error = getClaimError($address);
    if ($error) {
        $data["error"] = "<div class=\"alert alert-danger\">{$error}</div>";
        $adminlog->admin_set_message($error);
    } else {
        // Rand amount
        $r = mt_rand()/mt_getrandmax();
        $t = 0;
        foreach ($rewards as $reward) {
            $t += intval($reward[0])/$total_weight;
            if ($t > $r) {
                break;
            }
        }
        if (strpos($reward[1], '-') !== false) {
            $reward_range = explode('-', $reward[1]);
            $from = floatval($reward_range[0]);
            $to = floatval($reward_range[1]);
            if ($faucet_settings_array['currency']=='DOGE') {
                $reward_rounder = 0.00000001;
                if ($to>10) {
                    $reward_rounder = 0.00001;
                }
                $reward = mt_rand($from/$reward_rounder, $to/$reward_rounder);
                $reward = $reward * $reward_rounder;
            } else {
                $reward = mt_rand($from, $to);
            }
        } else {
            $reward = floatval($reward[1]);
        }

        if ((isset($_SESSION['shortlink'.$session_prefix]['solved'])) && ($_SESSION['shortlink'.$session_prefix]['solved']==true)) {
            if (strpos($faucet_settings_array['shortlink_payout'], '%')!==false) {
                $shortlink_reward = (int)$faucet_settings_array['shortlink_payout'];
                $reward = $reward + $reward * ($shortlink_reward / 100);
                if ($faucet_settings_array['currency']!='DOGE') {
                  $reward = round($reward);
                }
            } else {
                 $reward = $reward + roundRate($faucet_settings_array['shortlink_payout'] * $usdRate);
            }
        }

        $fb = new Service($data['service'], $data['apikey'], $data['currency'], $data['usertoken'], $connection_options);
        $ret = $fb->send($address, $reward, getIP());
        if ((!empty($ret['status'])) && (($ret['status']==403) || ($ret['status']==452))) {
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE name = ?");
            $q->execute(array('', 'apikey'));
            $q->execute(array('0', 'balance'));
        }

        // expresscrypto
        if ($faucet_settings_array['service']=='expresscrypto') {
            if ((strpos($ret['html'], 'This address is not linked to any account in ExpressCrypto.io')!==false) || (stripos($address, 'EC-')===false)) {
                if (empty($expresscrypto_ref_url)) {
                    $expresscrypto_ref_url = 'https://expresscrypto.io/signup?referral=246990';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in ExpressCrypto.io. <a href="https://expresscrypto.io/" onmousedown="$(this).attr(\'href\', \''.$expresscrypto_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // kswallet
        if ($faucet_settings_array['service']=='kswallet') {
            if (strpos($ret['html'], 'Requested recieve address')!==false) {
                if (empty($kswallet_ref_url)) {
                    $kswallet_ref_url = 'https://www.kswallet.net/register?r=makejar.com';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in ksWallet.net. <a href="https://kswallet.net/" onmousedown="$(this).attr(\'href\', \''.$kswallet_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // cedsonhub
        if ($faucet_settings_array['service']=='cedsonhub') {
            if (strpos($ret['html'], 'Username does not belong to CedsonHub account')!==false) {
                if (empty($cedsonhub_ref_url)) {
                    $cedsonhub_ref_url = 'https://cedsonhub.site/#/referral?r=MakeJar';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in CedsonHub.site. <a href="https://cedsonhub.site/" onmousedown="$(this).attr(\'href\', \''.$cedsonhub_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // faucetpay
        if ($faucet_settings_array['service']=='faucetpay') {
            if (strpos($ret['html'], 'The address does not belong to any user.')!==false) {
                if (empty($faucetpay_ref_url)) {
                    $faucetpay_ref_url = 'https://faucetpay.io/?r=366233';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in FaucetPay.io. <a href="https://faucetpay.io/" onmousedown="$(this).attr(\'href\', \''.$faucetpay_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // microwallet
        if ($faucet_settings_array['service']=='microwallet') {
            if (strpos($ret['html'], 'does not belong to an account on')!==false) {
                if (empty($microwallet_ref_url)) {
                    $microwallet_ref_url = 'https://www.microwallet.co/ref/MakeJar';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in MicroWallet.co. <a href="https://microwallet.co/" onmousedown="$(this).attr(\'href\', \''.$microwallet_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // walcrypt
        if ($faucet_settings_array['service']=='walcrypt') {
            if (strpos($ret['html'], 'Address does not belong to good currency or an account')!==false) {
                if (empty($walcrypt_ref_url)) {
                    $walcrypt_ref_url = 'https://www.walcrypt.com/register?ref=1174';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in WalCrypt.com. <a href="https://walcrypt.com/" onmousedown="$(this).attr(\'href\', \''.$walcrypt_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }
        // microcoinpay
        if ($faucet_settings_array['service']=='microcoinpay') {
            if (strpos($ret['html'], 'Before you can receive payments at')!==false) {
                if (empty($microcoinpay_ref_url)) {
                    $microcoinpay_ref_url = 'https://microcoinpay.com/r/319';
                }
                $ret['html'] = '<div class="alert alert-danger">This address is not linked to any account in MicroCoinPay.com. <a href="https://microcoinpay.com/" onmousedown="$(this).attr(\'href\', \''.$microcoinpay_ref_url.'\');" target="_blank">Make an account</a>.</div>';
            }
        }

        $user_hash_claim = '';
        if (!empty($ret['user_hash'])) {
            $user_hash_claim = $ret['user_hash'];
        }
        if (($data['unit'] == 'satoshi') || (!isset($ret['html_coin']))) {
            $ret_msg = '<a href="#" class="address">'.htmlspecialchars(trim($_POST['address'])).'</a>'."\n".(empty($ret['user_hash'])?'':$ret['user_hash']."\n").strip_tags($ret['html']);
        } else {
            $ret_msg = '<a href="#" class="address">'.htmlspecialchars(trim($_POST['address'])).'</a>'."\n".(empty($ret['user_hash'])?'':$ret['user_hash']."\n").strip_tags($ret['html_coin']);
        }

        $balance_update=-1;
        if ($ret['success']) {
            $_SESSION['just_claimed_successfully'.$session_prefix] = true;
            // add to the claim log
            $shortlink_service_used = '';
            $shortlink_service_used_url = '';
            if (((isset($_SESSION['shortlink'.$session_prefix]['solved'])) && ($_SESSION['shortlink'.$session_prefix]['solved']==true)) && (!empty($_SESSION['shortlink'.$session_prefix]['used']))) {
                $shortlink_service_used = $_SESSION['shortlink'.$session_prefix]['used'];
                $shortlink_service_used_url = $_SESSION['shortlink'.$session_prefix]['used_url'];
            }
            unset($_SESSION['shortlink'.$session_prefix]);
            $sql->exec("DELETE FROM ".$dbtable_prefix."Claimlog WHERE time<".(time()-86400).";");
            $q = $sql->prepare("INSERT INTO ".$dbtable_prefix."Claimlog
                                SET
                                    `address` = ?,
                                    `ip` = ?,
                                    `time` = ?,
                                    `shortlink` = ?,
                                    `reward` = ?
                                    ;");
            $claimlog_reward = $reward;
            if ($reward<1) {
                $claimlog_reward = number_format($reward, 8, '.', '');
            }
            $q->execute(array(trim($_POST['address']), getIP(), time(), $shortlink_service_used, $claimlog_reward));
            if ($dbtable_shortlink_pool_prefix != $dbtable_prefix) {
                $sql->exec("DELETE FROM ".$dbtable_shortlink_pool_prefix."Claimlog WHERE time<".(time()-86400).";");
                $q = $sql->prepare("INSERT INTO ".$dbtable_shortlink_pool_prefix."Claimlog
                                    SET
                                        `address` = ?,
                                        `ip` = ?,
                                        `time` = ?,
                                        `shortlink` = ?,
                                        `reward` = ?
                                        ;");
                $q->execute(array('', getIP(), time(), $shortlink_service_used, 0));
            }

            // set the cookie
            setcookie('address_'.$data['currency'], trim($_POST['address']), time() + 60*60*24*60);
            if (!empty($ret['balance'])) {
                if ($data['unit'] == 'satoshi') {
                    $balance_update = $ret['balance'];
                } else {
                    if (isset($ret['balance_bitcoin'])) {
                        $balance_update = $ret['balance_bitcoin'];
                    } else {
                        $balance_update = $ret['balance']/100000000;
                    }
                }
            }

            if (!empty($faucet_settings_array['safety_limits_end_time'])) {
                $sql->exec("UPDATE ".$dbtable_prefix."Settings SET value = '' WHERE `name` = 'safety_limits_end_time' ");
            }

            // remove ref if bad ref
            if (array_key_exists('r', $_GET) && trim($_GET['r']) != $address) {
                if (($faucet_settings_array['service']=='expresscrypto') && (stripos($_GET['r'], 'EC-')===false)) {
                    unset($_GET['r']);
                }
            }

            // handle refs
            if (array_key_exists('r', $_GET) && trim($_GET['r']) != $address) {
                $q = $sql->prepare("INSERT IGNORE INTO ".$dbtable_prefix."Refs (address) VALUES (?)");
                $q->execute(array(textSanitize(trim($_GET['r']))));
                $q = $sql->prepare("INSERT IGNORE INTO ".$dbtable_prefix."Addresses (`address`, `ref_id`, `last_used`) VALUES (?, (SELECT id FROM ".$dbtable_prefix."Refs WHERE address = ?), CURRENT_TIMESTAMP())");
                $q->execute(array(trim($_POST['address']), textSanitize(trim($_GET['r']))));
                
            }
            $refamount = floatval($data['referral'])*$reward/100;
            if ($data['unit'] == 'satoshi') {
                $refamount = round($refamount);
                if (($refamount < 1) && ($data['referral'] > 0)) {
                    $refamount = 1;
                }
            }
            if ($refamount>0) {
                $q = $sql->prepare("SELECT address FROM ".$dbtable_prefix."Refs WHERE id = (SELECT ref_id FROM ".$dbtable_prefix."Addresses WHERE address = ?)");
                $q->execute(array(trim($_POST['address'])));
                if ($ref = $q->fetch()) {
                    // moved to security check
                    $ret_ref = $fb->sendReferralEarnings(trim($ref[0]), $refamount, getIP());
                    if (($data['unit'] == 'satoshi') || (!isset($ret_ref['html_coin']))) {
                        $ret_msg.="\n".'<a href="#" class="address">'.htmlspecialchars(trim($ref[0])).'</a>'."\n".(empty($ret_ref['user_hash'])?'':$ret_ref['user_hash']."\n").strip_tags($ret_ref['html']);
                    } else {
                        $ret_msg.="\n".'<a href="#" class="address">'.htmlspecialchars(trim($ref[0])).'</a>'."\n".(empty($ret_ref['user_hash'])?'':$ret_ref['user_hash']."\n").strip_tags($ret_ref['html_coin']);
                    }
                    if (!empty($ret_ref['user_hash'])) {
                        $user_hash_ref = $ret_ref['user_hash'];
                        if ($user_hash_ref == $user_hash_claim) {
                            // disconnect the user from the R if they have the same user_hash
                            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Addresses SET ref_id=NULL WHERE address = ?;");
                            $q->execute(array(trim($_POST['address'])));
                        }
                    }
                    // update balance after ref payout
                    if (!empty($ret_ref['balance'])) {
                        if ($data['unit'] == 'satoshi') {
                            $balance_update = $ret_ref['balance'];
                        } else {
                            if (isset($ret['balance_bitcoin'])) {
                                $balance_update = $ret_ref['balance_bitcoin'];
                            } else {
                                $balance_update = $ret_ref['balance']/100000000;
                            }
                        }
                    }
                }
            }
            if (($data['unit'] == 'satoshi') || (!isset($ret['html_coin']))) {
                $data['paid'] = $ret['html'];
            } else {
                $data['paid'] = $ret['html_coin'];
            }
            if (!empty($shortlink_service_used)) {
                $ret_msg.="\n".'<b>SL:</b> '.$shortlink_service_used_url;
            }
        } else {
            $response = json_decode($ret["response"]);
            if ($response && property_exists($response, "status") && $response->status == 450) {
                // how many minutes until next safety limits reset?
                $end_minutes  = (date("i") > 30 ? 60 : 30) - date("i");
                // what date will it be exactly?
                $end_date = date("Y-m-d H:i:s", time()+$end_minutes*60-date("s"));
                $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET value = ? WHERE `name` = 'safety_limits_end_time' ")->execute([$end_date]);
            }
            $data['error'] = $ret['html'];
        }

        if ($ret['success'] || $fb->communication_error) {
            $q = $sql->prepare("INSERT INTO ".$dbtable_prefix."IPs (`ip`, `last_used`) VALUES (?, CURRENT_TIMESTAMP()) ON DUPLICATE KEY UPDATE `last_used` = CURRENT_TIMESTAMP()");
            $q->execute(array(getIP()));
            $q = $sql->prepare("INSERT INTO ".$dbtable_prefix."Addresses (`address`, `last_used`) VALUES (?, CURRENT_TIMESTAMP()) ON DUPLICATE KEY UPDATE `last_used` = CURRENT_TIMESTAMP()");
            $q->execute(array($address));
            if ($balance_update!=-1) {
                // update balance
                $q = $sql->prepare("UPDATE ".$dbtable_prefix."Settings SET `value` = ? WHERE `name` = ? ;");
                $q->execute(array($balance_update, 'balance'));
                // update also last balance check
                $q->execute(array(time(), 'last_balance_check'));
            }
        }
        $adminlog->admin_set_message($ret_msg);
    }
}

if (!$data['enabled']) {
    $page = 'disabled';
} elseif ($data['paid']) {
    $page = 'paid';
} elseif ($data['eligible'] && $data['address_eligible']) {
    $page = 'eligible';
} else {
    $page = 'visit_later';
}
$data['page'] = $page;

// shortlink
$data['shortlink'] = '';
if ($page=='eligible') {
    if (empty($_SESSION['shortlink'.$session_prefix])) {
        // init
        $_SESSION['shortlink'.$session_prefix] = array();
        $_SESSION['shortlink'.$session_prefix]['time'] = time();
        $_SESSION['shortlink'.$session_prefix]['enabled'] = true;
        $_SESSION['shortlink'.$session_prefix]['solved'] = false;
        $_SESSION['shortlink'.$session_prefix]['hash'] = randHash(rand(10, 12));
        $_SESSION['shortlink'.$session_prefix]['jmp'] = randHash(rand(10, 12));
        $_SESSION['shortlink'.$session_prefix]['shortlink_data'] = array();
        $_SESSION['shortlink'.$session_prefix]['shortlink_data_md5'] = md5($faucet_settings_array['shortlink_data']);
    }
    // re-enable if settings changed
    if (!$_SESSION['shortlink'.$session_prefix]['enabled']) {
        if ($_SESSION['shortlink'.$session_prefix]['shortlink_data_md5']!=md5($faucet_settings_array['shortlink_data'])) {
            // retry enabling settings changed
            $_SESSION['shortlink'.$session_prefix]['shortlink_data_md5'] = md5($faucet_settings_array['shortlink_data']);
            $_SESSION['shortlink'.$session_prefix]['enabled'] = true;
        } elseif ($_SESSION['shortlink'.$session_prefix]['time']>time() - 5*60) {
            // retry enabling after 5 minutes
            $_SESSION['shortlink'.$session_prefix]['time'] = time();
            $_SESSION['shortlink'.$session_prefix]['enabled'] = true;
        }
    }
    $shortlink_data = @json_decode($faucet_settings_array['shortlink_data'], true);
    // check if any at all
    if (!is_array($shortlink_data)) {
        $_SESSION['shortlink'.$session_prefix]['enabled'] = false;
    }

    if ($_SESSION['shortlink'.$session_prefix]['enabled']) {
        //check the claimlog for used shortlinks
        $used_shortlinks = array();
        if (!empty($data['address'])) {
            $q = $sql->prepare("SELECT
                                 shortlink,
                                 count(id) AS countclaims
                                FROM ".$dbtable_shortlink_pool_prefix."Claimlog
                                WHERE
                                 (ip LIKE ?
                                OR
                                 address LIKE ?)
                                AND
                                 time>=?
                                GROUP BY shortlink;");
            $q->execute(array(getIP(), $data['address'], time()-86400));
        } else {
            $q = $sql->prepare("SELECT
                                 shortlink,
                                 count(id) AS countclaims
                                FROM ".$dbtable_shortlink_pool_prefix."Claimlog
                                WHERE
                                 ip LIKE ?
                                AND
                                 time>=?
                                GROUP BY shortlink;");
            $q->execute(array(getIP(), time()-86400));
        }
        while ($item = $q->fetch(PDO::FETCH_ASSOC)) {
            $used_shortlinks[$item['shortlink']] = $item['countclaims'];
        }
        foreach ($shortlink_data as $shortlink_id => $v) {
            // remove disabled
            if (($v['enabled']==false) || (empty($v['apikey']))) {
                unset($shortlink_data[$shortlink_id]);
                continue;
            }
            // remove used
            if (!empty($used_shortlinks[$shortlink_id])) {
                if ((!empty($v['limit'])) && ($v['limit']>0) && ($used_shortlinks[$shortlink_id]>=$v['limit'])) {
                    unset($shortlink_data[$shortlink_id]);
                    continue;
                }
                $shortlink_data[$shortlink_id]['used']=$used_shortlinks[$shortlink_id];
            }
        }
        if (count($shortlink_data)<1) {
            $_SESSION['shortlink'.$session_prefix]['enabled'] = false;
            $_SESSION['shortlink'.$session_prefix]['time'] = time();
        } else {
            $_SESSION['shortlink'.$session_prefix]['shortlink_data'] = $shortlink_data;
        }
    }
    if ($_SESSION['shortlink'.$session_prefix]['enabled']) {
        // if solved
        if ($_SESSION['shortlink'.$session_prefix]['solved']==true) {
            // solved
            $data['shortlink']='<div id="id_shortlink" class="alert alert-success shortlink">';
            $extra_text = '';
            if (strpos($faucet_settings_array['shortlink_payout'], '%')!==false) {
                if ((int)$faucet_settings_array['shortlink_payout']>0) {
                    $extra_text = (int)$faucet_settings_array['shortlink_payout'].'%';
                }
            } else {
                $currency_text='satoshi';
                $shortlink_payout = roundRate($faucet_settings_array['shortlink_payout'] * $usdRate);
                if ($faucet_settings_array['currency']=='DOGE') {
                    if ($shortlink_payout>=0.00000001) {
                        $currency_text='DOGE';
                        $extra_text =  $shortlink_payout.' '.$currency_text;
                    }
                } else {
                    if ($shortlink_payout>=1) {
                        $extra_text = $shortlink_payout.' '.$currency_text;
                    }
                }
            }
            if (empty($extra_text)) {
                $data['shortlink'].= 'Thank you!';
            } else {
                $data['shortlink'].= 'You will get '.$extra_text.' extra when you claim.';
            }
            $data['shortlink'].='</div>';
        } else {
            // not solved
            // shortlink text
            $shortlink_text = 'Click here to prove you are a human.';
            if (isset($_SESSION['shortlink'.$session_prefix]['adlinkflykiller'])) {
                $shortlink_text = 'Please disable your ad-blocking add-on. '.$shortlink_text;
                unset($_SESSION['shortlink'.$session_prefix]['adlinkflykiller']);
            }
            $extra_text = '';
            if (strpos($faucet_settings_array['shortlink_payout'], '%')!==false) {
                if ((int)$faucet_settings_array['shortlink_payout']>0) {
                    $extra_text = (int)$faucet_settings_array['shortlink_payout'].'%';
                }
            } else {
                $currency_text='satoshi';
                $shortlink_payout = roundRate($faucet_settings_array['shortlink_payout'] * $usdRate);
                if ($faucet_settings_array['currency']=='DOGE') {
                    if ($shortlink_payout>=0.00000001) {
                        $currency_text='DOGE';
                        $extra_text = $shortlink_payout.' '.$currency_text;
                    }
                } else {
                    if ($shortlink_payout>=1) {
                        $extra_text = $shortlink_payout.' '.$currency_text;
                    }
                }
            }
            if (!empty($extra_text)) {
                $shortlink_text.= ' You will get extra '.$extra_text.' bonus.';
            }
            $data['shortlink']='<div id="id_shortlink" class="alert alert-info shortlink"><a href="/" onclick="$(location).attr(\'href\',\'shortlink.php?h='.$_SESSION['shortlink'.$session_prefix]['jmp'].'\');return false;">'.$shortlink_text.'</a></div>';
            $data['shortlink'].='<script>';
            $data['shortlink'].='$(function() {';
            if ($faucet_settings_array['shortlink_required']=='on') {
                $data['shortlink'].='$(\'.step1\').prepend(\'<div class="step_head">Step 1</div>\');';
            } else {
                $data['shortlink'].='$(\'.step1\').prepend(\'<div class="step_head">Step 1 (optional)</div>\');';
            }
            $data['shortlink'].='$(\'.step2\').prepend(\'<div class="step_head">Step 2</div>\');';
            if ($faucet_settings_array['shortlink_required']=='on') {
                $data['shortlink'].='$(\'.step2\').css(\'position\', \'relative\').css(\'padding\', \'5px\');';
                $data['shortlink'].='$(\'.step2\').append(\'<div class="step2_in">Please complete Step 1 first!</div>\');';
                $data['shortlink'].='$(\'.step2 :input\').attr(\'disabled\', true);';
                $data['shortlink'].='$(\'.step2 a\').attr(\'tabindex\', \'-1\');';
            }
            $data['shortlink'].='});';
            $data['shortlink'].='</script>';
        }
    }
}


if (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") {
    trigger_error("AJAX call that would break session");
    die();
}

// get the recent payouts
$recent_payouts = '';
if (($page=='eligible') && ($faucet_settings_array['show_recent_payouts']=='on')) {
    $odd_even = 'even';
    $q = $sql->query("SELECT address, time, reward FROM ".$dbtable_prefix."Claimlog WHERE address!='' ORDER BY id DESC LIMIT 10;");
    if ($q->rowCount()>0) {
        $recent_payouts = '<div id="recent-payouts"><h3>Recent Payouts</h3><table class="recent-payouts"><tr><th class="list-left">Date</th><th class="list-center">Address</th><th class="list-right">Reward</th></tr>';
        while ($claimlog_row = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($odd_even == 'even') {
                $odd_even = 'odd';
            } else {
                $odd_even = 'even';
            }
            if (strpos($claimlog_row['address'], '@') !== false) {
                $claimlog_row['address'] = explode('@', $claimlog_row['address'], 2);
                $claimlog_row['address'][0] = preg_replace('/(.)./', '$1*', $claimlog_row['address'][0]);
                $claimlog_row['address'] = $claimlog_row['address'][0] . '@' . $claimlog_row['address'][1];
            }
            $address_halflength = strlen($claimlog_row['address'])/2;
            $claimlog_row['address_1'] = substr($claimlog_row['address'], 0, (int)$address_halflength);
            $claimlog_row['address_2'] = substr($claimlog_row['address'], (int)$address_halflength);
            $recent_payouts.= '<tr class="list-'.$odd_even.'">';
            $recent_payouts.= '<td class="list-left"><div class="claim-date">'.date('d.m.Y-H:i:s', $claimlog_row['time']).'<a href="#" rel="'.$claimlog_row['time'].'"></a></div></td>';
            $recent_payouts.= '<td class="list-center"><span class="line">'.$claimlog_row['address_1'].'</span><span class="line">'.$claimlog_row['address_2'].'</span></td>';
            $recent_payouts.= '<td class="list-right">'.$claimlog_row['reward'].' '.$data['unit'].'</td>';
            $recent_payouts.= '<tr>';
        }
        $recent_payouts.= '</table></div>';
        $recent_payouts.= '<script>';

        $recent_payouts.= '$(function() {function padStart(num){var s=num+\'\';while(s.length<2){s=\'0\'+s};return s;}$(\'#recent-payouts .claim-date a\').each(function() {var t=$(this).attr(\'rel\');var d=new Date(t*1000);t=padStart(d.getDate())+\'.\'+padStart(d.getMonth()+1)+\'.\'+d.getFullYear()+\' \'+padStart(d.getHours())+\':\'+padStart(d.getMinutes())+\':\'+padStart(d.getSeconds());$(this).parent().html(t);})});';
        $recent_payouts.= '</script>';
    }
}
$data['recent_payouts'] = $recent_payouts;

$referred_users = '';
if (($page=='paid') && ($faucet_settings_array['show_referred_users']=='on')) {
    $odd_even = 'even';
    $q = $sql->prepare("SELECT a.address, a.last_used FROM ".$dbtable_prefix."Refs AS r LEFT JOIN ".$dbtable_prefix."Addresses AS a ON r.id=a.ref_id WHERE r.address= ? ORDER BY address ASC;");
    $q->execute(array($data['address']));
    if ($q->rowCount()>0) {
        $referred_users = '<div id="referred-users"><h3>Referred Users</h3><table class="referred-users"><tr><th class="list-left">Last Used</th><th class="list-center">Address</th></tr>';
        while ($claimlog_row = $q->fetch(PDO::FETCH_ASSOC)) {
            if ($odd_even=='even') {
                $odd_even = 'odd';
            } else {
                $odd_even = 'even';
            }
            $address_halflength = strlen($claimlog_row['address'])/2;
            $claimlog_row['address_1'] = substr($claimlog_row['address'], 0, (int)$address_halflength);
            $claimlog_row['address_2'] = substr($claimlog_row['address'], (int)$address_halflength);
            $claimlog_row['time'] = strtotime($claimlog_row['last_used']);
            $referred_users.= '<tr class="list-'.$odd_even.'">';
            $referred_users.= '<td class="list-left"><div class="claim-date">'.date('d.m.Y-H:i:s', $claimlog_row['time']).'<a href="#" rel="'.$claimlog_row['time'].'"></a></div></td>';
            $referred_users.= '<td class="list-center"><span class="line">'.$claimlog_row['address_1'].'</span><span class="line">'.$claimlog_row['address_2'].'</span></td>';
            $referred_users.= '<tr>';
        }
        $referred_users.= '</table></div>';
        $referred_users.= '<script>';

        $referred_users.= '$(function() {function padStart(num){var s=num+\'\';while(s.length<2){s=\'0\'+s};return s;}$(\'#recent-payouts .claim-date a\').each(function() {var t=$(this).attr(\'rel\');var d=new Date(t*1000);t=padStart(d.getDate())+\'.\'+padStart(d.getMonth()+1)+\'.\'+d.getFullYear()+\' \'+padStart(d.getHours())+\':\'+padStart(d.getMinutes())+\':\'+padStart(d.getSeconds());$(this).parent().html(t);})});';
        $referred_users.= '</script>';
    }
}
$data['referred_users'] = $referred_users;


if ((!isset($_SESSION['address_input_name'.$session_prefix])) || (empty($_SESSION['address_input_name'.$session_prefix]))) {
    $_SESSION['address_input_name'.$session_prefix] = randHash(rand(10, 12));
}
$data['address_input_name'] = $_SESSION['address_input_name'.$session_prefix];

$data['rewards'] = implode(', ', $possible_rewards);

checkClaimLimitNotExausted(trim($data["address"]), getIP(), $claims_left);

$data['claims_left']='';
if ($claims_left!=-1) {
    $data['claims_left']=$claims_left.' daily claims left.';
}

$q = $sql->query("SELECT url_name, name FROM ".$dbtable_prefix."Pages ORDER BY id");
$data["user_pages"] = $q->fetchAll();

$data['ref_users'] = '';
$q = $sql->query("SELECT count(address) as ref_users FROM ".$dbtable_prefix."Addresses WHERE ref_id IS NOT NULL;");
$ref_users_result = $q->fetch(PDO::FETCH_ASSOC);
$data['ref_users'] = $ref_users_result['ref_users'];

$data['headers'] = '';

if ($data['service']=='expresscrypto') {
    $data['address_placeholder'] = 'EC-UserId-2465';
} else if ($data['service']=='cedsonhub') {
    $data['address_placeholder'] = 'MakeJar';
} else if ($data['service']=='kswallet') {
    $data['address_placeholder'] = 'uJHGti7VeCYf5ReSC3BTLVtom6qzBrGfftHEAKGsWE';
    if (strpos($data['kswallet_meta_id'], 'meta')===false) {
        $data['headers'].= '<meta name="kswallet" content="'.$data['kswallet_meta_id'].'" />';
    } else {
        $data['headers'].= $data['kswallet_meta_id'];
    }
} else {
    $data['address_placeholder'] = '1MakeJar79wqjDyqNagES7AaX9GwdtfqvS';
}

$allowed = array('headers', 'currency', 'address_placeholder', 'ref_users', 'shortlink', 'recent_payouts', 'referred_users', 'page', 'name', 'rewards', 'claims_left', 'short', 'error', 'paid', 'captcha_valid', 'captcha', 'captcha_info', 'time_left', 'referral', 'reflink', 'template', 'user_pages', 'timer', 'unit', 'address', 'balance', 'disable_admin_panel', 'address_input_name', 'block_adblock', 'iframe_sameorigin_only', 'button_timer', 'safety_limits_end_time');

preg_match_all('/\$data\[([\'"])(custom_(?:(?!\1).)*)\1\]/', file_get_contents("templates/$template/index.php"), $matches);
foreach (array_unique($matches[2]) as $box) {
    $key = "{$box}_$template";
    if (!array_key_exists($key, $data)) {
        $data[$key] = '';
    }
    $allowed[] = $key;
}

foreach (array_keys($data) as $key) {
    if (!(in_array($key, $allowed))) {
        unset($data[$key]);
    }
}

foreach (array_keys($data) as $key) {
    if (array_key_exists($key, $data) && strpos($key, 'custom_') === 0) {
        $data[substr($key, 0, strlen($key) - strlen($template) - 1)] = $data[$key];
        unset($data[$key]);
    }
}

if (array_key_exists('p', $_GET)) {
    $q = $sql->prepare("SELECT url_name, name, html FROM ".$dbtable_prefix."Pages WHERE url_name = ?");
    $q->execute(array($_GET['p']));
    if ($page = $q->fetch()) {
        $data['page'] = 'user_page';
        $data['user_page'] = $page;
    } else {
        $data['error'] = "<div class='alert alert-danger'>That page doesn't exist!</div>";
    }
}

$data['address'] = htmlspecialchars($data['address']);

require_once('templates/'.$template.'/index.php');
