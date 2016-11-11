<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Type
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Defines a reusable DatafieldType class for use by a Symfony Form.
 *
 */

namespace ODR\AdminBundle\Form\Type;

// ODR
use ODR\AdminBundle\Form\DataTransformer\DataFieldToNumberTransformer;
// Doctrine
use Doctrine\Common\Persistence\ObjectManager;
// Symfony
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class DatafieldType extends AbstractType
{
    private $manager;

    /**
     * DatafieldType constructor.
     *
     * @param ObjectManager $manager
     */
    public function __construct(ObjectManager $manager)
    {
        $this->manager = $manager;
    }


    /**
     * @inheritdoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $transformer = new DataFieldToNumberTransformer($this->manager);
        $builder->addModelTransformer($transformer);
    }


    /**
     * @inheritdoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'invalid_message' => 'This Datafield does not exist',
        ));
    }


    /**
     * @inheritdoc
     */
    public function getParent()
    {
        return HiddenType::class;
    }
}