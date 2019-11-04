<?php 

// src/ODR/AdminBundle/Form/DataTransformer/ShortVarcharToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\ShortVarchar;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class ShortVarcharToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (shortvarchar) to a string (number).
     *
     * @param  ShortVarchar|null $shortvarchar
     * @return string
     */
    public function transform($shortvarchar)
    {
        if (null === $shortvarchar) {
            return "";
        }

        return $shortvarchar->getId();
    }

    /**
     * Transforms a string (number) to an object (shortvarchar).
     *
     * @param  string $number
     *
     * @return ShortVarchar|null
     *
     * @throws TransformationFailedException if object (shortvarchar) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $shortvarchar = $this->om
            ->getRepository('ODRAdminBundle:ShortVarchar')
            ->find($number)
        ;

        if (null === $shortvarchar) {
            throw new TransformationFailedException(sprintf(
                'An short varchar record with ID "%s" does not exist!',
                $number
            ));
        }

        return $shortvarchar;
    }
}
