<?php

namespace ODR\OpenRepository\ApiBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ODR\OpenRepository\ApiBundle\DependencyInjection\Compiler\ValidatorPass;

class ODROpenRepositoryApiBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ValidatorPass());
    }
}
