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
*/

namespace ODR\AdminBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Doctrine\ORM\EntityRepository;

class UpdateDataTreeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'multiple_allowed',
            'checkbox',
            array(
                'required' => false,
                'label'  => 'Multiple Allowed',
            )
        );
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
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
//        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTree'));
        $resolver->setDefaults(array('data_class' => 'ODR\AdminBundle\Entity\DataTreeMeta'));
    }


}
