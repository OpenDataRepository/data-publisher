<?php

/**
 * Open Data Repository Data Publisher
 * IntegerValue Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\AdminBundle\Form;

// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\Options;
// Symfony Form classes
use Symfony\Component\Form\Extension\Core\Type\IntegerType;


class IntegerValueForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'value',
            IntegerType::class,
            array(
                'required' => false,
            )
        );
    }


    /**
     * Returns the name of this type.
     *
     * @return string The name of this type
     */
    public function getName() {
        return 'IntegerValueForm';
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
        return 'IntegerValueForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'ODR\AdminBundle\Entity\IntegerValue',
            'datarecord_id' => '',
            'datafield_id' => '',
        ));

        // @see http://symfony.com/doc/2.8/components/options_resolver.html#default-values-that-depend-on-another-option
        $resolver->setDefault('csrf_token_id', function(Options $option) {
            $dr_id = $option['datarecord_id'];
            $df_id = $option['datafield_id'];

            return 'IntegerValueForm_'.$dr_id.'_'.$df_id;
        });
    }
}
