<?php

/**
* Open Data Repository Data Publisher
* ODROpenRepository Jupyterhub Bridge Bundle
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Contains the resources required to communicate with an install of Jupyterhub.
*/


namespace ODR\OpenRepository\JupyterhubBridgeBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\OpenRepository\SearchBundle\DependencyInjection\Compiler\ValidatorPass;

class ODROpenRepositoryJupyterhubBridgeBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
