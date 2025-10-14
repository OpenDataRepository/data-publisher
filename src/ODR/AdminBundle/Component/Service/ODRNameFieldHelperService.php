<?php

/**
 * Open Data Repository Data Publisher
 * ODRNameField Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains functions to assist with displaying a datarecord's name value...
 */

namespace ODR\AdminBundle\Component\Service;

// Services
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Other
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerInterface;


class ODRNameFieldHelperService
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
     * @var Logger
     */
    private $logger;


    /**
     * ODRNameFieldHelperService constructor.
     *
     * @param ContainerInterface $container
     * @param CacheService $cache_service
     * @param Logger $logger
     */
    public function __construct(
        ContainerInterface $container,
        CacheService $cache_service,
        Logger $logger
    ) {
        $this->container = $container;
        $this->cache_service = $cache_service;
        $this->logger = $logger;
    }


    /**
     * ODR tries to display a datarecord's "name" in a couple areas...but determining what to display
     * is surprisingly difficult once a "name" involves these two pieces of criteria...
     *  1) A record's name could be partially based on the value of a field in a linked descendant
     *  2) The values making up a record's name could come from fields that want to run render plugins
     *
     * With those two pieces of criteria...building the name can't really be done during the caching
     * of the datarecord itself, because that part of the code doesn't have access to the linked
     * datarecords some of the fields could come from.  It also can't really be done inside a twig
     * filter, since the arrays being filtered could have had part of the name filtered out.
     *
     * This means doing it relatively early in ODRRenderService is the only real option...
     *
     * @param array $datatype_array Needs to be unstacked and unfiltered
     * @param array $datarecord_array Needs to be unstacked and unfiltered
     */
    public function checkNameFields($datatype_array, &$datarecord_array)
    {
        $records_to_recache = array();
        /** @var DatafieldPluginInterface[] $render_plugin_lookup */
        $render_plugin_lookup = array();

        // ----------------------------------------
        // Determine if any of the datarecords don't have a nameField_formatted value yet...
        foreach ($datarecord_array as $dr_id => $dr) {
            // NOTE: due to the datarecord array being unstacked, don't need recursion to check
            //  namefields in child records
            $gp_dr_id = $dr['grandparent']['id'];

            if ( $dr['nameField_formatted'] === '' ) {
                // ...if the record doesn't have a formatted value, then we're going to have to figure
                //  it out
                $dt_id = $dr['dataType']['id'];
                $dt = $datatype_array[$dt_id];

                if ( empty($dt['nameFields']) ) {
                    // The datatype should have at least one namefield set, but if it doesn't then
                    //  just store the datarecord id in there
                    $datarecord_array[$dr_id]['nameField_formatted'] = $dr_id;

                    // Going to want to recache the grandparent datarecord so we don't have to keep
                    //  doing this...
                    $records_to_recache[$gp_dr_id] = 1;
                }
                else {
                    // The datatype has at least one namefield
                    $name_field_values = array();
                    foreach ($dt['nameFields'] as $display_order => $df_id) {
                        // The datafield listed here may not necessarily belong to the current
                        //  datatype...
                        $is_link = false;
                        $plugin_df = self::locateDatafield($datatype_array, $dt_id, $df_id, $is_link);
                        if ( is_null($plugin_df) ) {
                            // If the datafield can't be found, then the datatype is referring to
                            //  a datafield it shouldn't be trying to use as a namefield
                            throw new ODRException('Unable to find df '.$df_id.', supposed to be nameField '.$display_order.' for datatype '.$dt_id, 500, 0x6feb22da);
                        }

                        // ...same thing for the datarecord, though it will exist at this point
                        //  assuming the previous error wasn't thrown
                        $plugin_dr = self::locateDatarecord($datarecord_array, $dr_id, $df_id);

                        // NOTE: can't really attempt to optimize this process by grabbing existing
                        //  'nameField_formatted' values
                        // NOTE: this process can also technically leak part of a non-public record
                        //  ...but since they wouldn't be able to create new links to other non-public
                        //  records to expose other data, I don't think it's worth trying to prevent

                        // Determine if the datafield has a render plugin attached...
                        if ( empty($plugin_df['renderPluginInstances']) ) {
                            // ...it doesn't, so fall back to whatever value is stored in the field
                            $name_field_values[$display_order] = self::getValue($plugin_dr, $df_id);
                        }
                        else {
                            // ...it does, but the plugin may not necessarily feel like running...
                            foreach ($plugin_df['renderPluginInstances'] as $rpi_id => $rpi) {
                                $rp = $rpi['renderPlugin'];
                                if ( $rp['active'] ) {
                                    $plugin_class_name = $rp['pluginClassName'];

                                    // Want to load these plugins as little as possible
                                    if ( !isset($render_plugin_lookup[$plugin_class_name]) )
                                        $render_plugin_lookup[$plugin_class_name] = $this->container->get($plugin_class_name);
                                    $render_plugin = $render_plugin_lookup[$plugin_class_name];

                                    // Need to provide some rendering options to the plugins...
                                    $rendering_options = array(
                                        'context' => 'text',    // TODO - there's also an 'html' context that's currently only used by the reference plugins...does it make sense to use it for this?
                                        'is_link' => $is_link,
                                        'is_datatype_admin' => false  // TODO - nothing in 'text' context uses this, but if that changes it could quickly become problematic...can't have error messages being saved in cached_datarecord_<dr_id>
                                    );
                                    if ( $render_plugin->canExecutePlugin($rpi, $plugin_df, $plugin_dr, $rendering_options) ) {
                                        // plugin does want to execute, so let it
                                        $name_field_values[$display_order] = $render_plugin->execute($plugin_df, $plugin_dr, $rpi, $rendering_options);
                                    }
                                    else {
                                        // plugin doesn't want to execute, so fall back to whatever
                                        //  value is stored in the field
                                        $name_field_values[$display_order] = self::getValue($plugin_dr, $df_id);
                                    }
                                }
                            }
                        }

                        // TODO - technically, the datatype plugins could also want to change this value as well...
                    }

                    // Now that the values have all been processed, save them
                    $datarecord_array[$dr_id]['nameField_formatted'] = implode(' ', $name_field_values);
                    // Going to want to recache the grandparent datarecord so we don't have to keep
                    //  doing this...
                    $records_to_recache[$gp_dr_id] = 1;
                }
            }
        }

        // ----------------------------------------
        // Now that all datarecords are guaranteed to have a nameField_formatted value, recache
        //  any records that got modified
        foreach ($records_to_recache as $gp_dr_id => $num) {
            // Need to locate all of this datarecord's non-linked descendants...
            $tmp = array();
            foreach ($datarecord_array as $dr_id => $dr) {
                if ( $dr['grandparent']['id'] === $gp_dr_id )
                    $tmp[$dr_id] = $dr;
            }

            // ...so they can get recached together
            $this->cache_service->set('cached_datarecord_'.$gp_dr_id, $tmp);
        }
    }


    /**
     * Since the arrays aren't stacked in this service, it's somewhat harder to locate an arbitrary
     * datafield/datarecord...
     *
     * @param array $datatype_array
     * @param int $dt_id
     * @param int $df_id
     * @param bool $is_link will be set to true if the datafield belongs to a linked descendant
     * @return array|null
     */
    private function locateDatafield($datatype_array, $dt_id, $df_id, &$is_link)
    {
        // Attempt to look in the given datatype first...
        $dt = $datatype_array[$dt_id];
        if ( isset($dt['dataFields'][$df_id]) ) {
            // ...the requested datafield belongs to the given datatype, so return that
            return $dt['dataFields'][$df_id];
        }
        else if ( isset($dt['descendants']) ) {
            // ...otherwise, have to go digging through the datatype's descendants to try to find
            //  the requested datafield
            foreach ($dt['descendants'] as $descendant_dt_id => $data) {
                if ( $data['multiple_allowed'] === 0 ) {
                    // Namefields are only allowed to come from single-allowed descendants
                    $df = self::locateDatafield($datatype_array, $descendant_dt_id, $df_id, $is_link);
                    if ( !is_null($df) ) {
                        // If this descendant had the namefield, then ensure $is_link is set
                        //  correctly prior to returning
                        if ( $data['is_link'] === 1 )
                            $is_link = true;

                        return $df;
                    }
                }
            }
        }

        // If this point is reached, then the datafield isn't found in this datatype or any of
        //  its descendants. This can be legitimate...
        return null;
    }


    /**
     * Since the arrays aren't stacked in this service, it's somewhat harder to locate an arbitrary
     * datafield/datarecord...
     *
     * @param array $datarecord_array
     * @param int $dr_id
     * @param int $df_id
     * @return array|null
     */
    private function locateDatarecord($datarecord_array, $dr_id, $df_id)
    {
        // Attempt to look in the given datarecord first...
        $dr = $datarecord_array[$dr_id];
        if ( isset($dr['dataRecordFields'][$df_id]) ) {
            // ...the requested datafield belongs to the given datarecord, so return that
            return $dr;
        }
        else if ( isset($dr['children']) ) {
            // ...otherwise, have to go digging through the datarecord's descendants to try to find
            //  the requested datafield
            foreach ($dr['children'] as $descendant_dt_id => $dr_list) {
                // Namefields are only allowed to come from single-allowed descendants...
                if ( count($dr_list) === 1 ) {
                    $child_dr_id = $dr_list[0];
                    $dr = self::locateDatarecord($datarecord_array, $child_dr_id, $df_id);
                    if ( !is_null($dr) ) {
                        // If this descendant had the namefield, then return it
                        return $dr;
                    }

                    // TODO - this could techncially be combined with locateDatafield() to reduce the number of recursion calls
                }
            }
        }

        // If this point is reached, then the datafield isn't found in this datarecord or any of
        //  its descendants. This can be legitimate...
        return null;
    }


    /**
     * Since the arrays aren't stacked in this service, it's somewhat harder to locate the value
     * for an arbitrary datafield...
     *
     * @param array $dr
     * @param int $df_id
     * @return string
     */
    private function getValue($dr, $df_id)
    {
        // There might not be a value for this datafield in this datarecord...
        $drf = null;
        if ( isset($dr['dataRecordFields'][$df_id]) ) {
            // ...but that isn't the case here
            $drf = $dr['dataRecordFields'][$df_id];
        }

        // If something was found...
        if ( !is_null($drf) ) {
            // ...then determine the typeclass before returning whatever value the datafield has
            $typeclass = lcfirst( $drf['dataField']['dataFieldMeta']['fieldType']['typeClass'] );
            if ( !isset($drf[$typeclass][0]['value']) )
                return '';

            if ( $typeclass === 'datetimeValue' )
                return ($drf[$typeclass][0]['value'])->format('Y-m-d');    // matches DatarecordInfoService::getValue()
            else
                return $drf[$typeclass][0]['value'];
        }
        else {
            // ...there may not be a value for this datafield
            return '';
        }
    }
}
