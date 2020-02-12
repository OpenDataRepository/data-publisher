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
        $is_metadata = $options['is_metadata'];
        $is_metadata_template = $options['is_metadata_template'];
        $sortfield_datatypes = $options['sortfield_datatypes'];

        // None of these should be changable if viewing the properties of a linked datatype...
        if ($is_link == true)
            return;

        if ( !$is_metadata ) {
            // Metadata datatypes shouldn't allow their name or description to be changed directly
            // Top-level/child datatypes and templates need to be able to though
            $builder->add(
                'short_name',
                TextType::class,
                array(
                    'required' => true,
                    'label' => 'Dataset Name',
                )
            );

            // This isn't displayed, but still needs to be synched with short_name...
            $builder->add(
                'long_name',
                HiddenType::class,
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
        }

        if ( !$is_metadata && !$is_metadata_template ) {
            // Metadata datatypes don't allow additional top-level records to be created
            // As such, metadata templates also have no use for this property
            $builder->add(
                'newRecordsArePublic',
                CheckboxType::class,
                array(
                    'label' => 'Created Records default to public',
                    'required' => false
                )
            );
        }

        if ( !$is_metadata && !$is_metadata_template ) {
            // Metadata datatypes only have one record, so an external_id field is useless
            // As such, metadata templates also have no use for this field
            $builder->add(
                'externalIdField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($datatype_id) {
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
        }

        if ( !$is_metadata && !$is_metadata_template ) {
            // Metadata datatypes only have one record, so a name field is useless
            // As such, metadata templates also have no use for this field
            $builder->add(
                'nameField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($datatype_id) {
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
        }

        if ( !$is_metadata && !$is_metadata_template ) {
            // Metadata datatypes only have one record, so a sort field is useless
            // As such, metadata templates also have no use for this field
            $builder->add(
                'sortField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($sortfield_datatypes) {
                        return $er->createQueryBuilder('df')
                            ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                            ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                            ->where('ft.canBeSortField = 1 AND df.dataType IN (?1)')
                            ->setParameter(1, $sortfield_datatypes);
                    },

                    'group_by' => function ($df, $key, $value) use ($datatype_id) {
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
        }

        if ( !$is_metadata && !$is_metadata_template && $is_top_level ) {
            // backgroundImage fields only make sense on non-metadata top-level datatypes
            $builder->add(
                'backgroundImageField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($datatype_id) {
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
        }

        if ( $is_metadata || $is_metadata_template ) {
            // metadata name field only makes sense for a metadata datatype, though it's only
            //  allowed to be changed on a metadata template
            $builder->add(
                'metadataNameField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($datatype_id) {
                        return $er->createQueryBuilder('df')
                            ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                            ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                            ->where('ft.canBeMetadataNameField = 1 AND df.dataType = ?1')
                            ->setParameter(1, $datatype_id);
                    },

                    'label' => 'Metadata Name Field',
                    'choice_label' => 'field_name',
                    'expanded' => false,
                    'multiple' => false,

                    // This field can't be blank for a metadata datatype
                    'required' => true,
//                    'placeholder' => 'NONE',
                )
            );
        }

        if ( $is_metadata || $is_metadata_template ) {
            // metadata description field only makes sense for a metadata datatype, though it's
            //  only allowed to be changed on a metadata template
            $builder->add(
                'metadataDescField',
                EntityType::class,
                array(
                    'class' => 'ODR\AdminBundle\Entity\DataFields',
                    'query_builder' => function (EntityRepository $er) use ($datatype_id) {
                        return $er->createQueryBuilder('df')
                            ->leftJoin('ODRAdminBundle:DataFieldsMeta', 'dfm', 'WITH', 'dfm.dataField = df')
                            ->leftJoin('ODRAdminBundle:FieldType', 'ft', 'WITH', 'dfm.fieldType = ft')
                            ->where('ft.canBeMetadataDescField = 1 AND df.dataType = ?1')
                            ->setParameter(1, $datatype_id);
                    },

                    'label' => 'Metadata Description Field',
                    'choice_label' => 'field_name',
                    'expanded' => false,
                    'multiple' => false,

                    // This field can't be blank for a metadata datatype
                    'required' => true,
//                    'placeholder' => 'NONE',
                )
            );
        }


        if ( !$is_metadata && !$is_metadata_template && $is_top_level ) {
            // search slugs only make sense on non-metadata top-level datatypes
            $builder->add(
                'searchSlug',
                TextType::class,
                array(
                    'label' => 'Search Abbreviation',
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
        $resolver->setRequired('is_metadata');
        $resolver->setRequired('is_metadata_template');

        $resolver->setRequired('sortfield_datatypes');
    }
}
