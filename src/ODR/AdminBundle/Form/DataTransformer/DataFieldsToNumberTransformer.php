<?php 

// src/ODR/AdminBundle/Form/DataTransformer/DataFieldsToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\DataFields;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class DataFieldsToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (datafields) to a string (number).
     *
     * @param  DataFields|null $datafields
     * @return string
     */
    public function transform($datafields)
    {
        if (null === $datafields) {
            return "";
        }

        return $datafields->getId();
    }

    /**
     * Transforms a string (number) to an object (datafields).
     *
     * @param  string $number
     *
     * @return DataFields|null
     *
     * @throws TransformationFailedException if object (datafields) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $datafields = $this->om
            ->getRepository('ODRAdminBundle:DataFields')
            ->find($number)
        ;

        if (null === $datafields) {
            throw new TransformationFailedException(sprintf(
                'An data fields record with ID "%s" does not exist!',
                $number
            ));
        }

        return $datafields;
    }
}
