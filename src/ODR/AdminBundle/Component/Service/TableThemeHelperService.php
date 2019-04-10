<?php


/**
 * Open Data Repository Data Publisher
 * Table Theme Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains several utility functions to help render a list of datarecords in a table format.
 */

namespace ODR\AdminBundle\Component\Service;


// Entities
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Other
use Symfony\Bridge\Monolog\Logger;
// Utility
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Router;


class TableThemeHelperService
{

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var Router
     */
    private $router;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $valid_fieldtypes;


    /**
     * TableThemeHelperService constructor.
     *
     * @param ContainerInterface $container
     * @param CacheService $cache_service
     * @param DatarecordInfoService $dri_service
     * @param DatatypeInfoService $dti_service
     * @param PermissionsManagementService $pm_service
     * @param ThemeInfoService $theme_service
     * @param Router $router
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        CacheService $cache_service,
        DatarecordInfoService $datarecord_info_service,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        Router $router,
        Logger $logger
    ) {
        $this->container = $container;
        $this->cache_service = $cache_service;
        $this->dri_service = $datarecord_info_service;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->theme_service = $theme_info_service;
        $this->router = $router;
        $this->logger = $logger;

        $this->valid_fieldtypes = array(
            'Boolean',
            'File',
            'Integer',
            'Decimal',
            'Paragraph Text',
            'Long Text',
            'Medium Text',
            'Short Text',
            'DateTime',
            'Single Radio',
            'Single Select',
        );
    }


    /**
     * Utility function to return the column definition for use by the datatables plugin
     *
     * @param ODRUser $user
     * @param int $datatype_id
     * @param int $theme_id
     *
     * @return array
     */
    public function getColumnNames($user, $datatype_id, $theme_id)
    {
        // ----------------------------------------
        // First and second columns are always datarecord id and sort value, respectively
        $column_names  = '{"title":"datarecord_id","visible":false,"searchable":false},';
        $column_names .= '{"title":"datarecord_sortvalue","visible":false,"searchable":false},';
        $num_columns = 2;

        // Get the datatype and theme data
        $include_links = false;
        $datatype_array = $this->dti_service->getDatatypeArray($datatype_id, $include_links);
        $theme_array = $this->theme_service->getThemeArray($theme_id);

        // Don't want any child datatypes, or themes for child datatypes, in their respective arrays
        foreach ($datatype_array as $dt_id => $dt) {
            if ($datatype_id !== $dt_id)
                unset( $datatype_array[$dt_id] );
        }
        foreach ($theme_array as $t_id => $t) {
            if ($theme_id !== $t_id)
                unset( $theme_array[$t_id] );
        }

        // Filter out the datafields the user isn't allowed to view
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);

        $datarecord_array = array();
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


