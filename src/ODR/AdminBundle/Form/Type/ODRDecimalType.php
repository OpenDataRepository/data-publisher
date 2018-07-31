<?php

/**
 * Open Data Repository Data Publisher
 * ODR DecimalType
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Does roughly the same thing as the ODRIntegerType to inject a regex check before Symfony
 * transforms the form values.
 */

namespace ODR\AdminBundle\Form\Type;

// ODR
use ODR\AdminBundle\Form\DataTransformer\ODRDecimalToLocalizedStringTransformer;
// Symfony
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ODRDecimalType extends NumberType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->addViewTransformer(
            new ODRDecimalToLocalizedStringTransformer(
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
        return 'odr_decimal';
    }
}
