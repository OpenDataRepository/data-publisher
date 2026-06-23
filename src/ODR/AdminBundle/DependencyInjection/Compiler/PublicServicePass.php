<?php

/**
 * Open Data Repository Data Publisher
 * PublicServicePass Compiler Pass
 * (C) 2026
 * Released under the GPLv2
 *
 * Symfony 4 makes services private by default. This codebase fetches a number
 * of third-party/framework services directly from the container at runtime
 * (e.g. $this->container->get('pheanstalk') in console workers and
 * controllers). Those services must be public for that to work. Rather than
 * editing each (vendor-defined) service, this pass marks the required ones
 * public during container compilation.
 */

namespace ODR\AdminBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PublicServicePass implements CompilerPassInterface
{
    /**
     * Service ids that are fetched directly from the container and therefore
     * must remain public after compilation.
     *
     * @var string[]
     */
    private $publicServiceIds = [
        'pheanstalk',
        'memcached',
        'logger',
        'fos_user.user_manager',
        'snc_redis.default',
    ];

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        foreach ($this->publicServiceIds as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            } elseif ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
