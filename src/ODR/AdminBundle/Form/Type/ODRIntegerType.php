<?php

/**
 * Open Data Repository Data Publisher
 * ODR IntegerType
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Overrides Symfony's built-in Integer type in order to use a slightly modified data transformer.
 */

namespace ODR\AdminBundle\Form\Type;

// ODR
use ODR\AdminBundle\Form\DataTransformer\ODRIntegerToLocalizedStringTransformer;
// Symfony
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ODRIntegerType extends IntegerType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addViewTransformer(
            new ODRIntegerToLocalizedStringTransformer(
                $options['scale'],
                $options['grouping'],
                $options['rounding_mode']
            )
        );
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);
    }


    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->getBlockPrefix();
    }


    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'odr_integer';
    }
}
