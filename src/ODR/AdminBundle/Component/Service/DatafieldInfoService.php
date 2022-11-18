<?php

/**
 * Open Data Repository Data Publisher
 * Datafield Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Holds a number of utility functions to compute properties of datafields that have implications
 * elsewhere in ODR.  Attempts to use cache entries as much as possible.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataTypeSpecialFields;
use ODR\AdminBundle\Entity\FieldType;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class DatafieldInfoService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * DatafieldInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->logger = $logger;
    }


    /**
     * Traverses the cached datatype array to determine several datafield properties that are useful
     * to ODR...such as render plugin information and whether the datafield can be deleted or not.
     *
     * @param array $datatype_array
     * @param int|null $datafield_id
     *
     * @return array
     */
    public function getDatafieldProperties($datatype_array, $datafield_id = null)
    {
        $datafield_properties = array();

        // ----------------------------------------
        // Load properties for all datafields by default, or a single datafield if defined
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                if ( is_null($datafield_id) || $df_id === $datafield_id ) {
                    $dfm = $df['dataFieldMeta'];

                    // Store these values directly in the array
                    $typeclass = $dfm['fieldType']['typeClass'];
//                    $typename = $dfm['fieldType']['typeName'];


                    // These values require a bit of calculation first...
                    $has_render_plugin = !empty( $df['renderPluginInstances'] );
                    // TODO - can't copy fields with render plugins?  why was that again?
                    $can_copy = true;
                    if ( $typeclass === 'Radio' || $typeclass === 'Tag' || $has_render_plugin )
                        $can_copy = false;

                    $is_public = true;
                    if ( $dfm['publicDate']->format('Y-m-d') === '2200-01-01')
                        $is_public = false;

                    $has_tag_hierarchy = false;
                    if ( isset($df['tagTree']) && !empty($df['tagTree']) )
                        $has_tag_hierarchy = true;


                    // Whether the datafield can be deleted or not can just barely be computed from
                    //  the cached_datatype array (except for the tracked job restrictions)...
                    $delete_info = self::canDeleteDatafield($datatype_array, $dt_id, $df_id);
                    $can_delete = $delete_info['can_delete'];
                    $delete_message = $delete_info['delete_message'];

                    // Same with the ability to change the field's public status
                    $public_info = self::canChangePublicStatus($datatype_array, $dt_id, $df_id);
                    $can_change_public_status = $public_info['can_change_public_status'];
//                    $public_status_message = $public_info['public_status_message'];


                    // Store these properties in an array
                    $datafield_properties[$df_id] = array(
                        'can_copy' => $can_copy,
                        'can_delete' => $can_delete,
                        'delete_message' => $delete_message,

                        'is_public' => $is_public,
                        'can_change_public_status' => $can_change_public_status,
//                        'public_status_message' => $public_status_message,

                        'has_tag_hierarchy' => $has_tag_hierarchy,
                    );

                    // There are a couple more properties that can be required as a result of a
                    //  render plugin
                    $render_plugin_properties = self::getRenderPluginProperties($datatype_array, $dt_id, $df_id);
                    foreach ($render_plugin_properties as $key => $value)
                        $datafield_properties[$df_id][$key] = $value;
                }
            }
        }


        // ----------------------------------------
        // If only a single datafield was requested, return just its properties...otherwise, return
        //  the entire array
        if ( !is_null($datafield_id) )
            return $datafield_properties[$datafield_id];
        else
            return $datafield_properties;
    }


    /**
     * Helper function to determine whether a datafield has some property that should prevent deletion.
     *
     * Ongoing background jobs could also block deletion...but those aren't technically a property
     * of the datafield, and the UI is easier to work with if it ignores background jobs.
     *
     * @param array $datatype_array
     * @param int $datatype_id
     * @param int $datafield_id
     *
     * @return array
     */
    public function canDeleteDatafield($datatype_array, $datatype_id, $datafield_id)
    {
        $dt = $datatype_array[$datatype_id];
        $dtm = $dt['dataTypeMeta'];
        $df = $dt['dataFields'][$datafield_id];

        // Shouldn't delete a datafield that's derived from a master template
        if ( !is_null($df['templateFieldUuid']) ) {
            return array(
                'can_delete' => false,
                'delete_message' => "This datafield can't be deleted because it's required by a Master Template"
            );
        }

        // Also shouldn't delete a datafield that's being used as an external_id, metadata_name,
        //  or metadata_desc field for a datatype
        if ( !is_null($dtm['externalIdField']) && $dtm['externalIdField']['id'] === $datafield_id ) {
            return array(
                'can_delete' => false,
                'delete_message' => "This datafield can't be deleted because it's being used as the Datatype's external ID field"
            );
        }

        // Also shouldn't delete datafields that are being used by the Datatype's render plugins...
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_num => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                    if ( $rpf_df['id'] === $datafield_id ) {
                        $render_plugin_name = $rpi['renderPlugin']['pluginName'];
                        return array(
                            'can_delete' => false,
                            'delete_message' => "This Datafield can't be deleted because it's currently required by the \"".$render_plugin_name."\" this Datatype is using"
                        );
                    }
                }
            }
        }


        // ----------------------------------------
        // Otherwise, no problems deleting this field
        return array(
            'can_delete' => true,
            'delete_message' => '',
        );
    }


    /**
     * Helper function to determine whether a datafield has some property that should prevent any
     * change of public status.
     *
     * @param array $datatype_array
     * @param int $datatype_id
     * @param int $datafield_id
     *
     * @return array
     */
    public function canChangePublicStatus($datatype_array, $datatype_id, $datafield_id)
    {
        // ----------------------------------------
        // At the moment, there's nothing restricting changing a datafield's public status...
        return array(
            'can_change_public_status' => true,
//            'public_status_message' => '',
        );
    }


    /**
     * Helper function to determine whether a datafield should have/keep a property as a result of
     * the render plugin it's mapped to.
     *
     * @param array $datatype_array
     * @param int $datatype_id
     * @param int $datafield_id
     *
     * @return array
     */
    public function getRenderPluginProperties($datatype_array, $datatype_id, $datafield_id)
    {
        // Render plugins can require these properties...
        $props = array(
            'must_be_unique' => false,
            'single_uploads_only' => false,
            'no_user_edits' => false,

            // These have no bearing on datafield properties TODO - right?
//            'autogenerate_values' => false,
//            'is_derived' => false,
//            'is_optional' => false,
        );

        // ...but the datafield isn't guaranteed to be used by a render plugin
        $dt = $datatype_array[$datatype_id];
        $df = $dt['dataFields'][$datafield_id];

        // Check whether a datatype plugin is using this datafield
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_num => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( $rpf['id'] === $datafield_id ) {
                        // This datafield is being used by a datatype plugin
                        foreach ($rpf['properties'] as $key => $value) {
                            // Save whether the datatype plugin requires a given property
                            if ( isset($props[$key]) && $value === 1 )
                                $props[$key] = true;
                        }
                    }
                }
            }
        }

        // Check whether a datafield plugin is using this datafield
        if ( !empty($df['renderPluginInstances']) ) {
            foreach ($df['renderPluginInstances'] as $rpi_num => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    // If this point is reached, the datafield is using a datafield plugin
                    foreach ($rpf['properties'] as $key => $value) {
                        // Save whether the datafield plugin requires a given property
                        if ( isset($props[$key]) && $value === 1 )
                            $props[$key] = true;
                    }
                }
            }
        }

        return $props;
    }


    /**
     * Helper function to determine whether a datafield has multiple files/images uploaded or not.
     *
     * @param DataFields $datafield
     *
     * @return boolean
     */
    public function hasMultipleUploads($datafield)
    {
        // Should only be run on a file/image datafield
        $typeclass = $datafield->getFieldType()->getTypeClass();
        if ($typeclass !== 'File' && $typeclass !== 'Image')
            return false;

        // Count how many files/images are attached to this datafield across all datarecords
        $str =
           'SELECT COUNT(e.dataRecord)
            FROM ODRAdminBundle:'.$typeclass.' AS e
            JOIN ODRAdminBundle:DataFields AS df WITH e.dataField = df
            JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
            WHERE e.deletedAt IS NULL AND dr.deletedAt IS NULL AND df.id = :datafield';
        if ($typeclass == 'Image')
            $str .= ' AND e.original = 1 ';
        $str .= ' GROUP BY dr.id';

        $query = $this->em->createQuery($str)->setParameters( array('datafield' => $datafield) );
        $results = $query->getResult();

        // If $results has no rows, then nothing has been uploaded to the datafield...therefore it
        //  technically does not have multiple files/images
        if ( empty($results) )
            return false;

        // Otherwise...
        foreach ($results as $result) {
            // ...if $result[1] is greater than 1, then at least one datarecord has multiple uploads
            //  for the given datafield
            if ( intval($result[1]) > 1 )
                return true;
        }

        return false;
    }


    /**
     * Helper function to determine whether a datafield can be marked as unique or not.
     *
     * @param DataFields $datafield
     *
     * @return boolean
     */
    public function canDatafieldBeUnique($datafield)
    {
        // Only run queries if field can be set to unique
        if ( $datafield->getFieldType()->getCanBeUnique() == 0 )
            return false;

        $datatype = $datafield->getDataType();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        if ( $datatype->getId() !== $datatype->getGrandparent()->getId() )
            $is_child_datatype = true;

        if ( !$is_child_datatype ) {
            // Get a list of all values in the datafield
            $query = $this->em->createQuery(
               'SELECT e.value
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $results = $query->getArrayResult();

            // Determine if there are any duplicates in the datafield...
            $values = array();
            foreach ($results as $result) {
                $value = $result['value'];
                if ( isset($values[$value]) ) {
                    // Found duplicate, return false
                    return false;
                }
                else {
                    // Found new value, save and continue checking
                    $values[$value] = 1;
                }
            }
        }
        else {
            // Get a list of all values in the datafield, grouped by parent datarecord
            $query = $this->em->createQuery(
               'SELECT e.value, parent.id AS parent_id
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters( array('datafield' => $datafield->getId()) );
            $results = $query->getArrayResult();

            // Determine if there are any duplicates in the datafield...
            $values = array();
            foreach ($results as $result) {
                $value = $result['value'];
                $parent_id = $result['parent_id'];

                if ( !isset($values[$parent_id]) )
                    $values[$parent_id] = array();

                if ( isset($values[$parent_id][$value]) ) {
                    // Found duplicate, return false
                    return false;
                }
                else {
                    // Found new value, save and continue checking
                    $values[$parent_id][$value] = 1;
                }
            }
        }

        // Didn't find a duplicate, return true
        return true;
    }


    /**
     * Returns an array with three entries for each datafield in $datafield_ids...
     * 1) can the datafield's fieldtype be changed?
     * 2) if the fieldtype can't be changed, then a string with the reason it can't
     * 3) which fieldtypes the datafield is allowed to change to
     *
     * @param array $datatype_array
     * @param integer $datatype_id
     * @param array|null $datafield_ids If null, then determine for all datafields of datatype
     *
     * @return array
     */
    public function getFieldtypeInfo($datatype_array, $datatype_id, $datafield_ids = null)
    {
        // ----------------------------------------
        $fieldtype_info = array();

        // If no datafields were specified, then determine the allowed fieldtypes of all datafields
        //  in the given datatype
        if ( is_null($datafield_ids) ) {
            $datafield_ids = array();

            foreach ($datatype_array[$datatype_id]['dataFields'] as $df_id => $df)
                $datafield_ids[] = $df_id;
        }

        // Most likely going to need a list of all available fieldtypes
        /** @var FieldType[] $tmp */
        $tmp = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        $all_fieldtypes = array();
        foreach ($tmp as $ft)
            $all_fieldtypes[] = $ft->getId();


        // ----------------------------------------
        // Two of the four reasons to prevent a change to a datafield's fieldype require database
        //  lookups...
        foreach ($datafield_ids as $df_id) {
            $fieldtype_info[$df_id] = array(
                'prevent_change' => false,
                'prevent_change_message' => '',
                'allowed_fieldtypes' => $all_fieldtypes,
            );
        }

        // Prevent a datafield's fieldtype from changing if other fields are derived from it
        // TODO - need to make template synchronization able to migrate Fieldtypes eventually...
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, d_df.id AS derived_df_id
            FROM ODRAdminBundle:DataFields AS df
            LEFT JOIN ODRAdminBundle:DataFields AS d_df WITH d_df.masterDataField = df
            WHERE df IN (:datafield_ids)
            AND df.deletedAt IS NULL AND d_df.deletedAt IS NULL'
        )->setParameters( array('datafield_ids' => $datafield_ids) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $derived_df_id = $result['derived_df_id'];

            if ( !is_null($derived_df_id) ) {
                $fieldtype_info[$df_id]['prevent_change'] = true;
                $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because template synchronization can't migrate fieldtypes yet...";

                // Don't need to keep looking
                break;
            }
        }

        // Prevent a datafield's fieldtype from changing if it's a sort field for any datatype
        // TODO - allow changing fieldtype of sort fields?
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, dt.id AS dt_id
            FROM ODRAdminBundle:DataFields AS df
            LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
            LEFT JOIN ODRAdminBundle:DataType AS dt WITH dtsf.dataType = dt
            WHERE df IN (:datafield_ids) AND dtsf.field_purpose = :field_purpose
            AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datafield_ids' => $datafield_ids, 'field_purpose' => DataTypeSpecialFields::SORT_FIELD) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dt_id = $result['dt_id'];

            if ( !is_null($dt_id) ) {
                $fieldtype_info[$df_id]['prevent_change'] = true;
                $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because the Datafield is being used to sort a Datatype.";

                // Don't need to keep looking
                break;
            }
        }


        // ----------------------------------------
        // The other two reasons to prevent a change to a datafield's fieldtype, as well as the
        //  allowed fieldtypes, can be found via the cached datatype array
        $dt = $datatype_array[$datatype_id];

        // If the datatype is using a render plugin...
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_num => $rpi) {
                // ...then determine the allowed fieldtypes for each of its defined datafields
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                    $df_id = $rpf_df['id'];

                    if ( isset($fieldtype_info[$df_id]) ) {
                        $dt_fieldtypes = explode(',', $rpf_df['allowedFieldtypes']);
                        $fieldtype_info[$df_id]['allowed_fieldtypes'] = array_intersect($fieldtype_info[$df_id]['allowed_fieldtypes'], $dt_fieldtypes);
                    }
                }
            }
        }

        foreach ($datafield_ids as $df_num => $df_id) {
            // Get the datafield's array entry from the cached datatype entry
            $df = $dt['dataFields'][$df_id];
            $current_fieldtype_id = $df['dataFieldMeta']['fieldType']['id'];


            // Prevent a datafield's fieldtype from changing if it's derived from a template field
            if ( !is_null($df['masterDataField']) ) {
                $fieldtype_info[$df_id]['prevent_change'] = true;
                $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because the Datafield is derived from a Master Template.";
            }

            // TODO - allow changing fieldtype of unique fields?
            // TODO - ...can go from shorter text -> longer text, or number -> text...but not necessarily from longer text -> shorter text, or text -> number
            // Prevent a datafield's fieldtype from changing if it's marked as unique
            if ( $df['dataFieldMeta']['is_unique'] === true ) {
                $fieldtype_info[$df_id]['prevent_change'] = true;
                $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because the Datafield is currently marked as Unique.";
            }


            // If the datafield is using a render plugin...
            if ( !empty($df['renderPluginInstances']) ) {
                foreach ($df['renderPluginInstances'] as $rpi_num => $rpi) {
                    // There's only going to be one rpf in here, but don't know the array key beforehand
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                        // ...then the fieldtype can't be changed from what the render plugin requires
                        $df_fieldtypes = explode(',', $rpf_df['allowedFieldtypes']);
                        $fieldtype_info[$df_id]['allowed_fieldtypes'] = array_intersect($fieldtype_info[$df_id]['allowed_fieldtypes'], $df_fieldtypes);
                    }
                }
            }

            // If the datafield isn't supposed to change its fieldtype, then remove all but the
            //  current fieldtype from the 'allowed_fieldtypes' section of the array
            if ( $fieldtype_info[$df_id]['prevent_change'] === true ) {
                foreach ($fieldtype_info[$df_id]['allowed_fieldtypes'] as $num => $ft_id) {
                    if ( $ft_id !== $current_fieldtype_id )
                        unset( $fieldtype_info[$df_id]['allowed_fieldtypes'][$num] );
                }
            }
        }

        return $fieldtype_info;
    }
}
