<?php 

// src/ODR/AdminBundle/Form/DataTransformer/DataRecordFieldsToNumberTransformer.php
namespace ODR\AdminBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use ODR\TaskBundle\Entity\DataRecordFields;

/**
 * TODO: short description.
 * 
 * TODO: long description.
 * 
 */
class DataRecordFieldsToNumberTransformer implements DataTransformerInterface
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
     * Transforms an object (datarecordfields) to a string (number).
     *
     * @param  DataRecordFields|null $datarecordfields
     * @return string
     */
    public function transform($datarecordfields)
    {
        if (null === $datarecordfields) {
            return "";
        }

        return $datarecordfields->getId();
    }

    /**
     * Transforms a string (number) to an object (datarecordfields).
     *
     * @param  string $number
     *
     * @return DataRecordFields|null
     *
     * @throws TransformationFailedException if object (datarecordfields) is not found.
     */
    public function reverseTransform($number)
    {
        if (!$number) {
            return null;
        }

        $datarecordfields = $this->om
            ->getRepository('ODRAdminBundle:DataRecordFields')
            ->find($number)
        ;

        if (null === $datarecordfields) {
            throw new TransformationFailedException(sprintf(
                'An data record fields record with ID "%s" does not exist!',
                $number
            ));
        }

        return $datarecordfields;
    }
}
