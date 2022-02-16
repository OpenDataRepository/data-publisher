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
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var DatarecordInfoService
     */
    private $dri_service;

    /**
     * @var DatatreeInfoService
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
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_info_service
     * @param Router $router
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        CacheService $cache_service,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatreeInfoService $datatree_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        Router $router,
        Logger $logger
    ) {
        $this->container = $container;
        $this->cache_service = $cache_service;
        $this->dbi_service = $database_info_service;
        $this->dri_service = $datarecord_info_service;
        $this->dti_service = $datatree_info_service;
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
        // First and second columns are always datarecord id and sort value, respectively
        $column_names  = '{"title":"datarecord_id","visible":false,"searchable":false},';
        $column_names .= '{"title":"datarecord_sortvalue","visible":false,"searchable":false},';
        $num_columns = 2;

        // Get the array versions of the datafields being viewed by the user
        $df_array = self::getTableThemeDatafields($datatype_id, $theme_id, $user, 0x4ffd4e67);
        foreach ($df_array as $num => $df) {
            // Extract the datafield's name
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

        // Return the data back to the user
        $column_data = array('column_names' => $column_names, 'num_columns' => $num_columns);
        return $column_data;
    }


    /**
     * Returns the array version of the datafield at position $column_num in the given table theme.
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
        // Get the array versions of the datafields being viewed by the user
        $df_array = self::getTableThemeDatafields($datatype_id, $theme_id, $user, 0x973bdd1f);

        // Return the array version of the datafield if it exists
        if ( isset($df_array[$column_num]) )
            return $df_array[$column_num];

        // Otherwise, throw an exception
        throw new ODRException('Unable to locate the datafield entry at column '.$column_num.' for sorting');
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
        // Get the array versions of the datafields being viewed by the user
        $df_array = self::getTableThemeDatafields($datatype_id, $theme_id, $user, 0x7e562032);

        // Going to need to know whether the user has the can_view_datarecord permission for each
        //  datatype that's going to be rendered...
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $can_view_datarecord = array();
        // ...and also need to know whether any of the datatypes that are going to be rendered are
        //  a linked datatype, since that means linked datarecords need to be loaded as well...
        $needs_linked_data = false;

        $associated_datatypes = $this->dti_service->getAssociatedDatatypes($datatype_id);
        foreach ($df_array as $num => $df) {
            $dt_id = $df['dataType']['id'];

            if ( !isset($can_view_datarecord[$dt_id]) ) {
                // Store whether the user can view non-public datarecords for this datatype
                $can_view_datarecord[$dt_id] = false;

                if ( isset($user_permissions['datatypes'][$dt_id])
                    && isset($user_permissions['datatypes'][$dt_id]['dr_view'])
                ) {
                    $can_view_datarecord[$dt_id] = true;
                }
            }

            if ( $dt_id !== $datatype_id && in_array($dt_id, $associated_datatypes) ) {
                // This datafield does not belong to the top-level datatype, and also does not
                //  belong to one of the top-level datatype's children...therefore, linked record
                //  data is required
                $needs_linked_data = true;
            }
        }


        // ----------------------------------------
        // Load the cached version of each of the requested datarecords
        $datarecord_array = array();
        foreach ($datarecord_ids as $num => $search_dr_id) {
            // Load the datarecord being searched on first
            $dr_data = $this->cache_service->get('cached_table_data_'.$search_dr_id);
            if ($dr_data == false)
                $dr_data = self::buildTableData($search_dr_id);
            $datarecord_array[$search_dr_id] = $dr_data;

            // If linked datarecords need to be loaded...
            if ( $needs_linked_data ) {
                // ...then determine which ones to load
                $associated_datarecords = $this->dti_service->getAssociatedDatarecords($search_dr_id);

                foreach ($associated_datarecords as $num => $tmp_dr_id) {
                    // Don't load $search_dr_id again...
                    if ( $tmp_dr_id !== $search_dr_id ) {
                        // Load the linked datarecord
                        $dr_data = $this->cache_service->get('cached_table_data_'.$tmp_dr_id);
                        if ($dr_data == false)
                            $dr_data = self::buildTableData($tmp_dr_id);

                        // TODO - ...this doesn't filter out fields/records from linked datatypes that aren't legal for table themes
                        // Don't want to save the sortfield_value of the linked datarecord...
                        unset( $dr_data['sortField_value'] );
                        // ...but save everything else in the datarecord being searched on
                        foreach ($dr_data as $df_id => $df_data)
                            $datarecord_array[$search_dr_id][$df_id] = $df_data;
                    }
                }
            }
        }


        // ----------------------------------------
        // Build the final array of data
        $rows = array();
        foreach ($datarecord_array as $dr_id => $dr) {
            $dr_data = array();

            foreach ($df_array as $num => $df) {
                // Attempt to pull the data from the cached entry...
                $df_id = $df['id'];
                $dt_id = $df['dataType']['id'];

                if ( !isset($dr[$df_id]) ) {
                    // ...data isn't set, so it's probably empty or null...store empty string
                    $dr_data[] = '';
                }
                else if ( is_array($dr[$df_id]) ) {
                    // Need to ensure that names/links to non-public Files aren't displayed
                    //  to people that don't have permission to view them
                    $file_publicDate = $dr[$df_id]['publicDate'];
                    $file_url = $dr[$df_id]['url'];

                    if ($can_view_datarecord[$dt_id] || $file_publicDate != '2200-01-01')
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
            if ( count($dr_data) > 0 ) {
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
        $dt_data = $this->dbi_service->getDatatypeArray($dt_id, $include_links);
        $datatype_array = $dt_data[$dt_id];


        // Only want to save the data for the top-level datarecord
        $data = array('sortField_value' => $dr['sortField_value']);
        foreach ($datatype_array['dataFields'] as $df_id => $df) {
            // The datarecord might not have an entry for this datafield...
            $df_typename = $df['dataFieldMeta']['fieldType']['typeName'];
            $df_value = '';
            $save_value = true;

            // If the datafield is using a render plugin, and is not a file datafield...
            $render_plugin_instance = null;
            if ( !empty($df['renderPluginInstances']) && $df_typename !== 'File' ) {
                // ...then check whether the render plugin should be run
                foreach ($df['renderPluginInstances'] as $rpi_num => $rpi) {
                    if ( $rpi['renderPlugin']['active'] && $rpi['renderPlugin']['render'] ) {
                        // The datafield should only have a single render plugin that actually does
                        //  something, so don't look further
                        $render_plugin_instance = $rpi;
                        break;
                    }
                }
            }

            // If the field has a render plugin...
            $using_render_plugin = false;
            if ( !is_null($render_plugin_instance) ) {
                // ...then load the render plugin...
                $render_plugin = $render_plugin_instance['renderPlugin'];
                try {
                    /** @var DatafieldPluginInterface $plugin */
                    $plugin = $this->container->get($render_plugin['pluginClassName']);
                    // ...to test whether it should be executed
                    $rendering_options = array('context' => 'text');
                    if ( $plugin->canExecutePlugin($render_plugin_instance, $df, $dr, $rendering_options) ) {
                        // If so, then get the value from the plugin
                        $df_value = $plugin->execute($df, $dr, $render_plugin_instance, $rendering_options);
                        $using_render_plugin = true;
                    }
                    // If the plugin declines to execute here, then the rest of this function will
                    //  just use the raw value
                }
                catch (\Exception $e) {
                    throw new ODRException( 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$df['id'].' Datarecord '.$dr['id'].': '.$e->getMessage(), 500, 0x23568871, $e );
                }
            }

            if ( $using_render_plugin ) {
                /* The render plugin has already returned a string to use, do nothing */
            }
            else if ( !isset($dr['dataRecordFields']) || !isset($dr['dataRecordFields'][$df_id]) ) {
                /* A drf entry hasn't been created for this storage entity...just use the empty string */
            }
            else {
                // ...otherwise, (almost) directly transfer the data
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
     * Returns the array versions of each of the datafields in $datatype_id that are visible by the
     * given user and marked for use in the table theme $theme_id.
     *
     * @param int $datatype_id
     * @param int $theme_id
     * @param ODRUser $user
     * @param int $exception_code
     *
     * @return array
     */
    private function getTableThemeDatafields($datatype_id, $theme_id, $user, $exception_code)
    {
        // Load the cached datatype array, filtering child/linked datatypes that permit multiple
        //  records and fields that make no sense in a table layout
        $datatype_array = self::getDatatypeArrayForTableTheme($datatype_id);

        // Filter out everything the user isn't allowed to see
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $datarecord_array = array();
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // Load a version of the cached theme array that matches the filtered datatype array
        $theme_array = self::getThemeArrayForTableTheme($theme_id, $datatype_array);
        if ( !self::hasVisibleDatafields($theme_array) ) {
            // The user isn't able to see any datafields in this theme...could be because the user
            //  is lacking view permissions, or because of invalid fieldtypes, or because of hidden
            //  datafields/datatypes...

            // So, attempt to fallback to the datatype's master theme
            $master_theme = $this->theme_service->getDatatypeMasterTheme($datatype_id);
            $theme_id = $master_theme->getId();

            // See if this user will have any better luck with the master layout
            $theme_array = self::getThemeArrayForTableTheme($theme_id, $datatype_array);
            if ( !self::hasVisibleDatafields($theme_array) ) {
                // ...still no visible datafields.  Throw an error to prevent ODR from claiming
                //  that nothing matched the search
                throw new ODRException('No Datafields are visible', 500, $exception_code);
            }
        }

        // Now that we have a theme where the user can see something, it needs to be stacked so
        //  datafields are guaranteed to be in the correct order.  If table themes weren't required
        //  to be able to display fields from child/linked datatypes, then this wouldn't be needed
        $stacked_theme_array = $this->theme_service->stackThemeArray($theme_array, $theme_id);

        // Since the theme array is stacked, building a list of the datafields inside it needs to
        //  be done recursively
        return self::getTableThemeDatafields_worker($datatype_array, $stacked_theme_array);
    }


    /**
     * Returns whether the given unstacked theme array has at least one visible datafield
     *
     * @param array $theme_array
     *
     * @return bool
     */
    private function hasVisibleDatafields($theme_array)
    {
        // Since the theme array is unstacked, there may be more than one theme in here
        foreach ($theme_array as $t_id => $t) {
            foreach ($t['themeElements'] as $te_num => $te) {
                if (isset($te['themeDataFields'])) {
                    // getThemeArrayForTableTheme() has already filtered out datafields that the user
                    //  can't view...so the presence of anything at all fulfills the condition
                    if (!empty($te['themeDataFields']))
                        return true;
                }

                // themeDatatype entries don't matter since the array is unstacked
            }
        }

        // Didn't find any visible datafields
        return false;
    }


    /**
     * Because the theme array is stacked, finding the datafield entries in it needs to be done
     * recursively.
     *
     * @param array $datatype_array
     * @param array $theme_array
     *
     * @return array
     */
    private function getTableThemeDatafields_worker($datatype_array, $theme_array)
    {
        // Could be multiple datatypes represented here
        $df_array = array();
        $dt_id = $theme_array['dataType']['id'];

        foreach ($theme_array['themeElements'] as $te_num => $te) {
            if ( isset($te['themeDataFields']) ) {
                // getThemeArrayForTableTheme() has already filtered out datafields that the user
                //  can't view
                foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                    // Locate the datafield's entry in the datatype array
                    $df_id = $tdf['dataField']['id'];
                    $df = $datatype_array[$dt_id]['dataFields'][$df_id];

                    // Splice the datatype id back into the array since it'll be needed
                    $df['dataType'] = array(
                        'id' => $dt_id
                    );

                    // Store the datafield and continue looking
                    $df_array[] = $df;
                }
            }
            else if ( isset($te['themeDataType']) ) {
                // Need to recursively check for usable datafields in the child/linked datatype
                $tdt = $te['themeDataType'][0];
                $child_theme_id = $tdt['childTheme']['id'];
                $child_theme = $tdt['childTheme']['theme'][$child_theme_id];

                // If the child theme actually has data in it...
                if ( !empty($child_theme) ) {
                    // Each of those datafields needs to be appended to the array being built
                    $child_df_array = self::getTableThemeDatafields_worker($datatype_array, $child_theme);
                    foreach ($child_df_array as $df)
                        $df_array[] = $df;
                }
            }
        }

        return $df_array;
    }


    /**
     * Loads the cached datatype array for the given datatype, and filters out datatypes/datafields
     * that make no sense in a table layout.
     *
     * @param int $top_level_datatype_id
     *
     * @return array
     */
    private function getDatatypeArrayForTableTheme($top_level_datatype_id)
    {
        // Might as well load layout data for linked datatypes here
        $include_links = true;
        $datatype_array = $this->dbi_service->getDatatypeArray($top_level_datatype_id, $include_links);

        // The array needs to be filtered to only contain what a table layout can display...
        foreach ($datatype_array as $dt_id => $dt) {
            // If this datatype has children...
            if ( isset($dt['descendants']) ) {
                // ...then filter out child datatypes that permit multiple records
                foreach ($dt['descendants'] as $c_dt_id => $c_dt_data) {
                    if ($c_dt_data['multiple_allowed'] == 1) {
                        unset($datatype_array[$c_dt_id]);
                        unset($datatype_array[$dt_id]['descendants'][$c_dt_id]);
                    }
                }
            }

            // Also filter out datafields that make no sense in a table layout
            if ( isset($dt['dataFields']) ) {
                foreach ($dt['dataFields'] as $df_id => $df) {
                    $typename = $df['dataFieldMeta']['fieldType']['typeName'];
                    if ( !in_array($typename, $this->valid_fieldtypes) )
                        unset( $datatype_array[$dt_id]['dataFields'][$df_id] );
                }
            }
        }

        return $datatype_array;
    }


    /**
     * Loads the cached theme array for the requested theme, and then filters it to match the
     * given datatype array.
     *
     * @param int $theme_id
     * @param array $filtered_datatype_array
     *
     * @return array
     */
    private function getThemeArrayForTableTheme($theme_id, $filtered_datatype_array)
    {
        $theme_array = $this->theme_service->getThemeArray($theme_id);

        // The array needs to be filtered to match the datatype array
        foreach ($theme_array as $t_id => $t) {
            // No sense checking this theme if the datatype it points to got filtered out
            $dt_id = $t['dataType']['id'];
            if ( !isset($filtered_datatype_array[$dt_id]) ) {
                unset( $theme_array[$t_id] );
            }
            else {
                foreach ($t['themeElements'] as $te_num => $te) {
                    if ( $te['themeElementMeta']['hidden'] == 1 ) {
                        // This theme element is either hidden, or has no datafields
                        // Delete it out of the array since nothing will get displayed
                        unset( $theme_array[$t_id]['themeElements'][$te_num] );
                    }
                    else if ( isset($te['themeDataFields']) ) {
                        // This theme element has datafields
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            $df_id = $tdf['dataField']['id'];

                            if ( $tdf['hidden'] == 1
                                || !isset($filtered_datatype_array[$dt_id]['dataFields'][$df_id])
                            ) {
                                // This datafield is either hidden, or it's already been filtered
                                //  out of the datatype array...delete it out of the theme array
                                unset( $theme_array[$t_id]['themeElements'][$te_num]['themeDataFields'][$tdf_num] );
                            }
                        }
                    }
                    else {
                        // This is a themeDatatype entry...ignore it for right now, it'll be used
                        //  for stacking the theme array later
                    }
                }
            }
        }

        return $theme_array;
    }
}
