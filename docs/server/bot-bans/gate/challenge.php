<?php

/**
 * Open Data Repository Data Publisher
 * ODR gate: the reCAPTCHA challenge
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Apache (docs/server/bot-bans/apache/odr-gate.conf) sends every request without a valid pass
 * cookie here, by internal rewrite -- so REQUEST_URI is still the url the visitor asked for.
 * Deliberately standalone: no WordPress, no ODR, no framework.  Showing a challenge costs one
 * small script, which is the point when bots are the ones asking.
 *
 * GET (or anything without an answer): show the challenge, 403, logged gate=challenge.
 * POST with an answer: check it with Google.  Right -> set the pass cookie and send the visitor
 *   back to their url (303, gate=solved).  Wrong -> the challenge again (gate=challenge).
 *   Google unreachable -> the challenge again, logged gate=challenge-google-unreachable, which
 *   fail2ban doesn't count: that's our outage, not the visitor's fault.
 * /odr-gate/challenge.php?logo: the site's logo (gate=gate-logo), which has to be served from
 *   here because everything else on the site is behind the gate.
 *
 * Settings come from two places, both named by Apache (the OdrGate macro):
 *   odr_gate_config  the shared gate.ini: reCAPTCHA keys, secret, pass length (every site)
 *   odr_gate_site    this site's ODR instance, whose app/config/gate_branding.ini styles the page
 * Apache has to name them: on a linked install docs/ is a symlink into the shared checkout, so
 * working them out from this file's own location would find the wrong instance.
 *
 * The pass cookie is odr_pass=<expiry YYYYMMDDHHMMSS>-<sha1("expiry-address-host-secret")>,
 * which Apache checks against its own clock, the visitor's address and the site on every request.
 */

$site_dir = (string)($_SERVER['odr_gate_site'] ?? $_SERVER['REDIRECT_odr_gate_site'] ?? '');
$config_file = (string)($_SERVER['odr_gate_config'] ?? $_SERVER['REDIRECT_odr_gate_config']
    ?? ($site_dir !== '' ? $site_dir : dirname(__DIR__, 4)).'/app/config/gate.ini');

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$brand = odr_gate_branding($site_dir, $host);

// The logo has to come from here: everything else on the site is behind the gate
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ( strpos($path, '/odr-gate/') === 0 && isset($_GET['logo']) )
    odr_gate_serve_logo($site_dir, $brand);

$config = @parse_ini_file($config_file);
foreach (array('recaptcha_site_key', 'recaptcha_secret', 'pass_secret') as $key) {
    if ( !is_array($config) || empty($config[$key]) || strpos($config[$key], 'REPLACE') === 0 ) {
        error_log('odr-gate: '.$config_file.' is missing or has no '.$key);
        odr_gate_respond(503, 'gate-misconfigured', $brand,
            '<h1>Temporarily unavailable</h1><p>Please try again in a few minutes.</p>');
    }
}
$pass_hours = isset($config['pass_hours']) ? max(1, (int)$config['pass_hours']) : 96;

// The url to send the visitor back to.  Only ever a path on this site: "//evil.example" would be
// a redirect to someone else's.
$uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
if ( !preg_match('#^/(?![/\\\\])#', $uri) )
    $uri = '/';

$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

