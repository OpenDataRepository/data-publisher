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
use ODR\AdminBundle\Entity\FieldType;
// Exceptions
// Services
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
     * @var DatatypeInfoService
     */
    private $dti_service;

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
        $all_datafield_ids = array();

        // ----------------------------------------
        // Load properties for all datafields by default, or a single datafield if defined
        foreach ($datatype_array as $dt_id => $dt) {
            foreach ($dt['dataFields'] as $df_id => $df) {
                if ( is_null($datafield_id) || $df_id === $datafield_id ) {
                    // Store this id for the queries required later
                    $all_datafield_ids[] = $df_id;
                    $dfm = $df['dataFieldMeta'];

                    // Store these values directly in the array
                    $typeclass = $dfm['fieldType']['typeClass'];
//                    $typename = $dfm['fieldType']['typeName'];

                    $render_plugin_id = $dfm['renderPlugin']['id'];
                    $render_plugin_name = $dfm['renderPlugin']['pluginName'];
                    $render_plugin_classname = $dfm['renderPlugin']['pluginClassName'];

                    // These values require a bit of calculation first...
                    $can_copy = true;
                    if ( $typeclass === 'Radio' || $typeclass === 'Tag' || $render_plugin_classname !== 'odr_plugins.base.default' )
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

                        'render_plugin_id' => $render_plugin_id,
                        'render_plugin_classname' => $render_plugin_classname,
                        'render_plugin_name' => $render_plugin_name,

                        'has_tag_hierarchy' => $has_tag_hierarchy,
                    );
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

        // Also shouldn't delete datafields that are being used by the Datatype's render plugin...
        if ( $dtm['renderPlugin']['pluginClassName'] !== 'odr_plugins.base.default' ) {
            if ( !empty($dtm['renderPlugin']['renderPluginInstance']) ) {
                $rpi = $dtm['renderPlugin']['renderPluginInstance'][0];
                foreach ($rpi['renderPluginMap'] as $rpm) {
                    if ( $rpm['dataField']['id'] === $datafield_id ) {
                        return array(
                            'can_delete' => false,
                            'delete_message' => "This Datafield can't be deleted because it's currently required by the \"".$dtm['renderPlugin']['pluginName']."\" this Datatype is using"
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
     * Helper function to determine whether a datafield can have its fieldtype changed.
     *
     * Tracked jobs in-progress prevent changing of fieldtype too, but those need to be checked for
     * and handled by the caller since those are temporary restrictions.
     *
     * @param DataFields $datafield
     *
     * @return array
     */
    public function canChangeFieldtype($datafield)
    {
        // TODO - not 100% true, technically...can go from text -> text or number -> text, but can't go from text -> number...
        // Also prevent a fieldtype change if the datafield is marked as unique
        if ($datafield->getIsUnique() == true) {
            return array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is currently marked as Unique.",
            );
        }


        // TODO - the FieldType table has a list of sortable fieldtypes...but migration takes so long that the datatype will usually be sorted with an incomplete set of values...
        // Also prevent a fieldtype change if the datafield is being used as the sort field by any datatype
        $query = $this->em->createQuery(
           'SELECT dtm.shortName
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.sortField = df
            JOIN ODRAdminBundle:DataType AS dt WITH dtm.dataType = dt
            WHERE df.id = :datafield_id
            AND df.deletedAt IS NULL AND dtm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        if ( !empty($results) ) {
            return array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is being used to sort a Datatype",
            );
        }


        // Prevent a datafield's fieldtype from changing if it's derived from a template
        if ( !is_null($datafield->getMasterDataField()) ) {
            return array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because the Datafield is derived from a Master Template.",
            );
        }

        // TODO - need to make template synchronization able to migrate Fieldtypes eventually...
        $query = $this->em->createQuery(
           'SELECT df.id
            FROM ODRAdminBundle:DataFields df
            WHERE df.masterDataField = :datafield_id
            AND df.deletedAt IS NULL'
        )->setParameters( array('datafield_id' => $datafield->getId()) );
        $results = $query->getArrayResult();

        if ( !empty($results) ) {
            return array(
                'prevent_change' => true,
                'prevent_change_message' => "The Fieldtype can't be changed because template synchronization can't migrate fieldtypes yet..."
            );
        }

        // ----------------------------------------
        // Otherwise, no problems changing fieldtype of this datafield
        return array(
            'prevent_change' => false,
            'prevent_change_message' => '',
        );
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

        foreach ($results as $result) {
            // $result[1] contains how many files/images each datarecord has
            if ( intval($result[1]) > 1 )
                return true;
        }

        return false;
    }


    /**
     * Returns an array of fieldtype ids that the datafield is allowed to have in its current context.
     *
     * @param DataFields $datafield
     * @param array $datatype_array
     *
     * @return array
     */
    public function getAllowedFieldtypes($datafield, $datatype_array = null)
    {
        // ----------------------------------------
        // Need a list of all fieldtype ids to start from...
        /** @var FieldType[] $tmp */
        $tmp = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        $allowed_fieldtypes = array();
        foreach ($tmp as $ft)
            $allowed_fieldtypes[] = $ft->getId();

        // ...but can use the cached datatype array to get the rest of the data for determining this
        $datatype = $datafield->getDataType();
        if ( is_null($datatype_array) )
            $datatype_array = $this->dti_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't need links

        $dt = $datatype_array[$datatype->getId()];
        $df = $dt['dataFields'][$datafield->getId()];


        // ----------------------------------------
        // If the datafield is using a render plugin...
        if ( $df['dataFieldMeta']['renderPlugin']['pluginClassName'] !== 'odr_plugins.base.default' ) {
            $rpi = $df['dataFieldMeta']['renderPlugin']['renderPluginInstance'][0];
            $rpm = $rpi['renderPluginMap'][0];
            $rpf = $rpm['renderPluginFields'];

            // ...then the fieldtype can't be changed from what the render plugin requires
            $df_fieldtypes = explode(',', $rpf['allowedFieldtypes']);
            $allowed_fieldtypes = array_intersect($allowed_fieldtypes, $df_fieldtypes);
        }

        // If the datatype is using a render plugin...
        if ( $dt['dataTypeMeta']['renderPlugin']['pluginClassName'] !== 'odr_plugins.base.default' ) {
            $rpi = $dt['dataTypeMeta']['renderPlugin']['renderPluginInstance'][0];

            // ...then if this datafield is required by the render plugin...
            foreach ($rpi['renderPluginMap'] as $rpm) {
                if ( $rpm['dataField']['id'] === $datafield->getId() ) {
                    // ...then the fieldtype can't be changed from what the render plugin requires
                    $dt_fieldtypes = explode(',', $rpm['renderPluginFields']['allowedFieldtypes']);
                    $allowed_fieldtypes = array_intersect($allowed_fieldtypes, $dt_fieldtypes);

                    // No point looking through the render plugin's config any longer
                    break;
                }
            }
        }


        // ----------------------------------------
        // TODO - allow changing fieldtype of unique fields...can go from text -> text or number -> text, but not text -> number...
        // TODO - allow changing fieldtype of sort fields...currently fieldtype migration takes so long that the datatype will usually be sorted with an incomplete set of values...


        // ----------------------------------------
        // Return which fieldtypes the datafield is allowed to have
        return $allowed_fieldtypes;
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
                if ( isset($values[$value]) )
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$value] = 1;
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

                if ( isset($values[$parent_id][$value]) )
                    // Found duplicate, return false
                    return false;
                else
                    // Found new value, save and continue checking
                    $values[$parent_id][$value] = 1;
            }
        }

        // Didn't find a duplicate, return true
        return true;
    }
}

