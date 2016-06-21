<?php

/**
* Open Data Repository Data Publisher
* ODRAdmin Bundle
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Bundle definition for the ODR app
*/


namespace ODR\AdminBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\AdminBundle\DependencyInjection\Compiler\ValidatorPass;

class ODRAdminBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
