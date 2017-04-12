<?php

/**
 * Open Data Repository Data Publisher
 * ODROpenRepository OAuth Bundle
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This bundle is declared as a child of the FOSOAuthServerBundle, to
 * permit changing a few templates in that bundle
 */

namespace ODR\OpenRepository\OAuthBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ODROpenRepositoryOAuthBundle extends Bundle
{

    /**
     * @inheritdoc
     */
    public function getParent()
    {
        return 'FOSOAuthServerBundle';
    }
}

