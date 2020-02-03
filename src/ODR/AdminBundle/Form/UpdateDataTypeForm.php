<?php

/**
 * Open Data Repository Data Publisher
 * UpdateDataType Form
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Builds the Form used for modifying Datatype properties via
 * the right slideout in DisplayTemplate.
 *
 */

namespace ODR\AdminBundle\Form;

// ODR
use ODR\AdminBundle\Entity\DataFields;
// Symfony Forms
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
// Symfony Form classes
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
//
use Doctrine\ORM\EntityRepository;


class UpdateDataTypeForm extends AbstractType
{

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // Load values passed in via constructor
        $datatype_id = $options['datatype_id'];
        $is_top_level = $options['is_top_level'];
        $is_link = $options['is_link'];
        $sortfield_datatypes = $options['sortfield_datatypes'];

        // None of these should be changable if viewing the properties of a linked datatype...
        if ($is_link == true)
            return;

        $builder->add(
            'short_name',
            HiddenType::class,
            array(
                'required' => true,
                'label' => 'Short Name',
            )
        );

        $builder->add(
            'long_name',
            TextType::class,
            array(
                'required' => true,
                'label' => 'Dataset Name',
            )
        );

        $builder->add(
            'description',
            TextareaType::class,
            array(
                'required' => true,
                'label' => 'Description',
            )
        );

        $builder->add(
            'externalIdField',
            EntityType::class,
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($datatype_id) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                                ->where('dfm.is_unique = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype_id);
                },

                'label' => 'External ID Field',
                'choice_label' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => 'NONE',
            )
        );

        $builder->add(
            'nameField',
            EntityType::class,
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($datatype_id) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                                ->where('ft.canBeSortField = 1 AND df.dataType = ?1')
                                ->setParameter(1, $datatype_id);
                },

                'label' => 'Name Field',
                'choice_label' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => 'NONE',
            )
        );

        $builder->add(
            'sortField',
            EntityType::class,
            array(
                'class' => 'ODR\AdminBundle\Entity\DataFields',
                'query_builder' => function(EntityRepository $er) use ($sortfield_datatypes) {
                    return $er->createQueryBuilder('df')
                                ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                                ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                                ->where('ft.canBeSortField = 1 AND df.dataType IN (?1)')
                                ->setParameter(1, $sortfield_datatypes);
                },

                'group_by' => function($df, $key, $value) use ($datatype_id) {
                    /** @var DataFields $df */
                    return $df->getDataType()->getShortName();
                },

                'label' => 'Sort Field',
                'choice_label' => 'field_name',
                'expanded' => false,
                'multiple' => false,
                'placeholder' => 'NONE',
            )
        );

        if ($is_top_level) {
            // Only provide options to change these properties if datatype is top-level...
            $builder->add(
                'backgroundImageField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function(EntityRepository $er) use ($datatype_id) {
                        return $er->createQueryBuilder('df')
                            ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                            ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                            ->where('ft.typeName = ?1 AND df.dataType = ?2')
                            ->setParameter(1, 'Image')
                            ->setParameter(2, $datatype_id);
                    },

                    'label' => 'Background Image Field',
                    'choice_label' => 'field_name',
                    'expanded' => false,
                    'multiple' => false,
                    'placeholder' => 'NONE',
                )
            );

            $builder->add(
                'searchSlug',
                TextType::class,
                array(
                    'label' => 'Search Abbreviation',
                )
            );
        }

        $builder->add(
            'newRecordsArePublic',
            CheckboxType::class,
            array(
                'label'  => 'Created Records default to public',
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
        return 'UpdateDataTypeForm';
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
        return 'UpdateDataTypeForm';
    }


    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            array(
                'data_class' => 'ODR\AdminBundle\Entity\DataTypeMeta',
            )
        );

        // Required options should not have defaults set
        $resolver->setRequired('datatype_id');
        $resolver->setRequired('is_top_level');
        $resolver->setRequired('is_link');

        $resolver->setRequired('sortfield_datatypes');
    }
}
