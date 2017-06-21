<?php

/**
 * Open Data Repository Data Publisher
 * ODR OAuth Listener
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Extends HWI's OAuthListener for the purpose of intercepting OAuth login responses when connecting ODR users to
 * external OAuth provider accounts.  Everything else is left unchanged.
 */

namespace ODR\OpenRepository\OAuthClientBundle\Security\Http\Firewall;

use HWI\Bundle\OAuthBundle\Security\Http\Firewall\OAuthListener;
use Symfony\Component\HttpFoundation\Request;


class ODROAuthListener extends OAuthListener
{
    /**
     * {@inheritdoc}
     */
    protected function attemptAuthentication(Request $request)
    {
        // Determine whether this login attempt is due to an account-connecting attempt
        $session = $request->getSession();
        if ($session->has('_security.oauth_connect.redirect_path') &&
            $session->has('_security.oauth_connect.csrf_state') &&
            $session->has('_security.oauth_connect.target_user') &&
            $session->has('_security.oauth_connect.target_resource') &&
            $request->query->has('state') &&
            $request->attributes->has('_route') &&

            $request->query->get('state') == $session->get('_security.oauth_connect.csrf_state') &&
            $request->attributes->get('_route') == $session->get('_security.oauth_connect.target_resource')
        ) {
            // This is due to an account-connecting attempt, do nothing at this time...
        }
        else {
            // This login attempt isn't immediately after an account-connecting attempt...
            // Ensure those session keys are non-existent so ODROAuthUserProvider doesn't attempt to connect an account
            $session->remove('_security.oauth_connect.redirect_path');
            $session->remove('_security.oauth_connect.csrf_state');
            $session->remove('_security.oauth_connect.target_user');
            $session->remove('_security.oauth_connect.target_resource');
        }

        // Proceed with the usual flow of authentication...will eventually end up in
        //  ODR\OpenRepository\OAuthClientBundle\Security\Core\User\ODROAuthUserProvider::loadUserByOAuthUserResponse()
        return parent::attemptAuthentication($request);
    }
}
