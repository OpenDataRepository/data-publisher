<?php 

// src/ODR/AdminBundle/Form/DataTransformer/FieldTypeToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\FieldType;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class FieldTypeToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (fieldtype) to a string (number).
     *
     * @param  FieldType|null $fieldtype
     * @return string
     */
    public function transform($fieldtype)
    {
        if (null === $fieldtype) {
            return "";
        }

        return $fieldtype->getId();
    }

    /**
     * Transforms a string (number) to an object (fieldtype).
     *
     * @param  string $number
     *
     * @return FieldType|null
     *
     * @throws TransformationFailedException if object (fieldtype) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $fieldtype = $this->om
            ->getRepository('ODRAdminBundle:FieldType')
            ->find($number)
        ;

        if (null === $fieldtype) {
            throw new TransformationFailedException(sprintf(
                'An field type record with ID "%s" does not exist!',
                $number
            ));
        }

        return $fieldtype;
    }
}
