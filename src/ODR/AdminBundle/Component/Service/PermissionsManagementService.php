<?php

/**
 * Open Data Repository Data Publisher
 * Permissions Management Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 *
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\OpenRepository\UserBundle\Entity\User;
use ODR\AdminBundle\Entity\UserGroup;
// Forms
// Symfony
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
// Other
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;


class PermissionsManagementService
{

    /**
     * PermissionsManagementService constructor.
     */
    public function __construct()
    {
    }


    /**
     * Given a group's permission arrays, filter the provided datarecord/datatype arrays so twig doesn't render anything they're not supposed to see.
     *
     * @param array &$datatype_array    @see DatatypeInfoService::getDatatypeArray()
     * @param array &$datarecord_array  @see DatarecordInfoService::getDatarecordArray()
     * @param array $permissions_array  @see TODO
     */
    public function filterByGroupPermissions(&$datatype_array, &$datarecord_array, $permissions_array)
    {
$debug = true;
$debug = false;

if ($debug)
    print '----- permissions filter -----'."\n";

        // Save relevant permissions...
        $datatype_permissions = array();
        if ( isset($permissions_array['datatypes']) )
            $datatype_permissions = $permissions_array['datatypes'];
        $datafield_permissions = array();
        if ( isset($permissions_array['datafields']) )
            $datafield_permissions = $permissions_array['datafields'];

        $can_view_datatype = array();
        $can_view_datarecord = array();
        $datafields_to_remove = array();
        foreach ($datatype_array as $dt_id => $dt) {
            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dt_view' ]) )
                $can_view_datatype[$dt_id] = true;
            else
                $can_view_datatype[$dt_id] = false;

            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dr_view' ]) )
                $can_view_datarecord[$dt_id] = true;
            else
                $can_view_datarecord[$dt_id] = false;
        }


        // For each datatype in the provided array...
        foreach ($datatype_array as $dt_id => $dt) {

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datatype[$dt_id]) )
                $can_view_datatype[$dt_id] = false;

            // If datatype is non-public and user does not have the 'can_view_datatype' permission, then remove the datatype from the array
            if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datatype[$dt_id] ) {
                unset( $datatype_array[$dt_id] );
if ($debug)
    print 'removed non-public datatype '.$dt_id."\n";

                // Also remove all datarecords of that datatype
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dt_id == $dr['dataType']['id'])
                        unset( $datarecord_array[$dr_id] );
if ($debug)
    print ' -- removed datarecord '.$dr_id."\n";
                }

                // No sense checking anything else for this datatype, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to see this datatype...
            foreach ($dt['themes'] as $theme_id => $theme) {
                foreach ($theme['themeElements'] as $te_num => $te) {

                    // For each datafield in this theme element...
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            $df_id = $tdf['dataField']['id'];

                            // If the user doesn't have the 'can_view_datafield' permission for that datafield...
                            if ( $tdf['dataField']['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !(isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) ) ) {
                                // ...remove it from the layout
                                unset( $datatype_array[$dt_id]['themes'][$theme_id]['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField'] );  // leave the theme_datafield entry on purpose
                                $datafields_to_remove[$df_id] = 1;
if ($debug)
    print 'removed datafield '.$df_id.' from theme_element '.$te['id'].' datatype '.$dt_id.' theme '.$theme_id.' ('.$theme['themeType'].')'."\n";
                            }
                        }
                    }
                }
            }
        }

        // Also need to go through the datarecord array and remove both datarecords and datafields that the user isn't allowed to see
        foreach ($datarecord_array as $dr_id => $dr) {
            // Save datatype id of this datarecord
            $dt_id = $dr['dataType']['id'];

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datarecord[$dt_id]) )
                $can_view_datarecord[$dt_id] = false;

            // If the datarecord is non-public and user doesn't have the 'can_view_datarecord' permission, then remove the datarecord from the array
            if ( $dr['dataRecordMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                unset( $datarecord_array[$dr_id] );
if ($debug)
    print 'removed non-public datarecord '.$dr_id."\n";

                // No sense checking anything else for this datarecord, skip to the next one
                continue;
            }

            // The user is allowed to view this datarecord...
            foreach ($dr['dataRecordFields'] as $df_id => $drf) {

                // Remove the datafield if needed
                if ( isset($datafields_to_remove[$df_id]) ) {
                    unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
if ($debug)
    print 'removed datafield '.$df_id.' from datarecord '.$dr_id."\n";

                    // No sense checking file/image public status, skip to the next datafield
                    continue;
                }

                // ...remove the files the user isn't allowed to see
                foreach ($drf['file'] as $file_num => $file) {
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['file'][$file_num] );
if ($debug)
    print 'removed non-public file '.$file['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }

                // ...remove the images the user isn't allowed to see
                foreach ($drf['image'] as $image_num => $image) {
                    if ( $image['parent']['imageMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['image'][$image_num] );
if ($debug)
    print 'removed non-public image '.$image['parent']['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }
            }
        }
    }
}
