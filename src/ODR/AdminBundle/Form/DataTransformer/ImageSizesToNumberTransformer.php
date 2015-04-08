<?php 

// src/ODR/AdminBundle/Form/DataTransformer/ImageSizesToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\ImageSizes;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class ImageSizesToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (imagesizes) to a string (number).
     *
     * @param  ImageSizes|null $imagesizes
     * @return string
     */
    public function transform($imagesizes)
    {
        if (null === $imagesizes) {
            return "";
        }

        return $imagesizes->getId();
    }

    /**
     * Transforms a string (number) to an object (imagesizes).
     *
     * @param  string $number
     *
     * @return ImageSizes|null
     *
     * @throws TransformationFailedException if object (imagesizes) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $imagesizes = $this->om
            ->getRepository('ODRAdminBundle:ImageSizes')
            ->find($number)
        ;

        if (null === $imagesizes) {
            throw new TransformationFailedException(sprintf(
                'An image sizes record with ID "%s" does not exist!',
                $number
            ));
        }

        return $imagesizes;
    }
}
