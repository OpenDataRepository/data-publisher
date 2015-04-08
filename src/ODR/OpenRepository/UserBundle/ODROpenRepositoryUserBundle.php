<?php

namespace ODR\OpenRepository\UserBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ODROpenRepositoryUserBundle extends Bundle
{
    // the right way to do it?
    public function getParent()
    {
        return 'FOSUserBundle';
    }
}
