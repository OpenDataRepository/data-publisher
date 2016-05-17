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
 */

namespace ODR\AdminBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


class UpdateThemeDatafieldForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'cssWidthMed',
            'choice',
            array(
                'choices' => array('1-4' => '25%', '1-3' => '33%', '1-2' => '50%', '2-3' => '66%', '3-4' => '75%', '1-1' => '100%'),
                'label'  => 'Med Width: ',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => false
            )
        );

        $builder->add(
            'cssWidthXL',
            'choice',
            array(
                'choices' => array('1-4' => '25%', '1-3' => '33%', '1-2' => '50%', '2-3' => '66%', '3-4' => '75%', '1-1' => '100%'),
                'label'  => 'XL Width: ',
                'expanded' => false,
                'multiple' => false,
                'empty_value' => false
            )
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
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\ThemeDatafield'));
    }
}
