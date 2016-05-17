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
 */

namespace ODR\AdminBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


class UpdateThemeDatatypeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'display_type',
            'choice',
            array(
                'choices' => array('0' => 'Accordion', '1' => 'Tabbed', '2' => 'Dropdown', '3' => 'List'),
                'label'  => 'Display As',
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
        return 'UpdateThemeDatatypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\ThemeDatatype'));
    }
}