// ----------------------------------------
if ( ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['odr_gate']) ) {
    $token = isset($_POST['g-recaptcha-response']) ? (string)$_POST['g-recaptcha-response'] : '';
    $verdict = ( $token === '' ) ? false : odr_gate_verify($config['recaptcha_secret'], $token, $ip);

    if ( $verdict === true ) {
        // Expiry on Apache's clock (odr_gate_now is %{TIME}, handed over by the rewrite), so the
        //  "-gt %{TIME}" comparison in odr-gate.conf can't be thrown off by php's timezone
        $now = $_SERVER['odr_gate_now'] ?? $_SERVER['REDIRECT_odr_gate_now'] ?? date('YmdHis');
        $clock = DateTime::createFromFormat('YmdHis', (string)$now, new DateTimeZone('UTC'));
        if ( $clock === false )
            $clock = new DateTime('now', new DateTimeZone('UTC'));
        $expiry = $clock->modify('+'.$pass_hours.' hours')->format('YmdHis');
        // NOTE: the same string, in the same order, as the sha1() in odr-gate.conf
        $signature = sha1($expiry.'-'.$ip.'-'.$host.'-'.$config['pass_secret']);

        setcookie('odr_pass', $expiry.'-'.$signature, array(
            'expires' => time() + $pass_hours * 3600,
            'path' => '/',
            'secure' => ( ($_SERVER['HTTPS'] ?? '') === 'on' ),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        header('X-ODR-Gate: solved');
        header('Cache-Control: no-store');
        header('Location: '.$uri, true, 303);
        exit;
    }

    if ( $verdict === null )
        odr_gate_challenge($config['recaptcha_site_key'], $uri, $brand, 'challenge-google-unreachable',
            'We couldn&rsquo;t reach the verification service. Please try again.');

    odr_gate_challenge($config['recaptcha_site_key'], $uri, $brand, 'challenge', 'That didn&rsquo;t work. Please try again.');
}

odr_gate_challenge($config['recaptcha_site_key'], $uri, $brand, 'challenge', null);


// ------------------------------------------------------------------------------------------------

/**
 * Asks Google whether a reCAPTCHA answer is right.
 *
 * @return bool|null  true or false, or null when Google couldn't be asked
 */
function odr_gate_verify($secret, $token, $ip)
{
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array('secret' => $secret, 'response' => $token, 'remoteip' => $ip)),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ( $body === false || $status !== 200 )
        return null;
    $answer = json_decode($body, true);
    if ( !is_array($answer) || !isset($answer['success']) )
        return null;

    return $answer['success'] === true;
}


/**
 * This site's branding: app/config/gate_branding.ini in its instance, with defaults for
 * anything missing or invalid.
 *
 * @return array
 */
function odr_gate_branding($site_dir, $host)
{
    $brand = array(
        'site_name' => $host,
        'logo_url' => '',
        'logo_file' => '',
        'logo_alt' => '',
        'logo_height' => 60,
        'accent_color' => '#1f4e79',
        'background_color' => '#ffffff',
        'text_color' => '#333333',
        'message' => 'Automated traffic has been overwhelming this site, so visitors are asked to '
            .'confirm they\'re human. It only takes a moment, and you won\'t be asked again for a while.',
        'footer' => '',
        'footer_link' => '',
    );

    $file = ( $site_dir === '' ) ? '' : $site_dir.'/app/config/gate_branding.ini';
    $settings = ( $file !== '' && is_readable($file) ) ? @parse_ini_file($file) : false;
    if ( is_array($settings) ) {
        foreach ($brand as $key => $default) {
            if ( isset($settings[$key]) && trim((string)$settings[$key]) !== '' )
                $brand[$key] = trim((string)$settings[$key]);
        }
    }

    foreach (array('accent_color' => '#1f4e79', 'background_color' => '#ffffff', 'text_color' => '#333333') as $key => $default) {
        if ( !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $brand[$key]) )
            $brand[$key] = $default;
    }
    $brand['logo_height'] = max(16, min(200, (int)$brand['logo_height']));
    if ( !preg_match('#^https?://#i', $brand['footer_link']) )
        $brand['footer_link'] = '';

    return $brand;
}


/**
 * The logo, as stored locally: array(path, content type, version), or null when there isn't one.
 * Downloads and caches logo_url when the copy is missing, stale (a day) or for a different url.
 *
 * @return array|null
 */
function odr_gate_logo($site_dir, $brand)
{
    if ( $site_dir === '' )
        return null;

    // A file copied into app/config/gate/ by hand.  basename(): it can't name anything else.
    if ( $brand['logo_file'] !== '' ) {
        $file = $site_dir.'/app/config/gate/'.basename($brand['logo_file']);
        if ( !is_readable($file) )
            return null;
        $type = function_exists('mime_content_type') ? (string)@mime_content_type($file) : '';
        if ( strpos($type, 'image/') !== 0 )
            $type = ( substr(strtolower($file), -4) === '.svg' ) ? 'image/svg+xml' : 'image/png';
        return array($file, $type, (string)filemtime($file));
    }

    if ( $brand['logo_url'] === '' )
        return null;

    // A url, downloaded and kept in var/gate/.  On this site's own host it works too: the request
    //  comes from this server, which has a free pass.
    $url = $brand['logo_url'];
    if ( $url[0] === '/' && substr($url, 0, 2) !== '//' )
        $url = 'https://'.($_SERVER['HTTP_HOST'] ?? 'localhost').$url;
    if ( !preg_match('#^https?://#i', $url) )
        return null;

    $dir = $site_dir.'/var/gate';
    $data = $dir.'/logo';
    $meta_file = $dir.'/logo.json';
    $meta = is_readable($meta_file) ? json_decode((string)@file_get_contents($meta_file), true) : null;

    $current = is_array($meta) && is_readable($data) && ($meta['url'] ?? '') === $url;
    $fresh = $current && (time() - (int)($meta['fetched'] ?? 0)) < 86400;

    // Only one request refreshes it at a time; the others keep using the copy (or no logo)
    $lock = $dir.'/logo.lock';
    if ( !$fresh && (!is_file($lock) || time() - (int)@filemtime($lock) > 60) ) {
        if ( (is_dir($dir) || @mkdir($dir, 0775, true)) && @touch($lock) ) {
            $fetched = odr_gate_fetch_image($url);
            if ( $fetched !== null ) {
                @file_put_contents($data.'.new', $fetched['body']);
                @rename($data.'.new', $data);
                @file_put_contents($meta_file, json_encode(array('url' => $url, 'type' => $fetched['type'], 'fetched' => time())));
                $meta = array('url' => $url, 'type' => $fetched['type'], 'fetched' => time());
                $current = true;
            }
        }
    }

    if ( !$current )
        return null;
    return array($data, (string)$meta['type'], (string)$meta['fetched']);
}


