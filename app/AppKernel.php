<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    /**
     * @inheritdoc
     */
    public function registerBundles()
    {
        $bundles = array(
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),
            // new Symfony\Bundle\AsseticBundle\AsseticBundle(),
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\SwiftmailerBundle\SwiftmailerBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),

	        new Http\HttplugBundle\HttplugBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new drymek\PheanstalkBundle\drymekPheanstalkBundle(),
            new dterranova\Bundle\CryptoBundle\dterranovaCryptoBundle(),
            new FOS\OAuthServerBundle\FOSOAuthServerBundle(),
            new FOS\UserBundle\FOSUserBundle(),
            new HWI\Bundle\OAuthBundle\HWIOAuthBundle(),
            new Knp\Bundle\MarkdownBundle\KnpMarkdownBundle(),
            new Snc\RedisBundle\SncRedisBundle(),

            new JMS\AopBundle\JMSAopBundle(),
            new JMS\DiExtraBundle\JMSDiExtraBundle($this),
            new JMS\SecurityExtraBundle\JMSSecurityExtraBundle(),

            new Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle(),

            new ODR\AdminBundle\ODRAdminBundle(),
            new ODR\OpenRepository\ApiBundle\ODROpenRepositoryApiBundle(),
            new ODR\OpenRepository\GraphBundle\ODROpenRepositoryGraphBundle(),
            new ODR\OpenRepository\JupyterhubBridgeBundle\ODROpenRepositoryJupyterhubBridgeBundle(),
            new ODR\OpenRepository\OAuthServerBundle\ODROpenRepositoryOAuthServerBundle(),
            new ODR\OpenRepository\OAuthClientBundle\ODROpenRepositoryOAuthClientBundle(),
            new ODR\OpenRepository\SearchBundle\ODROpenRepositorySearchBundle(),
            new ODR\OpenRepository\UserBundle\ODROpenRepositoryUserBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new Sensio\Bundle\DistributionBundle\SensioDistributionBundle();
            $bundles[] = new Sensio\Bundle\GeneratorBundle\SensioGeneratorBundle();
        }

        return $bundles;
    }

    /**
     * @inheritdoc
     */
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }
}
