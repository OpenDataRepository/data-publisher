<?php

/**
* Open Data Repository Data Publisher
* ODROpenRepository User Bundle
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This bundle is declared as a child of the FriendsOfSymfony
* bundle, to permit extending of the password reset/change
* functionality.
*/


namespace ODR\OpenRepository\UserBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\OpenRepository\UserBundle\DependencyInjection\Compiler\ValidatorPass;

class ODROpenRepositoryUserBundle extends Bundle
{
    public function getParent()
    {
        return 'FOSUserBundle';
    }

    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
