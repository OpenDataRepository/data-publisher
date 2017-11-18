<?php

/**
 * Open Data Repository Data Publisher
 * Replace OAuthListener Compiler Pass
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * HWIOAuthBundle and their "connect" functionality doesn't playing nicely with FOSUserBundle, and the only "easily"
 * accessible places to override the HWIOAuthBundle don't have the information required to allow ODR to connect
 * ODR user accounts with OAuth accounts.
 *
 * Therefore, a compiler pass is necessary to change which class Symfony calls attemptAuthorization() on, so ODR
 * can actually intercept the actual OAuth login response.
 */

namespace ODR\OpenRepository\OAuthClientBundle\DependencyInjection\CompilerPass;

// ODR
use ODR\OpenRepository\OAuthClientBundle\Security\Http\Firewall\ODROAuthListener;
// Symfony
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;


class ReplaceOAuthListenerCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        // Overwrite this parameter from the HWIOAuthBundle, set at  hwi/oauth-bundle/Resources/config/oauth.xml
        $container->setParameter('hwi_oauth.authentication.listener.oauth.class', ODROAuthListener::class);
    }
}
