<?php

/**
 * Open Data Repository Data Publisher
 * UpdateThemeDatatype Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the Form used for modifying ThemeDatatype properties via
 * the right slideout in DisplayTemplate.
 *
 */

namespace ODR\AdminBundle\Form;

// ODR
use ODR\AdminBundle\Form\Type\DatatypeType;
use ODR\AdminBundle\Form\Type\ThemeElementType;
// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;


class UpdateThemeDatatypeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'dataType',
            DatatypeType::class
        );

        $builder->add(
            'themeElement',
            ThemeElementType::class
        );

        $builder->add(
            'display_type',
            ChoiceType::class,
            array(
                'choices' => array(
                    'Accordion' => '0',
                    'Tabbed' => '1',
                    'Dropdown' => '2',
                    'List' => '3'
                ),
                'choices_as_values' => true,
                'label'  => 'Display As',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => false
            )
        );
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateThemeDatatypeForm';
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
        return 'UpdateThemeDatatypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\ThemeDatatype'));
    }
}
