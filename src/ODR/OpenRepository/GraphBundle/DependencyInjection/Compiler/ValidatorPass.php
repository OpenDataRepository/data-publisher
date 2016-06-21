<?php
/**
 * Created by PhpStorm.
 * User: nate
 * Date: 6/7/16
 * Time: 11:12 AM
 */
namespace ODR\OpenRepository\GraphBundle\DependencyInjection\Compiler;

use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\Config\Resource\DirectoryResource;

class ValidatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $validatorBuilder = $container->getDefinition('validator.builder');
        $validatorFiles = array();
        $finder = new Finder();

        foreach ($finder->files()->in(__DIR__ . '/../../Resources/config/validation') as $file) {
            $validatorFiles[] = $file->getRealPath();
        }

        $validatorBuilder->addMethodCall('addYamlMappings', array($validatorFiles));

        // add resources to the container to refresh cache after updating a file
        $container->addResource(new DirectoryResource(__DIR__ . '/../../Resources/config/validation'));
    }
}