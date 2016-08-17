<?php

namespace ODR\OpenRepository\GraphBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\OpenRepository\GraphBundle\DependencyInjection\Compiler\ValidatorPass;

class ODROpenRepositoryGraphBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
