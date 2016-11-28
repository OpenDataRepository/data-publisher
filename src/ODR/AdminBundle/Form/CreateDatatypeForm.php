<?php

/**
 * Open Data Repository Data Publisher
 * CreateDataType Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;


class CreateDatatypeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'shortName', 
            TextType::class,
            array(
                'required' => true,
                'label'  => 'Short Name',
                'attr' => array(
                    'maxlength' => 32,
                ),
            )
        );

        $builder->add(
            'longName', 
            TextType::class,
            array(
                'required' => true,
                'label'  => 'Full TypeName',
                'attr' => array(
                    'maxlength' => 32,
                ),
            )
        );

        $builder->add(
            'description',
            TextareaType::class,
            array(
                'required' => true,
                'label' => 'Enter a description for this database.'
            )
        );

        // Adding a non-tracked field to allow master template creation.
        $builder->add(
            'is_master_type',
            HiddenType::class,
            array(
                'mapped' => false,
                'data' => 0
            )
        );

        $builder->add('save', SubmitType::class);

/*
        $builder->add(
            'description', 
            'text', 
            array(
                'required' => true,
                'label'  => 'Description',
            )
        );
*/
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'CreateDatatypeForm';
    }


    /**
     * Returns the prefix of the template block name for this type.
     *
     * The block prefixes default to the underscored short class name with
     * the "Type" suffix removed (e.g. "UserProfileType" => "user_profile").
     *
     * @return string The prefix of the template block name
     */
    public function getBlockPrefix()
    {
        return 'CreateDatatypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataType'));
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTypeMeta'));
    }


}
