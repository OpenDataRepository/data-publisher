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
 *
 * The pass cookie is odr_pass=<expiry YYYYMMDDHHMMSS>-<sha1("expiry-address-secret")>, which
 * Apache checks against its own clock and the visitor's address on every request.
 */

// The instance's app/config/gate.ini.  Apache names it (odr-gate-local.conf: SetEnv
//  odr_gate_config), because on a linked install docs/ is a symlink into the shared checkout --
//  working it out from this file's own location would find the wrong instance's settings.
$config_file = $_SERVER['odr_gate_config'] ?? $_SERVER['REDIRECT_odr_gate_config']
    ?? dirname(__DIR__, 4).'/app/config/gate.ini';

$config = @parse_ini_file($config_file);
foreach (array('recaptcha_site_key', 'recaptcha_secret', 'pass_secret') as $key) {
    if ( !is_array($config) || empty($config[$key]) || strpos($config[$key], 'REPLACE') === 0 ) {
        error_log('odr-gate: '.$config_file.' is missing or has no '.$key);
        odr_gate_respond(503, 'gate-misconfigured', '<h1>Temporarily unavailable</h1><p>Please try again in a few minutes.</p>');
    }
}
$pass_hours = isset($config['pass_hours']) ? max(1, (int)$config['pass_hours']) : 24;

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
        $signature = sha1($expiry.'-'.$ip.'-'.$config['pass_secret']);

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
        odr_gate_challenge($config['recaptcha_site_key'], $uri, 'challenge-google-unreachable',
            'We couldn&rsquo;t reach the verification service. Please try again.');

    odr_gate_challenge($config['recaptcha_site_key'], $uri, 'challenge', 'That didn&rsquo;t work. Please try again.');
}

odr_gate_challenge($config['recaptcha_site_key'], $uri, 'challenge', null);


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
 * Shows the challenge and stops.
 */
function odr_gate_challenge($site_key, $uri, $outcome, $message)
{
    $note = ( $message === null ) ? '' : '<p class="note">'.$message.'</p>';
    odr_gate_respond(403, $outcome,
        '<h1>Confirm you are human</h1>'
        .'<p>Automated traffic has been overwhelming this site, so visitors are asked to confirm '
        .'they&rsquo;re human. It only takes a moment, and you won&rsquo;t be asked again for a while.</p>'
        .$note
        .'<form method="post" action="'.htmlspecialchars($uri, ENT_QUOTES).'">'
        .'<input type="hidden" name="odr_gate" value="1">'
        .'<div class="g-recaptcha" data-sitekey="'.htmlspecialchars($site_key, ENT_QUOTES).'"></div>'
        .'<button type="submit">Continue</button>'
        .'</form>',
        '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
    );
}


/**
 * Sends a small complete page and stops.
 */
function odr_gate_respond($status, $outcome, $body, $head = '')
{
    http_response_code($status);
    header('X-ODR-Gate: '.$outcome);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    if ( $status === 503 )
        header('Retry-After: 300');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       .'<meta name="viewport" content="width=device-width, initial-scale=1">'
       .'<meta name="robots" content="noindex, nofollow">'
       .'<title>Confirm you are human</title>'.$head
       .'<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:40px 16px;'
       .'background:#fff;color:#333;display:flex;justify-content:center}main{max-width:30rem}'
       .'h1{font-size:1.3rem;margin:0 0 .75rem}p{line-height:1.5;margin:0 0 1rem}.note{color:#b32d2e}'
       .'button{font:inherit;padding:.5rem 1rem;margin-top:1rem;cursor:pointer}</style></head>'
       .'<body><main>'.$body.'</main></body></html>';
    exit;
}
