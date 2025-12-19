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
        // NOTE: name/sort fields are intentionally allowed to be deleted

        // Also shouldn't delete datafields that are being used by the Datatype's render plugins...
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                    if ( $rpf_df['id'] === $datafield_id ) {
                        $render_plugin_name = $rpi['renderPlugin']['pluginName'];
                        return array(
                            'can_delete' => false,
                            'delete_message' => "This Datafield can't be deleted because it's currently required by the ".$render_plugin_name." this Datatype is using"
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

            // While not technically a datafield property, it's easier for the UI if this flag exists
            'uses_layout_settings' => false,
        );

        // ...but the datafield isn't guaranteed to be used by a render plugin
        $dt = $datatype_array[$datatype_id];
        $df = $dt['dataFields'][$datafield_id];

        // Check whether a datatype plugin is using this datafield
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    if ( $rpf['id'] === $datafield_id ) {
                        // This datafield is being used by a datatype plugin
                        foreach ($rpf['properties'] as $key => $value) {
                            // Save whether the datatype plugin requires a given property
                            if ( isset($props[$key]) && $value === 1 )
                                $props[$key] = true;
                        }

                        // The 'uses_layout_settings' property isn't checked for or set in this loop
                        //  because the datafield doesn't "own" the datatype plugin
                    }
                }
            }
        }

        // Check whether a datafield plugin is using this datafield
        if ( !empty($df['renderPluginInstances']) ) {
            foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf) {
                    // If this point is reached, the datafield is using a datafield plugin
                    foreach ($rpf['properties'] as $key => $value) {
                        // Save whether the datafield plugin requires a given property
                        if ( isset($props[$key]) && $value === 1 )
                            $props[$key] = true;
                    }

                    // Also store whether the datafield plugin has a layout-specific setting
                    if ( isset($rpi['renderPlugin']['uses_layout_settings']) && $rpi['renderPlugin']['uses_layout_settings'] === true )
                        $props['uses_layout_settings'] = true;
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
     * The ability to change a datafield's fieldtype is one of ODR's core responsibilities, but there
     * are multiple other properties that rely on a field being a specific fieldtype, so actually
     * performing the change can be incredibly problematic. This function makes it slightly easier by
     * returning an array with four entries for each requested datafield...
     *
     * <pre>
     * array(
     *     <df_id> => array(
     *         'prevent_change' => <bool>,  // True when the datafield shouldn't change fieldtype
     *         'prevent_change_message' => '<string>', // Why the datafield shouldn't change fieldtype
     *         'affected_by_render_plugin' => <bool>,  // Whether any plugin is mapped to the datafield
     *         'allowed_fieldtypes' => array(  // Which fieldtypes the datafield can change to
     *             <ft1_id>,
     *             <ft2_id>,
     *             ...
     *         )
     *     ),
     *     ...
     * )
     * </pre>
     *
     * @param array $datatype_array
     * @param integer $datatype_id
     * @param int[]|null $datafield_ids If null, then return this info for all datafields of datatype
     * @param bool $only_check_render_plugins If true, then return after determing the fieldtype
     *                                        restrictions on the datafield imposed by any attached
     *                                        render plugins
     *
     * @return array
     */
    public function canChangeFieldtype($datatype_array, $datatype_id, $datafield_ids = null, $only_check_render_plugins = false)
    {
        // ----------------------------------------
        $fieldtype_info = array();
        $is_single_radio_field = array();
        $is_multiple_radio_field = array();

        // If no datafields were specified, then determine the allowed fieldtypes of all datafields
        //  in the given datatype
        $dt = $datatype_array[$datatype_id];
        if ( is_null($datafield_ids) ) {
            $datafield_ids = array();

            foreach ($dt['dataFields'] as $df_id => $df)
                $datafield_ids[] = $df_id;
        }

        // Need a list of all available fieldtypes so twig doesn't have to do it
        $single_radio_fieldtype_ids = array();
        $multiple_radio_fieldtype_ids = array();

        /** @var FieldType[] $tmp */
        $tmp = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        $all_fieldtypes = array();
        foreach ($tmp as $ft) {
            $all_fieldtypes[] = $ft->getId();

            $typename = $ft->getTypeName();
            switch ($typename) {
                case 'Single Radio':
                case 'Single Select':
                    // Always want to consider 'Single Radio' as equivalent to 'Single Select'
                    $single_radio_fieldtype_ids[] = $ft->getId();
                    break;
                case 'Multiple Radio':
                case 'Multiple Select':
                    // Same deal with 'Multiple Radio' and 'Multiple Select'
                    $multiple_radio_fieldtype_ids[] = $ft->getId();
                    break;

                default:
                    break;
            }
        }

        // It's easier to determine which fields are single/multiple radio all at once
        foreach ($dt['dataFields'] as $df_id => $df) {
            $typename = $df['dataFieldMeta']['fieldType']['typeName'];
            switch ($typename) {
                case 'Single Radio':
                case 'Single Select':
                    $is_single_radio_field[$df_id] = true;
                    break;
                case 'Multiple Radio':
                case 'Multiple Select':
                    $is_multiple_radio_field[$df_id] = true;
                    break;

                default:
                    $is_single_radio_field[$df_id] = false;
                    $is_multiple_radio_field[$df_id] = false;
                    break;
            }
        }

        // By default, the fieldtype of any datafield can get changed to any other fieldtype
        foreach ($datafield_ids as $df_id) {
            $fieldtype_info[$df_id] = array(
                'prevent_change' => false,
                'prevent_change_message' => '',
                'affected_by_render_plugin' => false,
                'allowed_fieldtypes' => $all_fieldtypes,
            );
        }


        // ----------------------------------------
        // Each datafield can have a combination of properties that should result in restricting
        //  what its fieldtype can get changed to...the first set has to do with attached render plugins

        // If the datatype is using a render plugin...
        if ( !empty($dt['renderPluginInstances']) ) {
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                // ...then determine the allowed fieldtypes for each of its defined datafields
                foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                    $df_id = $rpf_df['id'];

                    if ( isset($fieldtype_info[$df_id]) ) {
                        $dt_fieldtypes = explode(',', $rpf_df['allowedFieldtypes']);
                        $fieldtype_info[$df_id]['allowed_fieldtypes'] = array_intersect($fieldtype_info[$df_id]['allowed_fieldtypes'], $dt_fieldtypes);
                        $fieldtype_info[$df_id]['affected_by_render_plugin'] = true;

                        // The render plugin system already stores 'allowed_fieldtypes' in the backend
                        //  such that 'Single Radio' === 'Single Select', and the same for multiple
                    }
                }
            }
        }

        // The datafields themselves could also have plugins...
        foreach ($datafield_ids as $df_num => $df_id) {
            // Get the datafield's array entry from the cached datatype entry
            $df = $dt['dataFields'][$df_id];

            // If the datafield is using a render plugin...
            if ( !empty($df['renderPluginInstances']) ) {
                foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                    // There's only going to be one rpf in here, but don't know the array key beforehand
                    foreach ($rpi['renderPluginMap'] as $rpf_name => $rpf_df) {
                        // ...then the fieldtype can't be changed from what the render plugin requires
                        $df_fieldtypes = explode(',', $rpf_df['allowedFieldtypes']);
                        $fieldtype_info[$df_id]['allowed_fieldtypes'] = array_intersect($fieldtype_info[$df_id]['allowed_fieldtypes'], $df_fieldtypes);
                        $fieldtype_info[$df_id]['affected_by_render_plugin'] = true;

                        // The render plugin system already stores 'allowed_fieldtypes' in the backend
                        //  such that 'Single Radio' === 'Single Select', and the same for multiple
                    }
                }
            }
        }

        // Datafield migration reports only needs the render plugin information
        if ( $only_check_render_plugins )
            return $fieldtype_info;


        // ----------------------------------------
        // There are three situations in which changing a datafield's fieldtype should be heavily
        //  discouraged...the first is if it's being used as a name/sort field for any datatype
        $query = $this->em->createQuery(
           'SELECT df.id AS df_id, dt.id AS dt_id
            FROM ODRAdminBundle:DataFields AS df
            LEFT JOIN ODRAdminBundle:DataTypeSpecialFields AS dtsf WITH dtsf.dataField = df
            LEFT JOIN ODRAdminBundle:DataType AS dt WITH dtsf.dataType = dt
            WHERE df IN (:datafield_ids)
            AND df.deletedAt IS NULL AND dtsf.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datafield_ids' => $datafield_ids) );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $df_id = $result['df_id'];
            $dt_id = $result['dt_id'];

            if ( !is_null($dt_id) ) {
                if ( $is_single_radio_field[$df_id] ) {
                    // Shouldn't need to prevent fieldtype change, or display a message...
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $single_radio_fieldtype_ids;
                }
                else if ( $is_multiple_radio_field[$df_id] ) {
                    // ...both single and multiple radio fields should be allowed to freely switch
                    //  to the other single/multiple fieldtype at any time
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $multiple_radio_fieldtype_ids;
                }
                else {
                    $fieldtype_info[$df_id]['prevent_change'] = true;
                    $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because the Datafield is being as a name/sort field for a Datatype.";
                }

                // Don't need to keep looking
                break;
            }
        }

        // ...the second is if it's unique...
        foreach ($datafield_ids as $df_num => $df_id) {
            // Get the datafield's array entry from the cached datatype entry
            $df = $dt['dataFields'][$df_id];

            if ( $df['dataFieldMeta']['is_unique'] === true ) {
                // None of the radio fieldtypes can be unique, so no sense checking those
                $fieldtype_info[$df_id]['prevent_change'] = true;
                $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because the Datafield is currently marked as Unique.";
            }

            // TODO - allow changing fieldtype of unique fields?
            // TODO - ...can go from shorter text -> longer text, or number -> text...but not necessarily from longer text -> shorter text, or text -> number
        }

        // ...the other is if the datafield is entangled with the template system.  A master/template
        //  datafield has to define the fieldtype for each of its derived datafields, and changing
        //  a fieldtype in this situation requires a mess of additional checks to ensure that both
        //  master and derived fields can remain synchronized.
        // This is so problematic that ODR didn't allow any non-trivial fieldtype change in this
        //  situation until forced to in early 2026

        // If any of the fields are related to the template system...
        $master_df_list = array();
        $derived_df_list = array();
        foreach ($dt['dataFields'] as $df_id => $df) {
            /*if ( $df['is_master_field'] )  // TODO - this is temporary, so I can commit the updated reports files without also having to implement the update to fieldtype migration...
                $master_df_list[$df_id] = 1;
            else*/ if ( !is_null($df['masterDataField']) )
                $derived_df_list[$df_id] = 1;
        }

        foreach ($fieldtype_info as $df_id => $data) {
            if ( isset($master_df_list[$df_id]) || isset($derived_df_list[$df_id]) ) {
                // If the field is involved with the template system, then the dropdowns are only
                //  allowed to swap between single radio/select, or multiple radio/select
                if ( $is_single_radio_field[$df_id] ) {
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $single_radio_fieldtype_ids;
                    $fieldtype_info[$df_id]['prevent_change_message'] = "Changing to a fieldtype other than Single Radio/Select requires additional checks...";
                }
                else if ( $is_multiple_radio_field[$df_id] ) {
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $multiple_radio_fieldtype_ids;
                    $fieldtype_info[$df_id]['prevent_change_message'] = "Changing to a fieldtype other than Multiple Radio/Select requires additional checks...";
                }
                else {
                    // If the field is using any other fieldtype, then the dropdown needs to be
                    //  disabled
                    $fieldtype_info[$df_id]['prevent_change'] = true;
                    // ...the actual message depends on whether it's a master or a derived field
                    if ( isset($master_df_list[$df_id]) )
                        $fieldtype_info[$df_id]['prevent_change_message'] = "Template fields that already have derived fields require additional checks...";
                    else
                        $fieldtype_info[$df_id]['prevent_change_message'] = "Derived fields must remain synchronized with their Template field.";
                }
            }
        }

        // TODO - this is temporary, so I can commit the updated reports files without also having to implement the update to fieldtype migration...
        // Prevent a datafield's fieldtype from changing if other fields are derived from it
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
                if ( $is_single_radio_field[$df_id] ) {
                    // Shouldn't need to prevent fieldtype change, or display a message...
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $single_radio_fieldtype_ids;
                }
                else if ( $is_multiple_radio_field[$df_id] ) {
                    // ...both single and multiple radio fields should be allowed to freely switch
                    //  to the other single/multiple fieldtype at any time
                    $fieldtype_info[$df_id]['allowed_fieldtypes'] = $multiple_radio_fieldtype_ids;
                }
                else {
                    $fieldtype_info[$df_id]['prevent_change'] = true;
                    $fieldtype_info[$df_id]['prevent_change_message'] = "The Fieldtype can't be changed because template synchronization can't migrate fieldtypes yet...";
                }

                // Don't need to keep looking
                break;
            }
        }


        // ----------------------------------------
        // Now that each datafield has been checked...
        foreach ($fieldtype_info as $df_id => $df_data ) {
            // ...if the datafield isn't supposed to change its fieldtype...
            if ( $df_data['prevent_change'] ) {
                $df = $dt['dataFields'][$df_id];
                $current_fieldtype_id = $df['dataFieldMeta']['fieldType']['id'];

                // ...then remove all but its current fieldtype from the 'allowed_fieldtypes'
                //  section of the array
                foreach ($df_data['allowed_fieldtypes'] as $num => $ft_id) {
                    if ( $ft_id !== $current_fieldtype_id )
                        unset( $fieldtype_info[$df_id]['allowed_fieldtypes'][$num] );
                }
            }
        }

        return $fieldtype_info;
    }
}
