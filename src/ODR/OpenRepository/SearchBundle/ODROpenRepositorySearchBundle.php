<?php

/**
* Open Data Repository Data Publisher
* ODROpenRepository Search Bundle
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The resources contained in this bundle allow users to
* search data stored inside ODR.
*/


namespace ODR\OpenRepository\SearchBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\OpenRepository\SearchBundle\DependencyInjection\Compiler\ValidatorPass;

class ODROpenRepositorySearchBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
