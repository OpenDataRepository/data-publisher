<?php

/**
 *
 * Open Data Repository Data Publisher
 * Radio Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 */

namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use ODR\AdminBundle\Form\DataTransformer\DataFieldsToNumberTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;


class RadioOptionListForm extends AbstractType
{

    /**
    * TODO: short description.
    *
    *
    */
    public function __construct()
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $builder->add(
            'radio_option_list',
            TextareaType::class,
            array(
                'required' => true,
                'label' => 'Options (one per line)',
            )
        );

    }
    
    public function getName() {
        return 'RadioOptionListForm';
    }

    /**
     * TODO: short description.
     * 
     * @param OptionsResolverInterface $resolver 
     * 
     * @return TODO
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataFields'));
    }


}
