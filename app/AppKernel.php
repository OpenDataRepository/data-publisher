<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use ODR\AdminBundle\DependencyInjection\Compiler\DoctrineEventSubscriberCompatPass;

class AppKernel extends Kernel
{
    /**
     * Symfony 7's doctrine-bridge no longer wires "doctrine.event_subscriber" tags, so translate
     * them to "doctrine.event_listener" before that pass runs (high priority = earlier).
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new DoctrineEventSubscriberCompatPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            100
        );
    }

    /**
     * @inheritdoc
     */
    public function registerBundles(): iterable
    {
        $bundles = array(
            // new Symfony\Bundle\AsseticBundle\AsseticBundle(),
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),

	        new Http\HttplugBundle\HttplugBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle(),
            new Twig\Extra\TwigExtraBundle\TwigExtraBundle(),
            new Snc\RedisBundle\SncRedisBundle(),

            new Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle(),

            new ODR\AdminBundle\ODRAdminBundle(),
            new ODR\OpenRepository\ApiBundle\ODROpenRepositoryApiBundle(),
            new ODR\OpenRepository\GraphBundle\ODROpenRepositoryGraphBundle(),
            new ODR\OpenRepository\JupyterhubBridgeBundle\ODROpenRepositoryJupyterhubBridgeBundle(),
            new ODR\OpenRepository\SearchBundle\ODROpenRepositorySearchBundle(),
            new ODR\OpenRepository\UserBundle\ODROpenRepositoryUserBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
        }

        return $bundles;
    }

    /**
     * Symlinked instances (e.g. dev.rruff.net) define ODR_APP_DIR — the
     * *unresolved* path to that instance's app/ directory — so cache, logs,
     * and config resolve to the linked instance rather than the shared source
     * tree the symlink points at. SF7's default getProjectDir() walks up to
     * composer.json via ReflectionObject, which follows the symlink; overriding
     * it here (and only when ODR_APP_DIR is defined) redirects everything that
     * derives from the project dir. Re-implements develop 12cbb3d7's
     * getRootDir() override, which SF7 removed. No-op on normal installs.
     *
     * @inheritdoc
     */
    public function getProjectDir(): string
    {
        if (defined('ODR_APP_DIR'))
            return dirname(ODR_APP_DIR);

        return parent::getProjectDir();
    }

    /**
     * @inheritdoc
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        // Load config from the linked instance's app/config when symlinked
        // (see getProjectDir() above); otherwise from this file's directory.
        $config_dir = defined('ODR_APP_DIR') ? ODR_APP_DIR : __DIR__;
        $loader->load($config_dir.'/config/config_'.$this->getEnvironment().'.yml');
    }
}
