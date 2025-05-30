<?php

/**
 * Open Data Repository Data Publisher
 * UpdateDataTree Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the form used for modifying DataTree properties via
 * the right slideout in DisplayTemplate
 *
 */

namespace ODR\AdminBundle\Form;

// Entities
use ODR\AdminBundle\Entity\DataTreeMeta;
// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;


class UpdateDataTreeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $is_link = $options['is_link'];

        $choices = array(
            'Edit directly' => DataTreeMeta::ALWAYS_EDIT,
            'Edit opens in new tab' => DataTreeMeta::LINK_EDIT,
            'Toggle Edit Lock' => DataTreeMeta::TOGGLE_EDIT_INACTIVE,
        );

        $builder->add(
            'multiple_allowed',
            CheckboxType::class,
            array(
                'required' => false,
                'label'  => 'Multiple Allowed',
            )
        );

        if ( $is_link ) {
            $builder->add(
                'edit_behavior',
                ChoiceType::class,
                array(
                    'choices' => $choices,
                    'choices_as_values' => true,
                    'label'  => 'Edit Behavior',
                    'expanded' => false,
                    'multiple' => false,
                    'placeholder' => false
                )
            );
        }
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'UpdateDataTreeForm';
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
        return 'UpdateDataTreeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTreeMeta'));

        // Required options should not have defaults set
        $resolver->setRequired('is_link');
    }
}
