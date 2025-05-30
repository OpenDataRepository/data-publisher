<?php

/**
 * Open Data Repository Data Publisher
 * UpdateThemeDatafield Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the Form used for modifying ThemeDatafield properties via
 * the right slideout in DisplayTemplate.
 *
 */

namespace ODR\AdminBundle\Form;

// ODR
use ODR\AdminBundle\Form\Type\DatafieldType;
use ODR\AdminBundle\Form\Type\ThemeElementType;
// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;


class UpdateThemeDatafieldForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'dataField',
            DatafieldType::class
        );

        $builder->add(
            'themeElement',
            ThemeElementType::class
        );

        // These properties don't get symfony form elements, because they're modified/saved through
        //  various non-form UI elements/actions
        $builder->add(
            'cssWidthMed',
            HiddenType::class
        );
        $builder->add(
            'cssWidthXL',
            HiddenType::class
        );
        $builder->add(
            'hidden',
            HiddenType::class
        );
        $builder->add(
            'hideHeader',
            HiddenType::class
        );
        $builder->add(
            'useIconInTables',
            HiddenType::class
        );
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateThemeDatafieldForm';
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
        return 'UpdateThemeDatafieldForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'ODR\AdminBundle\Entity\ThemeDataField',
            )
        );
    }
}
