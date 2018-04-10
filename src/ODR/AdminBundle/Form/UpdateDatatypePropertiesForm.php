<?php

/**
 * Open Data Repository Data Publisher
 * UpdateDatatypeProperties Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;


class UpdateDatatypePropertiesForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $builder->add(
            'longName',
            HiddenType::class,
            array(
                'required' => true,
                'label'  => 'Long Name',
                'attr' => array(
                    'maxlength' => 255,
                    // underlying database column has a max length of 32 characters
                ),
            )
        );

        $builder->add(
            'description',
            HiddenType::class,
            array(
                'required' => true,
                'label' => 'Enter a description for this database.'
            )
        );

    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateDatatypePropertiesForm';
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
        return 'UpdateDatatypePropertiesForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'ODR\AdminBundle\Entity\DataTypeMeta',
                'form_settings' => null,
            )
        );
    }
}
