<?php

require_once 'config.php';

if ((!isset($dbtable_prefix)) || (empty($dbtable_prefix))) {
    $dbtable_prefix = 'Faucetinabox_';
}
if ((!isset($dbtable_shortlink_pool_prefix)) || (empty($dbtable_shortlink_pool_prefix))) {
    $dbtable_shortlink_pool_prefix = $dbtable_prefix;
}

$session_prefix=md5($dbuser.'-'.$dbname.'-'.$dbtable_prefix);
$session_prefix='_'.substr($session_prefix, 0, 8);

session_start();
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', false);

if (empty($_SESSION['shortlink'.$session_prefix]['hash'])) {
    header('X-Error: Missing hash.');
    header('Location: .');
    exit;
}



// generate shortlink
include_once 'libs/functions.php';

// generate new shortlink
try {
    $sql = new PDO($dbdsn, $dbuser, $dbpass, array(PDO::ATTR_PERSISTENT => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch(PDOException $e) {
    header('X-Error: No SQL connection.');
    header('Location: .');
    exit;
}

// check if configured
try {
    // load settings
    $faucet_settings_array = fb_load_settings();
    if (empty($faucet_settings_array['password'])) {
        // not configured
        exit;
    }
} catch(PDOException $e) {
    // not installed
    exit;
}



// returning from a shortlink
if (!empty($_GET['sl'])) {
    if ($_GET['sl']==$_SESSION['shortlink'.$session_prefix]['hash']) {
        if ($_SESSION['shortlink'.$session_prefix]['time'] > time()-5) {
            $_SESSION['shortlink'.$session_prefix]['adlinkflykiller'] = true;
            header('Location: .');
            exit;
        }
        // success, redirect to home
        if (isset($_SESSION['shortlink'.$session_prefix]['id'])) {
            // update users_returned
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Shortlinks SET users_returned=users_returned+1 WHERE id=? LIMIT 1;");
            $q->execute(array($_SESSION['shortlink'.$session_prefix]['id']));
        }
        $_SESSION['shortlink'.$session_prefix]['solved'] = true;
        header('Location: .');
        exit;
    }
    // bad shortlink hash
    header('X-Error: Returning. Bad shortlink hash.');
    header('Location: .');
    exit;
}

if ((!empty($_GET['h']))&&(!empty($_SESSION['shortlink'.$session_prefix]['hash']))&&($_SESSION['shortlink'.$session_prefix]['jmp'])==$_GET['h']) {
    // allow link generation
} else {
    // redirect to home
    header('X-Error: Bad jmp value.');
    header('Location: .');
    exit;
}



// delete shorts older than a week
if (mt_rand(0, 10)==5) {
    $q = $sql->prepare("DELETE FROM ".$dbtable_prefix."Shortlinks WHERE time<?;");
    $q->execute(array(time()-86400*7));
}

$shortlink_data = $_SESSION['shortlink'.$session_prefix]['shortlink_data'];
// check used once again
$used_shortlinks = array();
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
while ($item = $q->fetch(PDO::FETCH_ASSOC)) {
    $used_shortlinks[$item['shortlink']] = $item['countclaims'];
}
foreach ($shortlink_data as $shortlink_id => $v) {
    // remove used
    if (!empty($used_shortlinks[$shortlink_id])) {
        if ((!empty($v['limit'])) && ($v['limit']>0) && ($used_shortlinks[$shortlink_id]>=$v['limit'])) {
            unset($shortlink_data[$shortlink_id]);
            continue;
        }
        $shortlink_data[$shortlink_id]['used']=$used_shortlinks[$shortlink_id];
    }
}

// sort shortlink data using priority
uasort($shortlink_data, 'sort_shortlink_data');

$_SESSION['shortlink'.$session_prefix]['time'] = time();

foreach ($shortlink_data as $key => $val) {
    // search for already generated shortlink today
    $q = $sql->prepare("SELECT id FROM ".$dbtable_prefix."Shortlinks WHERE time>? AND shortlink=? LIMIT 1;");
    $q->execute(array(time()-86400, $key));
    if ($item = $q->fetch(PDO::FETCH_ASSOC)) {
        // there is at least one link generated today
        // get random link generated in the past week
        $days_to_check_back = 7;
        if ((isset($val['days'])) && (!empty($val['days'])) && ((int)$val['days']>0)) {
            $days_to_check_back = (int)$val['days'];
        }
        $q = $sql->prepare("SELECT id, link, hash, users_sent FROM ".$dbtable_prefix."Shortlinks WHERE time>? AND shortlink=? ORDER BY RAND() LIMIT 1;");
        $q->execute(array(time()-86400*$days_to_check_back, $key));
        if ($item = $q->fetch(PDO::FETCH_ASSOC)) {
            $_SESSION['shortlink'.$session_prefix]['used'] = $key;
            $_SESSION['shortlink'.$session_prefix]['used_url'] = $item['link'];
            $_SESSION['shortlink'.$session_prefix]['hash'] = $item['hash'];
            $_SESSION['shortlink'.$session_prefix]['id'] = $item['id'];
            // update users_sent
            $q = $sql->prepare("UPDATE ".$dbtable_prefix."Shortlinks SET users_sent=users_sent+1 WHERE id=? LIMIT 1;");
            $q->execute(array($item['id']));
            header('Referrer-Policy: unsafe-url');
            header('Location: '.$item['link']);
            exit;
        }
    } else {
        // get shortlink data
        $api_token = $val['apikey'];
        $api_url = $val['apilink'];

        // Check protocol
        if (is_ssl()) {
            $protocol = "https://";
        } else {
            $protocol = "http://";
        }
        //
        $return_url = $protocol.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'], '?').'?sl='.$_SESSION['shortlink'.$session_prefix]['hash'];
        $return_url_encoded = urlencode($return_url);

        $ch = curl_init();
        if ($ch === false) {
            // curl not enabled
            header('X-Error: Curl not enabled.');
            header('Location: .');
            exit;
        }

        // no links generated today
        $api_url.= '?api='.$api_token.'&url='.$return_url_encoded.'&alias=FBU'.randHash(9).'&expiry='.(time()+10800);//.'&type=1'
        // get the shortlink
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:76.0) Gecko/20100101 Firefox/76.0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $shortlink_response = @curl_exec($ch);
        curl_close($ch);
        // not compatible with http: api - retry with https:
        if (empty($shortlink_response)) {
            $http_pos=stripos($api_url, 'http://');
            if (($http_pos!==false) && ($http_pos==0))  {
                if ($ch = curl_init()) {
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_URL, 'https://'.substr($api_url, 7));
                    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:76.0) Gecko/20100101 Firefox/76.0');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    $shortlink_response = @curl_exec($ch);
                    curl_close($ch);
                }
            }
        }
        $result = @json_decode($shortlink_response, true);
        if ((!empty($result['status'])) && ($result['status']=='success')) {
            // valid shortlink - go
            $_SESSION['shortlink'.$session_prefix]['used'] = $key;
            $_SESSION['shortlink'.$session_prefix]['used_url'] = $result['shortenedUrl'];
            $qq = $sql->prepare("INSERT INTO ".$dbtable_prefix."Shortlinks(time, shortlink, link, hash, users_sent) VALUES(?,?,?,?,?);");
            $qq->execute(array(time(), $key, $result['shortenedUrl'], $_SESSION['shortlink'.$session_prefix]['hash'], 1));

            $_SESSION['shortlink'.$session_prefix]['id'] = $sql->lastInsertId();
            header('Referrer-Policy: unsafe-url');
            header('Location: '.$result['shortenedUrl']);
            exit;
        } else {
            // bad shortlink
            header('X-Error-'.$key.': '.$key.' fail');
            continue;
        }
    }
}

// error - No link provider available, redirect to home
$_SESSION['shortlink'.$session_prefix]['enabled'] = false;
$_SESSION['shortlink'.$session_prefix]['time'] = time();
header('X-Error-final: No link provider available.');
header('Location: .');
exit;

?>