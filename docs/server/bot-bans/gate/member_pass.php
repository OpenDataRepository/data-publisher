<?php

/**
 * Open Data Repository Data Publisher
 * ODR gate: the member pass
 * (C) 2026 by Nathan Stone (nate.stone@opendatarepository.org)
 * Released under the GPLv2
 *
 * Lets a logged-in WordPress or ODR user through the gate without the reCAPTCHA, from any address.
 *
 * The ordinary pass (odr_pass, set by challenge.php) is signed with the visitor's address, so it
 * stops working whenever that changes -- which for Apple's iCloud Private Relay, and phones moving
 * between networks, is all the time.  Apache can't check WordPress's or ODR's own login cookies
 * (that needs their databases), so whichever of them the visitor is logged in to also sets this
 * cookie, which Apache can check on its own (step 4 of the OdrGate macro in odr-gate.conf):
 *
 *     odr_member=<expiry YYYYMMDDHHMMSS>-<sha1 of "expiry-member-host-secret">
 *
 * The literal "member" stands where the ordinary pass has the address (an address is never
 * "member", so one can't pass for the other).  Under a shared domain (odr_pass_scope, e.g.
 * *.odr.io) it is odr_shared_member, signed with the domain and set for all of it, exactly like
 * odr_shared_pass.  It lasts member_pass_hours (gate.ini), and is renewed on any page a logged-in
 * user loads once less than half of that is left -- so someone who keeps using the site is never
 * asked.  Logging out deletes it.  It is not tied to an address, so it can be copied: that's the
 * trade, and why only a logged-in session ever gets one.
 *
 * Used by the WordPress theme (odr_gate_member.php) and by ODR (GateMemberPassSubscriber).  No
 * framework, nothing but these functions: Apache names this directory in odr_gate_dir, and both
 * load the file from there.  Everything comes from what Apache hands the request (odr_gate_config,
 * odr_pass_scope, odr_gate_now), so on a site without the gate every function here is a no-op.
 * Not served to browsers: odr-gate.conf only lets challenge.php out of this directory.
 */

if ( !function_exists('odr_gate_member_context') ) {

/**
 * What a member pass for this request would look like, or null when the site isn't behind the
 * gate (or the gate's settings can't be read), in which case nothing should be set.
 *
 * @param array $server  $_SERVER, or Symfony's $request->server->all()
 *
 * @return array|null  name, domain, signed_for, secret, hours, now (Apache's clock, YmdHis),
 *                     secure
 */
function odr_gate_member_context(array $server)
{
    static $configs = array();

    $get = function ($key) use ($server) {
        return (string)($server[$key] ?? $server['REDIRECT_'.$key] ?? '');
    };

    $config_file = $get('odr_gate_config');
    $now = $get('odr_gate_now');
    $host = (string)($server['HTTP_HOST'] ?? '');
    if ( $config_file === '' || $host === '' || !preg_match('/^[0-9]{14}$/', $now) )
        return null;

    if ( !array_key_exists($config_file, $configs) )
        $configs[$config_file] = is_readable($config_file) ? @parse_ini_file($config_file) : false;
    $config = $configs[$config_file];
    if ( !is_array($config) || empty($config['pass_secret']) || strpos($config['pass_secret'], 'REPLACE') === 0 )
        return null;

    // The shared domain this site is under, if any -- the same check challenge.php makes
    $scope = strtolower($get('odr_pass_scope'));
    $bare_host = strtolower((string)preg_replace('/:[0-9]+$/', '', $host));
    if ( $scope !== '' && $bare_host !== $scope && substr($bare_host, -(strlen($scope) + 1)) !== '.'.$scope )
        $scope = '';

    return array(
        'name' => ( $scope !== '' ? 'odr_shared_member' : 'odr_member' ),
        'domain' => $scope,
        // NOTE: the same value Apache signs with: %{HTTP_HOST} as sent, or odr_pass_scope
        'signed_for' => ( $scope !== '' ? $scope : $host ),
        'secret' => (string)$config['pass_secret'],
        'hours' => isset($config['member_pass_hours']) ? max(1, (int)$config['member_pass_hours']) : 336,
        'now' => $now,
        'secure' => ( strtolower((string)($server['HTTPS'] ?? '')) === 'on' ),
    );
}


/**
 * The signature for a member pass expiring at $expiry.  The same string, in the same order, as the
 * sha1() in odr-gate.conf.
 */
function odr_gate_member_sign(array $context, $expiry)
{
    return sha1($expiry.'-member-'.$context['signed_for'].'-'.$context['secret']);
}


/**
 * Whether a logged-in user needs a new member pass: theirs is missing, isn't valid (wrong site, an
 * old secret), has expired, or has less than half its life left.
 *
 * @param array $context  from odr_gate_member_context()
 * @param array $cookies  $_COOKIE, or Symfony's $request->cookies->all()
 */
function odr_gate_member_needs_renewal(array $context, array $cookies)
{
    $value = (string)($cookies[$context['name']] ?? '');
    if ( !preg_match('/^([0-9]{14})-([0-9a-f]{40})$/', $value, $match) )
        return true;
    if ( !hash_equals(odr_gate_member_sign($context, $match[1]), $match[2]) )
        return true;

    // Both are YmdHis on Apache's clock, so they compare as strings
    return $match[1] <= odr_gate_member_expiry($context, $context['hours'] / 2);
}


/**
 * Apache's clock plus $hours, as YmdHis.  Apache's %{TIME} is local time with no zone, so it's
 * handled here as if it were UTC: only the arithmetic matters, and UTC has no daylight saving.
 */
function odr_gate_member_expiry(array $context, $hours)
{
    $clock = DateTime::createFromFormat('YmdHis', $context['now'], new DateTimeZone('UTC'));
    return $clock->modify('+'.(int)round($hours * 3600).' seconds')->format('YmdHis');
}


/**
 * A new member pass: array(name, value, options), options in setcookie()'s form.
 */
function odr_gate_member_issue(array $context)
{
    $expiry = odr_gate_member_expiry($context, $context['hours']);
    return array(
        $context['name'],
        $expiry.'-'.odr_gate_member_sign($context, $expiry),
        odr_gate_member_cookie_options($context, time() + $context['hours'] * 3600),
    );
}


/**
 * Deleting the member pass, on logout: array(name, options), options in setcookie()'s form.
 */
function odr_gate_member_clear(array $context)
{
    return array($context['name'], odr_gate_member_cookie_options($context, 1));
}


function odr_gate_member_cookie_options(array $context, $expires)
{
    $options = array(
        'expires' => $expires,
        'path' => '/',
        'secure' => $context['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if ( $context['domain'] !== '' )
        $options['domain'] = $context['domain'];
    return $options;
}

}
