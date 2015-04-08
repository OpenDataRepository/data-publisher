<?php 

// src/ODR/AdminBundle/Form/DataTransformer/FieldTypeToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\RenderPlugin;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class RenderPluginToNumberTransformer implements DataTransformerInterface
{
    /**
     * @var ObjectManager
     */
    private $om;

    /**
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        $this->om = $om;
    }

    /**
     * Transforms an object (renderplugin) to a string (number).
     *
     * @param  RenderPlugin|null $renderplugin
     * @return string
     */
    public function transform($renderplugin)
    {
        if (null === $renderplugin) {
            return "";
        }

        return $renderplugin->getId();
    }

    /**
     * Transforms a string (number) to an object (renderplugin).
     *
     * @param  string $number
     *
     * @return RenderPlugin|null
     *
     * @throws TransformationFailedException if object (renderplugin) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $renderplugin = $this->om
            ->getRepository('ODRAdminBundle:RenderPlugin')
            ->find($number)
        ;

        if (null === $renderplugin) {
            throw new TransformationFailedException(sprintf(
                'An field type record with ID "%s" does not exist!',
                $number
            ));
        }

        return $renderplugin;
    }
}
