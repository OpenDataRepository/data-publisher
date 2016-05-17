<?php

/**
 * Open Data Repository Data Publisher
 * UpdateTheme Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the Form used for modifying Theme properties via
 * the right slideout in DisplayTemplate.
 */

namespace ODR\AdminBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;


class UpdateThemeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'template_name',
            'text',
            array(
                'required' => true,
                'label' => 'Theme Name',
            )
        );

        $builder->add(
            'template_description',
            'text',
            array(
                'required' => true,
                'label' => 'Description Name',
            )
        );

        $builder->add(
            'is_default',
            'checkbox',
            array(
                'label'  => 'Is Default?',
                'required' => false
            )
        );
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateThemeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\Theme'));
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\ThemeMeta'));
    }
}
