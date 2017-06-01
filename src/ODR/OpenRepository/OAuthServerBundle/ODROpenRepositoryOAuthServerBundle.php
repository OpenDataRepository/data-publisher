<?php

/**
 * Open Data Repository Data Publisher
 * ODROpenRepository OAuth Server Bundle
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This bundle is declared as a child of the FOSOAuthServerBundle, to
 * permit changing a few templates in that bundle
 */

namespace ODR\OpenRepository\OAuthServerBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ODROpenRepositoryOAuthServerBundle extends Bundle
{

    /**
     * @inheritdoc
     */
    public function getParent()
    {
        return 'FOSOAuthServerBundle';
    }
}

