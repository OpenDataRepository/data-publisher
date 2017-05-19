<?php

/**
 * Open Data Repository Data Publisher
 * ODROpenRepository OAuth Client Bundle
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This bundle is declared as a child of the HWIOAuthBundle.
 */

namespace ODR\OpenRepository\OAuthClientBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ODROpenRepositoryOAuthClientBundle extends Bundle
{

    /**
     * @inheritdoc
     */
    public function getParent()
    {
        return 'HWIOAuthBundle';
    }
}

