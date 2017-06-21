<?php

/**
 * Open Data Repository Data Publisher
 * ODROpenRepository OAuth Client Bundle
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This bundle is declared as a child of the HWIOAuthBundle, and includes a compiler pass to change the OAuth
 * authentication listener that the HWIOAuthBundle has hardcoded.
 */

namespace ODR\OpenRepository\OAuthClientBundle;

// ODR
use ODR\OpenRepository\OAuthClientBundle\DependencyInjection\CompilerPass\ReplaceOAuthListenerCompilerPass;
// Symfony
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;


class ODROpenRepositoryOAuthClientBundle extends Bundle
{

    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new ReplaceOAuthListenerCompilerPass());
    }


    /**
     * {@inheritdoc}
     */
    public function getParent()
    {
        return 'HWIOAuthBundle';
    }
}
