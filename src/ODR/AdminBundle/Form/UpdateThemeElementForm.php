<?php

/**
* Open Data Repository Data Publisher
* UpdateThemeElement Form
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* Holds pureCSS width options for ThemeElements.
*/

//ODR/AdminBundle/Forms/UpdateDataTypeForm.class.php
namespace ODR\AdminBundle\Form;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Doctrine\ORM\EntityRepository;

class UpdateThemeElementForm extends AbstractType
{

    protected $theme_element;
    public function __construct (\ODR\AdminBundle\Entity\ThemeElement $theme_element) {
        $this->theme_element = $theme_element;
    }


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
    
    public function getName() {
        return 'UpdateThemeElementForm';
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
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\ThemeElement'));
    }


}