        // ----------------------------------------
        // Need to store the names of each of the datafields
        foreach ($theme_array[$theme_id]['themeElements'] as $te_num => $te) {
            // Ignore theme elements that are hidden
            if ( $te['themeElementMeta']['hidden'] == 1 )
                continue;

            if ( isset($te['themeDataFields']) ) {
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    // If the datafield still exists in the datatype array...
                    if ( isset($datatype_array[$datatype_id]['dataFields'][$df_id]) ) {
                        $df = $datatype_array[$datatype_id]['dataFields'][$df_id];

                        // ...and the datafield isn't "hidden" for this theme...
                        if ( $tdf['hidden'] == 1 )
                            continue;

                        // ...and the field's type name is on the list of valid fieldtypes
                        $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                        if ( !in_array($typename, $this->valid_fieldtypes) )
                            continue;

                        // ...then extract the datafield's name
                        $fieldname = $df['dataFieldMeta']['fieldName'];

                        // Escape double-quotes in the datafield's name
                        $fieldname = str_replace('"', "\\\"", $fieldname);
                        $column_names .= '{"title":"'.$fieldname.'",';

                        // If dynamically added, the edit link column will have a priority of 10000
                        //  and therefore won't be hidden if there's too many columns for the screen
                        // @see https://datatables.net/reference/option/columns.responsivePriority#Type
                        $column_names .= '"responsivePriority":11000},';

                        $num_columns++;
                    }
                }
            }
        }

        $column_data = array('column_names' => $column_names, 'num_columns' => $num_columns);
        return $column_data;
    }


    /**
     * Returns a stripped-down array of data for rendering by the datatables plugin.
     *
     * $datarecord_ids should already be a list of datarecords the user is able to view.  Otherwise,
     *  filtering may cause this to return fewer rows than desired.
     *
     * @param ODRUser $user
     * @param int[] $datarecord_ids
     * @param int $datatype_id
     * @param int $theme_id
     *
     * @throws ODRException
     *
     * @return array
     */
    public function getRowData($user, $datarecord_ids, $datatype_id, $theme_id)
    {
        // ----------------------------------------
        // Get the datatype and theme data
        $include_links = false;
        $datatype_array = $this->dti_service->getDatatypeArray($datatype_id, $include_links);
        $theme_array = $this->theme_service->getThemeArray($theme_id);

        // Don't want any child datatypes, or themes for child datatypes, in their respective arrays
        foreach ($datatype_array as $dt_id => $dt) {
            if ($datatype_id !== $dt_id)
                unset( $datatype_array[$dt_id] );
        }
        foreach ($theme_array as $t_id => $t) {
            if ($theme_id !== $t_id)
                unset( $theme_array[$t_id] );
        }


        // Filter out everything the user isn't allowed to view
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $user_permissions['datatypes'];

        $datarecord_array = array();
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // Grab the cached version of each of the requested datarecords
        $datarecord_array = array();
        foreach ($datarecord_ids as $num => $dr_id) {
            $dr_data = $this->cache_service->get('cached_table_data_'.$dr_id);
            if ($dr_data == false)
                $dr_data = self::buildTableData($dr_id);

            $datarecord_array[$dr_id] = $dr_data;
        }

        // Store whether the user is able to view non-public files
        $can_view_datarecord = false;
        if ( isset($datatype_permissions[$datatype_id]) && isset($datatype_permissions[$datatype_id]['dr_view']) )
            $can_view_datarecord = true;


        // ----------------------------------------
        // Build an array of valid datafields
        $datafield_ids = self::getValidDatafields($theme_array, $theme_id, $datatype_array, $datatype_id);
        if ( empty($datafield_ids) ) {
            // ...if the original theme didn't have visible datafields, attempt to fallback to the
            //  datatype's master theme
            $master_theme = $this->theme_service->getDatatypeMasterTheme($datatype_id);
            $master_theme_id = $master_theme->getId();

            // Like before, don't want any themes for child datatypes in the array
            $theme_array = $this->theme_service->getThemeArray($master_theme_id);
            foreach ($theme_array as $t_id => $t) {
                if ($master_theme_id !== $t_id)
                    unset( $theme_array[$t_id] );
            }

            // Attempt to get valid datafields off the master theme...
            $datafield_ids = self::getValidDatafields($theme_array, $master_theme_id, $datatype_array, $datatype_id);

            // TODO - fix other areas of ODR to prevent this from happening somehow?
            // ...but if there's STILL no visible datafields leftover, then throw an error to
            //  prevent ODR from claiming that nothing matched the search
            if ( empty($datafield_ids) )
                throw new ODRException('No Datafields are visible', 500, 0x7e562032);
        }


        // ----------------------------------------
        // Build the final array of data
        $rows = array();
        foreach ($datarecord_array as $dr_id => $dr) {
            $dr_data = array();

            foreach ($datafield_ids as $num => $df_id) {
                // Attempt to pull the data from the cached entry...
                if ( !isset($dr[$df_id]) ) {
                    // ...data isn't set, so it's probably empty or null...store empty string
                    $dr_data[] = '';
                }
                else if ( is_array($dr[$df_id]) ) {
                    // Need to ensure that names/links to non-public Files aren't displayed
                    //  to people that don't have permission to view them
                    $file_publicDate = $dr[$df_id]['publicDate'];
                    $file_url = $dr[$df_id]['url'];

                    if ($can_view_datarecord || $file_publicDate != '2200-01-01')
                        $dr_data[] = $file_url;
                    else
                        $dr_data[] = '';
                }
                else {
                    // ...store it in the final array
                    $dr_data[] = $dr[$df_id];
                }
            }

            // If something was stored...
            if (count($dr_data) > 0) {
                // Prepend the datarecord's id and sortfield value
                $row = array();
                $row[] = strval($dr_id);
                $row[] = strval($dr['sortField_value']);

                // Convert all the datafield values into strings, and store them
                foreach ($dr_data as $tmp)
                    $row[] = strval($tmp);

                $rows[] = $row;
            }
        }

        // Return the final result
        return $rows;
    }


    /**
     * Builds the cached entry that stores table data for the given datarecord.  Can't just use the
     * regular cached entry because displaying in table format may require execution of a render
     * plugin.
     *
     * @param int $datarecord_id
     *
     * @throws ODRException
     *
     * @return array
     */
    private function buildTableData($datarecord_id)
    {
        // Need the cached data for the given datarecord...
        $include_links = false;
        $dr_data = $this->dri_service->getDatarecordArray($datarecord_id, $include_links);
        $dr = $dr_data[$datarecord_id];

        // Also need the datatype info in order to determine render plugin for datafields
        $dt_id = $dr_data[$datarecord_id]['dataType']['id'];
        $dt_data = $this->dti_service->getDatatypeArray($dt_id, $include_links);
        $datatype_array = $dt_data[$dt_id];


        // Only want to save the data for the top-level datarecord
        $data = array('sortField_value' => $dr['sortField_value']);
        foreach ($datatype_array['dataFields'] as $df_id => $df) {
            // The datarecord might not have an entry for this datafield...
            $df_value = '';
            $save_value = true;

            // If the datafield is using a render plugin...
            $render_plugin = $df['dataFieldMeta']['renderPlugin'];
            if ($render_plugin['id'] !== 1) {
                // Run the render plugin for this datafield
                try {
                    /** @var DatafieldPluginInterface $plugin */
                    $plugin = $this->container->get($render_plugin['pluginClassName']);
                    $df_value = $plugin->execute($df, $dr, $render_plugin, 'table');
                }
                catch (\Exception $e) {
                    throw new ODRException( 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$df['id'].' Datarecord '.$dr['id'].': '.$e->getMessage(), 500, 0x23568871, $e );
                }
            }
            else if ( !isset($dr['dataRecordFields']) || !isset($dr['dataRecordFields'][$df_id]) ) {
                /* A drf entry hasn't been created for this storage entity...just use the empty string */
            }
            else {
                // ...otherwise, (almost) directly transfer the data
                $df_typename = $df['dataFieldMeta']['fieldType']['typeName'];
                $drf = $dr['dataRecordFields'][$df_id];

                switch ($df_typename) {
                    case 'Boolean':
                        if ( isset($drf['boolean']) && isset($drf['boolean'][0]) ) {
                            if ( $drf['boolean'][0]['value'] == 1 )
                                $df_value = 'YES';
                        }
                        break;
                    case 'Integer':
                        if ( isset($drf['integerValue']) && isset($drf['integerValue'][0]) )
                            $df_value = $drf['integerValue'][0]['value'];
                        break;
                    case 'Decimal':
                        if ( isset($drf['decimalValue']) && isset($drf['decimalValue'][0]) )
                            $df_value = $drf['decimalValue'][0]['value'];
                        break;
                    case 'Paragraph Text':
                        if ( isset($drf['longText']) && isset($drf['longText'][0]) )
                            $df_value = $drf['longText'][0]['value'];
                        break;
                    case 'Long Text':
                        if ( isset($drf['longVarchar']) && isset($drf['longVarchar'][0]) )
                            $df_value = $drf['longVarchar'][0]['value'];
                        break;
                    case 'Medium Text':
                        if ( isset($drf['mediumVarchar']) && isset($drf['mediumVarchar'][0]) )
                            $df_value = $drf['mediumVarchar'][0]['value'];
                        break;
                    case 'Short Text':
                        if ( isset($drf['shortVarchar']) && isset($drf['shortVarchar'][0]) )
                            $df_value = $drf['shortVarchar'][0]['value'];
                        break;
                    case 'DateTime':
                        if ( isset($drf['datetimeValue']) && isset($drf['datetimeValue'][0]) ) {
                            $df_value = $drf['datetimeValue'][0]['value']->format('Y-m-d');
                            if ($df_value == '9999-12-31')
                                $df_value = '';
                        }
                        break;

                    case 'File':
                        if ( isset($drf['file']) && isset($drf['file'][0]) ) {
                            $file = $drf['file'][0];    // should only ever be one file in here anyways

                            $url = $this->router->generate( 'odr_file_download', array('file_id' => $file['id']) );
                            $df_value = array(
                                'publicDate' => $file['fileMeta']['publicDate']->format('Y-m-d'),
                                'url' => '<a href='.$url.'>'.$file['fileMeta']['originalFileName'].'</a>',
                            );
                        }
                        break;

                    case 'Single Radio':
                    case 'Single Select':
                        if ( isset($drf['radioSelection']) ) {
                            foreach ($drf['radioSelection'] as $ro_id => $rs) {
                                if ( $rs['selected'] == 1 ) {
                                    $df_value = $rs['radioOption']['optionName'];
                                    break;
                                }
                            }
                        }
                        break;

                    default:
                        $save_value = false;
                        break;
                }
            }

            if ($save_value)
                $data[$df_id] = $df_value;
        }

        // Save and return the cached version of the data
        $this->cache_service->set('cached_table_data_'.$datarecord_id, $data);
        return $data;
    }


    /**
     * Extracted from getRowData() in an attempt to permit better recovery from errors...
     *
     * @param array $theme_array
     * @param int $theme_id
     * @param array $datatype_array
     * @param int $datatype_id
     *
     * @return array
     */
    private function getValidDatafields($theme_array, $theme_id, $datatype_array, $datatype_id)
    {
        $datafield_ids = array();
        foreach ($theme_array[$theme_id]['themeElements'] as $te_num => $te) {
            // Ignore hidden themeElements
            if ( $te['themeElementMeta']['hidden'] == 1 )
                continue;

            if ( isset($te['themeDataFields']) ) {
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    // If the data in the datafield still exists after filtering...
                    if ( isset($datatype_array[$datatype_id]['dataFields'][$df_id]) ) {
                        $df = $datatype_array[$datatype_id]['dataFields'][$df_id];

                        // ...and the datafield isn't "hidden" for this theme...
                        if ( $tdf['hidden'] == 1 )
                            continue;

                        // ...and the field's type name is on the list of valid fieldtypes...
                        $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                        if ( !in_array($typename, $this->valid_fieldtypes) )
                            continue;

                        // ...then store the datafield id
                        $datafield_ids[] = $df_id;
                    }
                }
            }
        }

        return $datafield_ids;
    }


    /**
     * Returns the array version of the datafield at position $column_num in the given theme.
     *
     * @param ODRUser $user
     * @param int $datatype_id
     * @param int $theme_id
     * @param int $column_num
     *
     * @throws ODRException
     *
     * @return array
     */
    public function getDatafieldAtColumn($user, $datatype_id, $theme_id, $column_num)
    {
        // ----------------------------------------
        // Get the datatype and theme data
        $include_links = false;
        $datatype_array = $this->dti_service->getDatatypeArray($datatype_id, $include_links);
        $theme_array = $this->theme_service->getThemeArray($theme_id);

        // Don't want any child datatypes, or themes for child datatypes, in their respective arrays
        foreach ($datatype_array as $dt_id => $dt) {
            if ($datatype_id !== $dt_id)
                unset( $datatype_array[$dt_id] );
        }
        foreach ($theme_array as $t_id => $t) {
            if ($theme_id !== $t_id)
                unset( $theme_array[$t_id] );
        }

        // Filter out everything the user isn't allowed to view
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);

        $datarecord_array = array();
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


        // ----------------------------------------
        $df_count = -1;

        foreach ($theme_array[$theme_id]['themeElements'] as $te_num => $te) {
            // Ignore theme elements that are hidden
            if ( $te['themeElementMeta']['hidden'] == 1 )
                continue;

            if ( isset($te['themeDataFields']) ) {
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    $df_id = $tdf['dataField']['id'];

                    // If the datafield still exists in the datatype array...
                    if ( isset($datatype_array[$datatype_id]['dataFields'][$df_id]) ) {
                        $df = $datatype_array[$datatype_id]['dataFields'][$df_id];

                        // ...and the datafield isn't "hidden" for this theme...
                        if ( $tdf['hidden'] == 1 )
                            continue;

                        // ...and the field's type name is on the list of valid fieldtypes
                        $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                        if ( !in_array($typename, $this->valid_fieldtypes) )
                            continue;

                        // This is a valid datafield for a table theme
                        $df_count++;

                        // If the position of this datafield matches the desired sort column...
                        if ($df_count == $column_num)
                            // ...return the array form of that datafield
                            return $df;
                    }
                }
            }
        }

        throw new ODRException('Unable to locate datafield entry for sorting');
    }
}
