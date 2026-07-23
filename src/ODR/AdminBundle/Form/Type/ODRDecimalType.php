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
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\AdminBundle\Form\DataTransformer\ODRDecimalToLocalizedStringTransformer;
// Symfony
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class ODRDecimalType extends TextType
{

//    /**
//     * {@inheritdoc}
//     */
//    #[\Override]
//    public function buildForm(FormBuilderInterface $builder, array $options): void
//    {
//        $builder->addViewTransformer(
//            new ODRDecimalToLocalizedStringTransformer(
//                $options['scale'],
//                $options['grouping'],
//                $options['rounding_mode']
//            )
//        );
//    }


    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
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
    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'odr_decimal';
    }


    /**
     * Override to bypass the parent transform function...it mostly seems to be used to run an
     * is_numeric() check (which won't necessarily pass due to this field allowing values like
     * "1.23(4)") and apply a decimal place formatter (which doesn't apply to ODR's data either)
     *
     * {@inheritDoc}
     */
    #[\Override]
    public function transform($data): mixed
    {
        // The parent function throws an error when it receives the empty string, but apparently
        //  will happily convert <null> values into the empty string...
        $data = trim($data);
        return $data;
    }


    /**
     * Runs a validation regex on the given string value from the form before Symfony casts the it
     * to an integer as part of converting "view" data into "normalized" data.
     *
     * @see http://symfony.com/doc/2.8/form/data_transformers.html#about-model-and-view-transformers
     *
     * {@inheritdoc}
     */
    #[\Override]
    public function reverseTransform($data): mixed
    {
        if ( !ValidUtility::isValidDecimal($data) )
            throw new TransformationFailedException();

        // If the given value is acceptable, just return the string itself...the Decimal entity will
        //  deal with actually storing it...
        return $data;
    }
}