/**
 * Downloads an image: array(body, type), or null.  At most 1 MB, a few seconds.
 *
 * @return array|null
 */
function odr_gate_fetch_image($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_MAXFILESIZE => 1048576,
        CURLOPT_USERAGENT => 'ODR gate logo fetch',
    ));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($ch);

    if ( $body === false || $status !== 200 || strlen($body) > 1048576 || strpos($type, 'image/') !== 0 ) {
        error_log('odr-gate: could not fetch the logo from '.$url.' (HTTP '.$status.', '.$type.')');
        return null;
    }
    return array('body' => $body, 'type' => $type);
}


/**
 * Sends the logo and stops.
 */
function odr_gate_serve_logo($site_dir, $brand)
{
    $logo = odr_gate_logo($site_dir, $brand);
    header('X-ODR-Gate: gate-logo');
    if ( $logo === null ) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: '.$logo[1]);
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    // an svg is a document: never let it run anything
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    readfile($logo[0]);
    exit;
}


/**
 * Shows the challenge and stops.
 */
function odr_gate_challenge($site_key, $uri, $brand, $outcome, $message)
{
    $note = ( $message === null ) ? '' : '<p class="note">'.$message.'</p>';
    odr_gate_respond(403, $outcome, $brand,
        '<h1>Confirm you are human</h1>'
        .'<p>'.odr_gate_h($brand['message']).'</p>'
        .$note
        .'<form method="post" action="'.odr_gate_h($uri).'">'
        .'<input type="hidden" name="odr_gate" value="1">'
        .'<div class="g-recaptcha" data-sitekey="'.odr_gate_h($site_key).'"></div>'
        .'<button type="submit">Continue</button>'
        .'</form>',
        '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
    );
}


/**
 * Sends a small complete page, in this site's branding, and stops.
 */
function odr_gate_respond($status, $outcome, $brand, $body, $head = '')
{
    global $site_dir;

    http_response_code($status);
    header('X-ODR-Gate: '.$outcome);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    if ( $status === 503 )
        header('Retry-After: 300');

    $logo = odr_gate_logo((string)$site_dir, $brand);
    if ( $logo !== null ) {
        $masthead = '<img class="logo" src="/odr-gate/challenge.php?logo&amp;v='.odr_gate_h($logo[2]).'"'
            .' alt="'.odr_gate_h($brand['logo_alt'] !== '' ? $brand['logo_alt'] : $brand['site_name']).'"'
            .' style="height:'.$brand['logo_height'].'px">';
    }
    else {
        $masthead = '<div class="site">'.odr_gate_h($brand['site_name']).'</div>';
    }

    $footer = '';
    if ( $brand['footer'] !== '' ) {
        $text = odr_gate_h($brand['footer']);
        $footer = '<footer>'.( $brand['footer_link'] !== ''
            ? '<a href="'.odr_gate_h($brand['footer_link']).'">'.$text.'</a>' : $text ).'</footer>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       .'<meta name="viewport" content="width=device-width, initial-scale=1">'
       .'<meta name="robots" content="noindex, nofollow">'
       .'<title>'.odr_gate_h($brand['site_name']).' &ndash; Confirm you are human</title>'.$head
       .'<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:40px 16px;'
       .'background:'.$brand['background_color'].';color:'.$brand['text_color'].';display:flex;justify-content:center}'
       .'main{max-width:30rem;width:100%}.logo{display:block;max-width:100%;margin:0 0 1.5rem}'
       .'.site{font-size:1.5rem;font-weight:600;color:'.$brand['accent_color'].';margin:0 0 1.5rem}'
       .'h1{font-size:1.3rem;margin:0 0 .75rem}p{line-height:1.5;margin:0 0 1rem}.note{color:#b32d2e}'
       .'button{font:inherit;padding:.5rem 1.25rem;margin-top:1rem;cursor:pointer;border:0;border-radius:4px;'
       .'background:'.$brand['accent_color'].';color:#fff}'
       .'footer{margin-top:2rem;font-size:.9rem;opacity:.8}footer a{color:'.$brand['accent_color'].'}</style></head>'
       .'<body><main>'.$masthead.$body.$footer.'</main></body></html>';
    exit;
}


function odr_gate_h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}
