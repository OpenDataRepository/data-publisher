<?php

/**
 * Open Data Repository Data Publisher
 * ODR Token Generator
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Drop-in replacement for FOSUserBundle's fos_user.util.token_generator (removed with FOSUserBundle).
 * Aliased to the "fos_user.util.token_generator" service id so existing callers
 * ($this->container->get('fos_user.util.token_generator')->generateToken()) keep working. Produces a
 * URL-safe random token, same as the bundle did.
 */

namespace ODR\OpenRepository\UserBundle\Component\Service;

class ODRTokenGenerator
{
    /**
     * @return string a URL-safe random token
     */
    public function generateToken()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
