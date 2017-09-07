<?php

/**
 * Open Data Repository Data Publisher
 * Owned Client Grant Extension
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The FOSOAuthServer bundle only creates an anonymous user token when processing the client_credentials grant type, and
 * the other grant types are unsuitable for general use by a machine.  However, a custom grant extension can mimic the
 * client_credentials grant type, since ODR has the capability to attach an "owner" when creating an OAuth client.
 *
 * @see https://github.com/FriendsOfSymfony/FOSOAuthServerBundle/blob/master/Resources/doc/adding_grant_extensions.md
 */

namespace ODR\OpenRepository\OAuthServerBundle\OAuth;

use FOS\OAuthServerBundle\Storage\GrantExtensionInterface;
use OAuth2\Model\IOAuth2Client;
use ODR\OpenRepository\OAuthServerBundle\Entity\Client;


class OwnedClientGrantExtension implements GrantExtensionInterface
{

    /**
     * {@inheritdoc}
     */
    public function checkGrantExtension(IOAuth2Client $client, array $inputData, array $authHeaders)
    {
        /** @var Client $client */
        $owner = $client->getOwner();

        if ($owner) {
            // Return the associated owner for this OAuth client
            return array(
                'data' => $owner
            );
        }

        // Otherwise, don't let this client log in
        return false;
    }
}
