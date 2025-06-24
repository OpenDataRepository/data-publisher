<?php

/**
 * Open Data Repository Data Publisher
 * File Renamer Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The RRUFF Project database (originally at rruff.info) renamed pretty much every single spectra
 * and image file that was uploaded to the website, so that users would see filenames like
 * "Actinolite_R040063_Sample_Photo_34230_M.jpg" instead of "040063(32)(8_0)1024.jpg".
 *
 * The problem with this is that getting these various pieces of data only really works when you have
 * a database with a known/pre-defined structure...and unfortunately, *THE PRIMARY DESIGN PRINCIPLE*
 * of ODR is that users need to be able to change the structure of their database, and it needs to
 * try to keep working even if they change stuff on a whim.
 *
 *
 * Unsurprisingly then, this plugin has to do many terrible things to ODR.  It can't change the
 * filename after the file is encrypted because of async problems (see FilePreEncryptEvent), so it
 * has to instead rename the file on the server before the encryption process gets to it...making the
 * encryption process think the file was originally uploaded with the desired name.
 *
 * Because the database structure can change on a whim, there's no reliable method for the file/image
 * field in question to "depend on" the fields this plugin is configured to use...and therefore it's
 * effectively impossible for changes made to these fields to automatically trigger an update to the
 * relevant files/images.
 *
 * On top of this, the "relevant fields" can come from a linked database, so any config made for this
 * plugin has a very real chance of getting invalidated due to changes made by a user that doesn't
 * even have permissions to touch the plugin's config in the first place.  There is no solution for
 * this problem that won't violate the autonomy of one of the relevant datatype admin.  Additionally,
 * this means that the plugin violates one of the other primary design principles of ODR...which is
 * that each database can be handled as its own self-contained unit.
 *
 * Finally, automatically checking whether the filename is out of sync is incredibly expensive,
 * and ODR is already slow enough.  As such, there's no way to notify the user when a filename is
 * "out of date".  The next easiest method involves hijacking the Edit and MassEdit controllers so
 * the user at least has the option of manually triggering a rename...and it still requires additional
 * events and controllers to work correctly.
 *
 *
 * Given how many of ODR's design principles this plugin violates, I'm surprised it works at all.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Base;

// ODR
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginOptionsDef;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\FilePreEncryptEvent;
use ODR\AdminBundle\Component\Event\MassEditTriggerEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class FileRenamerPlugin implements DatafieldPluginInterface, PluginSettingsDialogOverrideInterface, MassEditTriggerEventInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $database_info_service;

    /**
     * @var DatarecordInfoService
     */
    private $datarecord_info_service;

    /**
     * @var DatatreeInfoService
     */
    private $datatree_info_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * FileRenamer Plugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatreeInfoService $datatree_info_service,
        EntityMetaModifyService $entity_meta_modify_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->entity_modify_service = $entity_meta_modify_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datafield, $datarecord, $rendering_options)
    {
        if ( isset($rendering_options['context']) ) {
            $context = $rendering_options['context'];

            // The FileRenamer Plugin should work in the 'edit' context
            if ( $context === 'edit' ) {
                // This plugin technically coexists with the FileHeaderInserter and RRUFFFileHeaderInserter
                //  plugins...but it's better for this plugin to refuse to activate and let the other
                //  two insert this plugin's icon if they're also attached to this field
                foreach ($datafield['renderPluginInstances'] as $rpi_id => $rpi) {
                    if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter'
                        || $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.file_header_inserter'
                    ) {
                        return false;
                    }
                }

                return true;
            }

            // TODO - also work in the 'display' context?  but finding errors to display is expensive...
        }

        return false;
    }


    /**
     * Executes the FileRenamer Plugin on the provided datafield
     *
     * @param array $datafield
     * @param array|null $datarecord
     * @param array $render_plugin_instance
     * @param array $rendering_options
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datafield, $datarecord, $render_plugin_instance, $rendering_options)
    {

        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];


            // ----------------------------------------
            // ODR is designed so that render plugins can completely override the rendering system...
            //  which makes it difficult for plugins to cooperatively change the HTML

            // This plugin technically coexists with the FileHeaderInserter and RRUFFFileHeaderInserter
            //  plugins...but it's better for this plugin to refuse to activate and let the other
            //  two insert this plugin's icon if they're also attached to this field
            foreach ($datafield['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter'
                    || $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.file_header_inserter'
                ) {
                    return '';
                }
            }

            $output = "";
            if ( $rendering_options['context'] === 'display' ) {
                // TODO - also work in the 'display' context?  but finding errors to display is expensive...

//                $output = $this->templating->render(
//                    'ODROpenRepositoryGraphBundle:Base:FileRenamer/display_file_datafield.html.twig',
//                    array(
//                        'datafield' => $datafield,
//                        'datarecord' => $datarecord,
//
//                        'value' => $str,
//                    )
//                );
            }
            else if ( $rendering_options['context'] === 'edit' ) {
                $output = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:FileRenamer/file_renamer_edit_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,
                    )
                );
            }

            return $output;
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Returns whether the given datafield is using the FileRenamer plugin.
     *
     * @param DataFields $datafield
     * @param bool $is_pre_encrypt_event
     *
     * @return bool
     */
    private function isEventRelevant($datafield, $is_pre_encrypt_event)
    {
        // Going to use the cached datatype array to locate the correct datafield...
        $datatype = $datafield->getDataType();
        $dt_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links

        $dt = $dt_array[$datatype->getId()];
        if ( !isset($dt['dataFields']) || !isset($dt['dataFields'][$datafield->getId()]) )
            return false;

        $df = $dt['dataFields'][$datafield->getId()];
        if ( !isset($df['renderPluginInstances']) )
            return false;

        foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_renamer' ) {
                // Datafield is using the correct plugin...
                if ( $is_pre_encrypt_event ) {
                    // ...but if it's for a preEncrypt event, then need to also check one of the
                    //  options for this plugin
                    if ( !isset($rpi['renderPluginOptionsMap']['fire_on_pre_encrypt'])
                        || $rpi['renderPluginOptionsMap']['fire_on_pre_encrypt'] === 'yes'
                    ) {
                        // The somewhat strange conditions are meant for the off-chance that the
                        //  plugin config doesn't have this option set
                        return true;
                    }
                }
                else {
                    // ...no reason to not return true here
                    return true;
                }
            }
        }

        // Otherwise, the event needs to be ignored
        return false;
    }


    /**
     * Locates the configuration for the plugin if it exists, and converts it into a more useful
     * array format for actual use.
     *
     * The first entry in the array is always the "prefix" entry...a string of datatype ids which
     * indicate any datatype links that have to be followed to reach the "ultimate ancestor" of a
     * record that is having a file/image renamed.  This "prefix" also assists with actually finding
     * the correct values to use in the file/image filename later on...it will always have at least
     * one datatype id in it.
     *
     * The remainder of the entries are either datafield uuids or string constants.  For instance...
     * array(
     *   'prefix' => '<prefix string>',
     *   'config' => array(
     *      0 => '4bbb2be0f76e2dede80796479d98',
     *      1 => 'Sample_Photo',
     *   )
     * );
     * ...means "find the value of the datafield with uuid '4bbb2be0f76e2dede80796479d98', then append
     * the string 'Sample_Photo'".
     *
     * The uuid of the file/image is always appended at the end, to ensure that filenames remain unique.
     *
     * @param DataFields $datafield
     * @return array
     */
    private function getCurrentPluginConfig($datafield)
    {
        // Going to try to create an array of datafield uuids and string constants...
        $config = array();

        // Neither Event has direct access to the renderPluginInstance, so might as well just always
        //  get the data from the cached datatype array
        $datatype = $datafield->getDataType();
        $datatype_array = $this->database_info_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want linked datatypes
        $dt = $datatype_array[$datatype->getId()];

        // The datafield entry in this array is guaranteed to exist...
        $df = $dt['dataFields'][$datafield->getId()];
        // ...but the renderPluginInstance won't be, if the renderPlugin hasn't been attached to the
        //  datafield in question
        if ( !empty($df['renderPluginInstances']) ) {
            // The datafield could have more than one renderPluginInstance
            foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_renamer' ) {
                    // Need to know the simple configuration values...
                    $separator = '__';
                    if ( isset($rpi['renderPluginOptionsMap']['separator']) )
                        $separator = trim($rpi['renderPluginOptionsMap']['separator']);
                    $period_substitute = '-';
                    if ( isset($rpi['renderPluginOptionsMap']['period_substitute']) )
                        $period_substitute = trim($rpi['renderPluginOptionsMap']['period_substitute']);
                    $file_extension = 'auto';
                    if ( isset($rpi['renderPluginOptionsMap']['file_extension']) )
                        $file_extension = trim($rpi['renderPluginOptionsMap']['file_extension']);
                    $append_file_uuid = 'yes';
                    if ( isset($rpi['renderPluginOptionsMap']['append_file_uuid']) )
                        $append_file_uuid = trim($rpi['renderPluginOptionsMap']['append_file_uuid']);
                    $delete_invalid_characters = 'yes';
                    if ( isset($rpi['renderPluginOptionsMap']['delete_invalid_characters']) )
                        $delete_invalid_characters = trim($rpi['renderPluginOptionsMap']['delete_invalid_characters']);

                    // ...and the semi-encoded value for the list of fields
                    $field_list_value = trim($rpi['renderPluginOptionsMap']['field_list']);

                    // Due to using newlines to separate values, the list of fields probably ends up
                    //  with "\r" characters following a browser submission...
                    $field_list_value = str_replace("\r", "", $field_list_value);
                    $tmp = explode("\n", $field_list_value);

                    // The first line in the config value is the prefix, while each subsequent line
                    //  is either a field uuid or a string constant

                    $config = array(
                        'prefix' => $tmp[0],
                        'config' => array_slice($tmp, 1),
                        'separator' => $separator,
                        'period_substitute' => $period_substitute,
                        'file_extension' => $file_extension,
                        'append_file_uuid' => $append_file_uuid,
                        'delete_invalid_characters' => $delete_invalid_characters,
                    );
                }
            }
        }

        // If the prefix is blank somehow, then the plugin isn't configured
        if ( isset($config['prefix']) && $config['prefix'] === '' )
            return array();
        // If no fields or string constants were set, then the plugin isn't configured
        if ( isset($config['config']) && empty($config['config']) )
            return array();
        // If there's no separator, then the plugin isn't configured
        if ( isset($config['separator']) && $config['separator'] === '' )
            return array();

        if ( isset($config['file_extension']) ) {
            // If the file extension is blank, then it's not configured correctly
            if ( $config['file_extension'] === '' )
                return array();
            // If the file extension ends with a period, then it's not valid
            if ( substr($config['file_extension'], -1) === '.' )
                return array();
        }

        // Otherwise, attempt to return the plugin's config
        return $config;
    }


    /**
     * This plugin attempts to rename all files/images in the datafield it's attached to, based on
     * the values of other acceptable datafields that are related to the datafield using the plugin.
     *
     * A field is considered "acceptable" if it's a text/number field or a single radio/select...it
     * also has to belong to one of the following:
     * 1) the current datatype
     * 2) an ancestor of this datatype (whether it's linked or not)
     * 3) a descendant of any datatype from 1 or 2, provided that only a single child is allowed
     *
     *
     * Additionally, this function determines the "config prefix" that assists self::getNewFilenames()
     * in locating both the correct datarecord to load the cached version of, and also the actual
     * values to use for the new filenames.
     *
     * Unfortunately, due to how ODR works, there could be multiple datatypes that link to the
     * datatype this plugin is getting attached to, so there will be multiple possible prefixes. The
     * user will be forced to choose a single prefix in the render plugin settings dialog, and their
     * chosen prefix will be the one saved to the database.
     *
     * @param DataFields $datafield
     * @return array
     */
    private function getAvailableConfigurations($datafield)
    {
        // ----------------------------------------
        // This could probably be done via the cached datatree array...but long covid seems to have
        //  rendered my brain incapable of visualizing how that would work.
        // Fortunately, the only time this will get called is in the renderplugin settings dialog...
        //  ...so it won't be entirely terrible to load the entire datatree table from the database
        //  and convert it into a format that my brain can use to solve this problem
        $query = $this->em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            WHERE ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL
            AND descendant.deletedAt IS NULL AND dtm.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $by_ancestors = array();
        $by_descendants = array();
        foreach ($results as $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $multiple_allowed = $result['multiple_allowed'];
            // Don't care about whether it's a link or not

            if ( !isset($by_ancestors[$ancestor_id]) )
                $by_ancestors[$ancestor_id] = array();
            $by_ancestors[$ancestor_id][$descendant_id] = $multiple_allowed;

            if ( !isset($by_descendants[$descendant_id]) )
                $by_descendants[$descendant_id] = array();
            $by_descendants[$descendant_id][$ancestor_id] = $multiple_allowed;
        }


        // ----------------------------------------
        // The reason for making two arrays out of the datatree table is because this problem needs
        //  to solved in two steps...
        $all_valid_datatypes = array();
        $prefix_data = array();

        // The first step is to take this datatype and find every single ancestor it has
        $dt_id = $datafield->getDataType()->getId();
        $all_valid_datatypes[$dt_id] = 1;
        $prefix_data[$dt_id] = '';

        $datatypes_to_check = array($dt_id);
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
            foreach ($datatypes_to_check as $num => $descendant_dt_id) {
                if ( isset($by_descendants[$descendant_dt_id]) ) {
                    foreach ($by_descendants[$descendant_dt_id] as $ancestor_id => $multiple_allowed) {
                        // All ancestors of the original datatype are considered valid...
                        $all_valid_datatypes[$ancestor_id] = 1;
                        // ...and need to also find all ancestors of this ancestor datatype
                        $tmp[] = $ancestor_id;

                        if ( !isset($prefix_data[$ancestor_id]) ) {
                            // This is the first time this ancestor has been seen
                            $prefix_data[$ancestor_id] = array($descendant_dt_id => $prefix_data[$descendant_dt_id]);
                        }
                        else {
                            // This descendant has multiple paths to reach the same ancestor...
                            // ...e.g. Sample links to Reference, and Sample links to Mineral which
                            //  also links to Reference...there are two ways to go from Reference to
                            //  Sample in this example.
                            $prefix_data[$ancestor_id][$descendant_dt_id] = $prefix_data[$descendant_dt_id];
                        }
                    }

                    // Don't want to remove previous descendants from this list...it's plausible that
                    //  there are "ancestors" that have no "ownership" over the database in question,
                    //  which is one of the hazards of being able to freely link to (almost) whichever
                    //  database you feel like...
//                    unset( $prefix_data[$descendant_dt_id] );
                }
            }

            // Continue looking for ancestors
            $datatypes_to_check = $tmp;
        }

        // The second step is to take each of the ancestors of the original datatype, and find all
        //  descendants that only allow single child/linked records.  This ensures that there is at
        //  most a single string available to use as part of a filename, regardless of whether the
        //  file/image field belongs to a top-level datatype, or a child/linked datatype

        // TODO - actually, it doesn't quite do that...the "multiple_allowed" is technically "multiple_allowed_descendants"
        // TODO - should something be added to set "multiple_allowed_ancestors"?

        $datatypes_to_check = array_keys($all_valid_datatypes);
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
            foreach ($datatypes_to_check as $num => $ancestor_dt_id) {
                if ( isset($by_ancestors[$ancestor_dt_id]) ) {
                    foreach ($by_ancestors[$ancestor_dt_id] as $descendant_id => $multiple_allowed) {
                        if ( !$multiple_allowed ) {
                            // This ancestor datatype has a descendant that only allows a single
                            //  child/linked record, so it's considered valid...
                            $all_valid_datatypes[$descendant_id] = 1;
                            // ...and need to also find all descendants of this descendant datatype
                            $tmp[] = $descendant_id;
                        }
                    }
                }
            }

            // Continue looking for descendants
            $datatypes_to_check = $tmp;
        }

        // Reverse the array so that it can be used in a database query
        $all_valid_datatypes = array_keys($all_valid_datatypes);


        // ----------------------------------------
        // Now that there's a list of valid datatypes, need to determine which fields are available
        /** @var FieldType[] $all_fieldtypes */
        $all_fieldtypes = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();

        // Only datafields with certain fieldtypes are valid for this application
        $valid_fieldtypes = array();
        foreach ($all_fieldtypes as $ft) {
            switch ($ft->getTypeName()) {
                // These fieldtypes can be converted into a string
                case 'Integer':
                case 'Decimal':
                case 'Short Text':
                case 'Medium Text':
                case 'DateTime':
                case 'Single Radio':
                case 'Single Select':
                    $valid_fieldtypes[] = $ft->getId();
                    break;

                // These fields are excluded due to length concerns
//                case 'Long Text':
//                case 'Paragraph Text':
                // These fields are impossible to convert into a filename
//                case 'Boolean':
//                case 'File':
//                case 'Image':
//                case 'Markdown':
//                case 'Multiple Radio':
//                case 'Multiple Select':
//                case 'Tags':
//                case 'XYZ Data':
                default:
                    /* do nothing */
            }
        }

        // Since there could easily be multiple top-level datatypes from the first step, it's faster
        //  to use a database query to determine the names of the relevant datatypes...
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id, dtm.shortName AS dt_name
            FROM ODRAdminBundle:DataType dt
            JOIN ODRAdminBundle:DataTypeMeta dtm WITH dtm.dataType = dt
            WHERE dt.id IN (:datatype_ids)
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
        )->setParameters( array('datatype_ids' => $all_valid_datatypes) );
        $results = $query->getArrayResult();

        $name_data = array();
        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $dt_name = $result['dt_name'];

            // Want the names of all the valid datatypes, hence the separate query
            if ( !isset($name_data[$dt_id]) )
                $name_data[$dt_id] = array('name' => $dt_name, 'fields' => array());
        }

        // ...then use a second query to determine the ids/names of the relevant datafields
        // Non-public datafields are not allowed to be used
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id, df.id AS df_id, df.fieldUuid, dfm.fieldName
            FROM ODRAdminBundle:DataType dt
            JOIN ODRAdminBundle:DataFields df WITH df.dataType = dt
            JOIN ODRAdminBundle:DataFieldsMeta dfm WITH dfm.dataField = df
            WHERE dt.id IN (:datatype_ids) AND dfm.fieldType IN (:fieldtype_ids)
            AND dfm.publicDate != :public_date
            AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_ids' => $all_valid_datatypes,
                'fieldtype_ids' => $valid_fieldtypes,
                'public_date' => "2200-01-01 00:00:00",
            )
        );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $df_id = $result['df_id'];
            $df_uuid = $result['fieldUuid'];
            $df_name = $result['fieldName'];

            $name_data[$dt_id]['fields'][$df_id] = array('uuid' => $df_uuid, 'name' => $df_name);
        }


        // ----------------------------------------
        // Didn't forget about the prefixes from the first step...it's just easier to display them
        //  to the user if we also have some datatype names to go along with them...
        $prefixes = self::buildPrefixes($prefix_data, $name_data);

        // In an attempt to assist users, the list of "valid datatypes" on the left side of the
        //  renderplugin settings dialog should attempt to display only the datatypes that the
        //  currently selected prefix would provide access to
        $descendants_by_prefix = array();

        foreach ($prefixes as $prefix_string => $name_string) {
            // ...the easiest way to do this is to explode each prefix again, and find all "valid"
            //  datatypes descended from them
            $descendants_by_prefix[$prefix_string] = array();

            $valid_datatypes = explode('_', $prefix_string);

            // Keep track of all valid datatypes for this prefix
            foreach ($valid_datatypes as $num => $dt_id)
                $descendants_by_prefix[$prefix_string][$dt_id] = 1;

            while ( !empty($valid_datatypes) ) {
                $tmp = array();
                foreach ($valid_datatypes as $num => $ancestor_dt_id) {
                    if ( isset($by_ancestors[$ancestor_dt_id]) ) {
                        foreach ($by_ancestors[$ancestor_dt_id] as $descendant_id => $multiple_allowed) {
                            if ( !$multiple_allowed ) {
                                // This ancestor datatype has a descendant that only allows a single
                                //  child/linked record, so it's considered valid...
//                                $descendants_by_prefix[$descendant_id] = 1;
                                // ...and need to also find all descendants of this descendant datatype
                                $tmp[] = $descendant_id;
                            }
                        }
                    }
                }

                // Need to also save any potential descendants found
                foreach ($tmp as $num => $dt_id)
                    $descendants_by_prefix[$prefix_string][$dt_id] = 1;

                // Continue looking for descendants
                $valid_datatypes = $tmp;
            }
        }


        // ----------------------------------------
        return array(
            'prefixes' => $prefixes,
            'name_data' => $name_data,
            'allowed_datatypes' => $descendants_by_prefix,
        );
    }


    /**
     * Recursively builds a hierarchy of datatypes by converting the prefix array into a flattened
     * format.
     *
     * @param array $prefix_data
     * @param array $fields
     * @return array
     */
    private function buildPrefixes($prefix_data, $fields)
    {
        $prefixes = array();

        foreach ($prefix_data as $ancestor_id => $descendants) {
            if ( !is_array($descendants) ) {
                $prefixes[$ancestor_id] = $fields[$ancestor_id]['name'];
            }
            else {
                $descendant_prefixes = self::buildPrefixes($descendants, $fields);
                foreach ($descendant_prefixes as $key => $value)
                    $prefixes[$ancestor_id.'_'.$key] = $fields[$ancestor_id]['name'].' >> '.$value;
            }
        }

        return $prefixes;
    }


    /**
     * Determines what the files/images uploaded to the given dataRecordFields entity should actually
     * be named on the server, based on the given plugin config.
     *
     * This process consists of taking the datarecord with files/images that need renaming, then
     * finding its "ultimate ancestor" datarecord through a targeted database query...due to the
     * rules enforced by self::getAvailableConfigurations(), this "ultimate ancestor" record will
     * contain all the possible values the files/images could be set to once it's loaded from redis.
     *
     * The cached entry can then be iterated over with the rest of the config info to find the values
     * to use for the new filenames for the files/images...though if the plugin isn't configured
     * correctly, or told to run on databases it shouldn't, or records that are expected to exist
     * don't actually exist...then the plugin won't be able to work properly.
     *
     * @param DataRecordFields $original_drf
     * @return array|string Returns an array organized by file/image id, or a single string attempting to indicate why the "correct" names can't be determined
     */
    public function getNewFilenames($original_drf)
    {
        if ( is_null($original_drf) )
            throw new ODRBadRequestException('Unable to find new filenames for a null drf', 0x08fc0ad3);

        // Going to need both the datafield and the datarecord belonging to this drf entry...
        $dr = $original_drf->getDataRecord();
        $df = $original_drf->getDataField();
        $typeclass = $df->getFieldType()->getTypeClass();
        if ( !($typeclass === 'File' || $typeclass === 'Image') )
            throw new ODRBadRequestException('Unable to find new filenames for a '.$typeclass.' field', 0x08fc0ad3);

        // Determine the requested configuration info from the plugin's config
        $config_info = self::getCurrentPluginConfig($df);
        // If nothing is configured, then don't attempt to rename any files/images
        if ( empty($config_info) )
            return array();


        // ----------------------------------------
        // Makes no sense to run all this stuff if the field has no files/images in it
        $entities = array();
        if ( $typeclass === 'File' )
            $entities = $original_drf->getFile();
        else
            $entities = $original_drf->getImage();
        $entities = $entities->toArray();

        if ( $typeclass === 'Image' ) {
            // Ignore thumbnails, since their name depends solely on their parent image
            foreach ($entities as $num => $entity) {
                /** @var Image $entity */
                if ( !$entity->getOriginal() )
                    unset( $entities[$num] );
            }
        }

        if ( empty($entities) )
            return array();


        // ----------------------------------------
        // Need to find the "ultimate ancestor" of this datarecord, as far as the config for this
        //  render plugin is concerned at least.  The "prefix" string...an underscore-separated list
        //  of datatype ids...indicates how far to go looking for this "ultimate ancestor".
        $ancestor_dr_id = null;
        // Also will be useful to get all the intermediate ids
        $intermediate_dr_ids = array();

        $prefix = $config_info['prefix'];
        $split_prefix = explode('_', $prefix);


        // ----------------------------------------
        // If the "prefix" string only has a single datatype id in it, then we don't need to locate
        //  the "ultimate ancestor"...it's just the datarecord of the drf given
        if ( count($split_prefix) == 1 ) {
            // Note that this is desired behavior even if the record not top-level or linked to from
            //  somewhere...it just means that the values for the files only come from this record
            //  and its descendants (if any exist)
            $ancestor_dr_id = $dr->getId();
            $intermediate_dr_ids[$ancestor_dr_id] = 0;
        }
        else {
            // ...but if the "prefix" has more than one datatype id, then we're going to need to build
            //  a query to find the "ultimate ancestor".  Unlike the dataTree table, which contains
            //  entries for both regular and linked descendants, the linkedDataTree table only
            //  contains entries for linked records.

            // This means that the query to find said "ultimate ancestor" might need to keep switching
            //  between locating the parent with the dataRecord table, and locating the ancestor via
            //  the linkedDataTree table...
            $cached_datatree_array = $this->datatree_info_service->getDatatreeArray();

            // Due to doctrine being a pain, just going to manually emulate the querybuilder
            $select_array = array('dr_0.id AS dr_0_id');
            $from_str = 'FROM odr_data_record dr_0';
            $join_array = array();
            $where_array = array('dr_0.data_type_id = :dr_0_dt');
            $deleted_array = array('dr_0.deletedAt IS NULL');
            $params = array('dr_0_dt' => intval($split_prefix[0]));

            $dr_num = 1;
            $ldt_num = 1;
            $param_num = 2;

            for ($i = 1; $i < count($split_prefix); $i++) {
                // This is the primary reason for the "prefix"...already knowing the dataypes makes
                //  building this specific query considerably easier
                $current_dt_id = intval($split_prefix[$i]);
                $prev_dt_id = intval($split_prefix[$i-1]);

                // Determine whether this relationship is a link or not...
                $is_link = false;
                if ( isset($cached_datatree_array['linked_from'][$current_dt_id]) ) {
                    if ( in_array($prev_dt_id, $cached_datatree_array['linked_from'][$current_dt_id]) )
                        $is_link = true;
                }

                if ( !$is_link ) {
                    // If it's not a link, then we just need to join another instance of the
                    //  datarecord table
                    $select_array[] = 'dr_'.$dr_num.'.id AS dr_'.$dr_num.'_id';
                    $join_array[] = 'LEFT JOIN odr_data_record dr_'.$dr_num.' ON dr_'.($dr_num-1).'.id = dr_'.$dr_num.'.parent_id';
                    // Limiting the newly joined table to the correct data_type_id helps mysql immensely
                    $where_array[] = 'dr_'.$dr_num.'.data_type_id = :dr_'.$dr_num.'_dt';
                    $params['dr_'.$dr_num.'_dt'] = $current_dt_id;
                    // Have to define deletedAt since this is a native SQL query
                    $deleted_array[] = 'dr_'.$dr_num.'.deletedAt IS NULL';

                    // Increment these numbers for the next datatype in the prefix
                    $dr_num++;
                    $param_num++;
                }
                else {
                    // If it is a link, then need to join a linkedDataTree table first, then another
                    //  instance of the datarecord table after that
                    $select_array[] = 'dr_'.$dr_num.'.id AS dr_'.$dr_num.'_id';
                    $join_array[] = 'LEFT JOIN odr_linked_data_tree ldt_'.$ldt_num.' ON ldt_'.$ldt_num.'.ancestor_id = dr_'.($dr_num-1).'.id';
                    $join_array[] = 'LEFT JOIN odr_data_record dr_'.$dr_num.' ON ldt_'.$ldt_num.'.descendant_id = dr_'.$dr_num.'.id';
                    // Limiting the newly joined table to the correct data_type_id helps mysql immensely
                    $where_array[] = 'dr_'.$dr_num.'.data_type_id = :dr_'.$dr_num.'_dt';
                    $params['dr_'.$dr_num.'_dt'] = $current_dt_id;
                    // Have to define deletedAt since this is a native SQL query
                    $deleted_array[] = 'ldt_'.$ldt_num.'.deletedAt IS NULL';
                    $deleted_array[] = 'dr_'.$dr_num.'.deletedAt IS NULL';

                    // Increment these numbers for the next datatype in the prefix
                    $dr_num++;
                    $ldt_num++;
                    $param_num++;
                }
            }

            // Need one more WHERE clause to tie the entire query to the datarecord the files/images
            //  were uploaded to...
            $where_array[] = 'dr_'.($dr_num-1).'.id = :dr_'.($dr_num-1).'_id';
            $params['dr_'.($dr_num-1).'_id'] = $dr->getId();

            // Implode all the pieces together...
            $select_str = 'SELECT '.implode(', ', $select_array);
            $join_str = implode("\n", $join_array);
            $where_str = 'WHERE '.implode(' AND ', $where_array);
            $deleted_str = implode(' AND ', $deleted_array);
            // ...so they can be spliced into a single coherent query
            $query = $select_str."\n".$from_str."\n".$join_str."\n".$where_str."\nAND ".$deleted_str;

            // Since it's native SQL, have to get the raw connection...
            $conn = $this->em->getConnection();
            $tmp = $conn->executeQuery($query, $params);

            $results = array();
            foreach ($tmp as $key => $value)
                $results[$key] = $value;

            // Ideally, there's only a single "ultimate ancestor" for the requested datarecord...
            //  but the query could easily return nothing (typically when an expected record hasn't
            //  been linked...), or it could return a lot of records (typically when the plugin is
            //  either configured to run on a datatype that shouldn't be renaming files, or when
            //  the "prefix" includes too many ancestor datatypes)

            // Regardless of why there's a problem, it means that there's no way to get the correct
            //  values to rename the files/images...so nothing should get renamed at all
            if ( count($results) < 1 )
                return "Unable to find the correct ancestors for datarecord ".$dr->getId()."...most likely, one of the ancestors is supposed to be a linked record, but it hasn't been linked to.";
            else if ( count($results) > 1 )
                return "Multiple possible ancestors for datarecord ".$dr->getId()."...plugin is likely either configured wrong, or being used incorrectly.";

            // It'll be useful to save each of the datarecord ids found in the previous query...
            for ($i = 0; $i < $dr_num; $i++)
                $intermediate_dr_ids[$i] = intval($results[0]['dr_'.$i.'_id']);
            $ancestor_dr_id = $intermediate_dr_ids[0];

            // ...but after this point, they're only useful if they're flipped
            $intermediate_dr_ids = array_flip($intermediate_dr_ids);
        }

        // This variable shouldn't be null at this point, but make sure...if it is, then there's no
        //  way to determine what the files/images should be renamed to
        if ( is_null($ancestor_dr_id) )
            return "Unable to find the correct ancestors for datarecord ".$dr->getId()."...no clue why.";


        // ----------------------------------------
        // The "ultimate ancestor" could be a child record if the plugin is configured that way, so
        //  need to get its grandparent id to load all relevant data from a cached array
        $query = $this->em->createQuery(
           'SELECT gp.id
            FROM ODRAdminBundle:DataRecord dr
            JOIN ODRAdminBundle:DataRecord gp WITH dr.grandparent = gp
            WHERE dr.id = :datarecord_id
            AND dr.deletedAt IS NULL AND gp.deletedAt IS NULL'
        )->setParameters( array('datarecord_id' => $ancestor_dr_id) );
        $results = $query->getArrayResult();
        $grandparent_dr_id = $results[0]['id'];

        $dr_array = $this->datarecord_info_service->getDatarecordArray($grandparent_dr_id);
        // Don't want to stack this array, because that would force the use of recursion later on


        // At this point, the config info only has uuids...but the cached datarecord array refers
        //  to fields by ids instead.  It's probably faster to run a quick query to create a mapping
        //  linking field id, uuid, and name (for error purposes, if needed), instead of digging
        //  through the cached array using strcmp() a bunch of times
        $fields = $config_info['config'];

        $query = $this->em->createQuery(
           'SELECT df.id, df.fieldUuid, dfm.fieldName
            FROM ODRAdminBundle:DataFields df
            LEFT JOIN ODRAdminBundle:DataFieldsMeta dfm WITH dfm.dataField = df
            WHERE df.fieldUuid IN (:uuids)
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
        )->setParameters( array('uuids' => $fields) );
        $results = $query->getArrayResult();

        $uuid_mapping = array();
        $datafield_names = array();
        foreach ($results as $result) {
            $id = $result['id'];
            $uuid = $result['fieldUuid'];
            $field_name = $result['fieldName'];

            $uuid_mapping[$id] = $uuid;
            $datafield_names[$id] = $field_name;
        }


        // ----------------------------------------
        // Unfortunately, there doesn't seem to be a better method to actually get the values than to
        //  iterate over every single datafield listed in the plugin's config...if the database was
        //  queried here, it would either have to be done with a massive really slow query like the
        //  one in DatarecordInfoService, or a potentially large number of simple queries...
        $mapping = array();
        foreach ($uuid_mapping as $df_id => $df_uuid) {
            // While the config_prefix attempts to mitigate this, there is a very real possibility
            //  that there are multiple datarecords for each datafield...which results in the
            //  possibility of "multiple values" per datafield...
            $mapping[$df_id] = array();

            // ...so we need to iterate over all records in the cached array and hope we find the
            //  correct/desired value
            foreach ($dr_array as $dr_id => $dr) {
                if ( isset($dr['dataRecordFields'][$df_id]) ) {
                    $drf = $dr['dataRecordFields'][$df_id];
                    if ( isset($drf['shortVarchar']) )
                        $mapping[$df_id][$dr_id] = $drf['shortVarchar'][0]['value'];
                    else if ( isset($drf['integerValue']) )
                        $mapping[$df_id][$dr_id] = $drf['integerValue'][0]['value'];
                    else if ( isset($drf['mediumVarchar']) )
                        $mapping[$df_id][$dr_id] = $drf['mediumVarchar'][0]['value'];
                    else if ( isset($drf['decimalValue']) )
                        $mapping[$df_id][$dr_id] = $drf['decimalValue'][0]['original_value'];
                    else if ( isset($drf['datetimeValue']) )
                        $mapping[$df_id][$dr_id] = $drf['datetimeValue'][0]['value'];
                    else if ( isset($drf['radioSelection']) ) {
                        // Because of the config, this shouldn't be a multiple radio/select...
                        foreach ($drf['radioSelection'] as $ro_id => $ro) {
                            $mapping[$df_id][$dr_id] = $ro['radioOption']['optionName'];
                        }
                    }
                }
            }
        }

        // At this point, $mapping could have zero, one, or more than one value for each datafield
        foreach ($mapping as $df_id => $data) {
            if ( count($data) > 1 ) {
                // When there are multiple datarecords with values for this datafield, it means the
                //  "ultimate ancestor" allows multiple descendants of this datafield's datatype

                // e.g. Raman Spectra typically have multiple wavelengths (532nm, 785nm, etc)...if
                //  this entire process is called on the 532nm spectra, then the values for the 785nm
                //  spectra will also be in here.

                // In order for the FileRenamer plugin to work, however, there needs to be at most
                //  one value per datafield.  Fortunately, the "correct" record should be in the list
                //  of previously determined $intermediate_dr_ids, so we can discard the values that
                //  belong to datarecords not in that list
                foreach ($data as $dr_id => $value) {
                    if ( !isset($intermediate_dr_ids[$dr_id]) )
                        unset( $mapping[$df_id][$dr_id] );
                }

                // There shouldn't (?) be more than one datarecord left in $data at this point...
                //  it should either have one entry or be empty...
            }
        }

        // Need to run through the mapping array again...if any issues from the previous loop still
        //  persist, then the plugin can't create filenames from the data.  This could be a config
        //  issue, or could be missing data (field not filled out, or record not linked to)
        foreach ($mapping as $df_id => $data) {
            if ( empty($data) ) {
                // If no datarecord had a value for this datafield, then the filename will be missing
                //  a required part...since the data could legitimately be blank, provide the empty string
//                return "Unable to find a value for the \"".$datafield_names[$df_id]."\" datafield to use for renaming purposes...most likely, the field has no value or an expected record hasn't been linked to.";
                $mapping[$df_id] = array(0 => '');

                // This typically happens when the field hasn't been filled out, or when the config
                //  tries to pull a value from a linked datarecord...that hasn't actually been linked
            }
            else if ( count($data) > 1 ) {
                // If there are still multiple records with values for this datafield, then is a
                //  problem with the plugin config...most likely, it's been told to try to find a
                //  value in a linked descendant that permits more than one datarecord to link to it
                return "Found multiple values for the \"".$datafield_names[$df_id]."\" datafield...unable to determine which value to use for renaming the field.  Most likely, the plugin shouldn't be used on this field.";
            }
        }

        // Since there's not guaranteed to only be one value per datafield, the $mapping array can
        //  get flattened...
        $values = array();
        foreach ($fields as $display_order => $key) {
            // Determine whether the config wants a field value or a string constant in this spot...
            $value = null;
            $df_id = array_search($key, $uuid_mapping);

            if ( $df_id !== false ) {
                // ...if it's supposed to be a field value, then the previously determined mapping
                //  should have the value
                foreach ($mapping[$df_id] as $dr_id => $data)
                    $value = $data;
            }
            else {
                // ...if it's supposed to be a string constant, then just directly use that
                $value = $key;
            }

            // Store the correct value to use for the new filename
            $values[$display_order] = $value;
        }

        // The base of the new filename is found by imploding all the various pieces together...
        $separator = $config_info['separator'];
        $base_filename = implode($separator, $values);
        // ...and then replacing any period characters with the substitute sequence
        $base_filename = str_replace(".", $config_info['period_substitute'], $base_filename);

        // ----------------------------------------
        // NOTE: this is mostly a duplicate of code in EntityMetaModifyService::updateFileMeta()
        // ...unlike that location, which is more of a "last resort" to prevent invalid filenames,
        //  this plugin will skip attempting to save anything it thinks is invalid

        // Need to try to prevent illegal characters in the filenames...
        $regex = '/[\x5c\/\:\*\?\"\<\>\|\x7f]|[\x00-\x1f]/';
        if ( $config_info['delete_invalid_characters'] === 'yes' ) {
            // Delete these invalid characters by default
            $base_filename = preg_replace($regex, '', $base_filename);
        }
        else {
            // If the user doesn't want any invalid characters deleted...
            if ( preg_match($regex, $base_filename) === 1 )
                // ...then refuse to continue executing the plugin when the filename has them
                return array();
        }

        // ...also try to prevent leading/trailing spaces in the filename...
        $base_filename = trim($base_filename);
        // ...and other various illegal names in both linux and windows...
        $regex = '/^(\.|\.\.|CON|PRN|AUX|NUL|COM1|COM2|COM3|COM4|COM5|COM6|COM7|COM8|COM9|LPT1|LPT2|LPT3|LPT4|LPT5|LPT6|LPT7|LPT8|LPT9)$/i';
        if ( preg_match($regex, $base_filename) === 1 )
            return array();
        // ...and filenames starting with a dash are also bad
        if ( strpos($base_filename, '-') === 0 )
            return array();


        // ----------------------------------------
        // Now that we've got part of the filename, we need to update every one of the files/images
        //  uploaded to this drf...
        $new_filenames = array();

        foreach ($entities as $entity) {
            /** @var File|Image $entity */
            $id = $entity->getId();
            $uuid = $entity->getUniqueId();

            // ...so that the filename for this file/image entity can be determined
            $new_filenames[$id] = array('new_filename' => $base_filename);

            // Append the file/image's uuid if requested
            if ( $config_info['append_file_uuid'] === 'yes' )
                $new_filenames[$id]['new_filename'] .= $separator.$uuid;

            // Change the file's extension if requested
            if ( $config_info['file_extension'] === 'auto' ) {
                $new_filenames[$id]['new_filename'] .= '.'.$entity->getExt();
            }
            else {
                $new_filenames[$id]['new_filename'] .= '.'.$config_info['file_extension'];
                $new_filenames[$id]['new_ext'] = $config_info['file_extension'];
            }

            // Actually saving the new names is done by whatever called this function
        }

        return $new_filenames;
    }


    /**
     * Attempts to rename a file/image before it actually gets encrypted by the other parts of ODR.
     *
     * @param FilePreEncryptEvent $event
     *
     * @throws \Exception
     */
    public function onFilePreEncrypt(FilePreEncryptEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $is_event_relevant = false;
        $entity = null;
        $typeclass = null;

        try {
            // Get entities related to the file
            /** @var File|Image $entity */
            $entity = $event->getFile();
            $drf = $entity->getDataRecordFields();
            $datafield = $drf->getDataField();

            $typeclass = $datafield->getFieldType()->getTypeClass();

            // Only care about a file that get uploaded to a field using this plugin...
            $is_event_relevant = self::isEventRelevant($datafield, true);
            if ( $is_event_relevant ) {
                // This file was uploaded to the correct field, so it now needs to be processed
                $this->logger->debug('Want to rename '.$typeclass.' '.$entity->getId().' "'.$entity->getOriginalFileName().'"...', array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

                // ----------------------------------------
                // Since the file/image hasn't been encrypted yet, it's currently in something of an
                //  odd state...getLocalFileName() doesn't return the entire path to the file, but
                //  just the directory the file/image is in instead
                $local_filepath = $entity->getLocalFileName().'/'.$entity->getOriginalFileName();

                // In order to not wait some unknown amount of time for the file/image to finish
                //  encrypting, it needs to be renamed before the encryption process...
                $ret = self::getNewFilenames($drf);
                if ( is_array($ret) ) {
                    // ...and after we find the correct name for the newly uploaded file/image...
                    if ( !isset($ret[$entity->getId()]) )
                        throw new ODRException('onFilePreEncrypt() unable to find new filename for '.$typeclass.' '.$entity->getId(), 0x08fc0ad3);
                    $data = $ret[$entity->getId()];
                    $new_filename = $data['new_filename'];

                    if ( strlen($new_filename) <= 255 ) {
                        $this->logger->debug('...renaming '.$typeclass.' '.$entity->getId().' to "'.$new_filename.'"...', array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

                        // ...save the new filename in the database...
                        $meta_entry = null;
                        if ($typeclass === 'File')
                            $meta_entry = $entity->getFileMeta();
                        else
                            $meta_entry = $entity->getImageMeta();

                        $meta_entry->setOriginalFileName($new_filename);
                        $this->em->persist($meta_entry);

                        // If the plugin is enforcing a particular file extension...
                        if ( isset($data['new_ext']) ) {
                            // ...then need to also set a value in the File/Image entity itself
                            $new_ext = $data['new_ext'];

                            $entity->setExt($new_ext);
                            $this->em->persist($entity);
                        }

                        // ...and move the file on the server so its location matches the database
                        rename($local_filepath, $entity->getLocalFileName().'/'.$new_filename);

                        // In theory, the encryption process should continue on as if the user uploaded
                        //  the file/image with the "correct" name in the first place

                        // Now that the file/image is named correctly, flush the change
                        $this->em->flush();
                    }
                    else {
                        $this->logger->debug('-- (ERROR) unable to save new filename "'.$new_filename.'" for '.$typeclass.' '.$entity->getId().' because it exceeds 255 characters', array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));
                    }
                }
                else {
                    // ...if getNewFilenames() returns null, then there's some unrecoverable problem
                    //  that prevents the file/image from being renamed
                    $this->logger->debug('-- (ERROR) unable to rename '.$typeclass.' '.$entity->getId().'...', array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

                    // Regardless of the reason why there's a problem, this plugin can't fix it
                    // As such, nothing should be done
                    throw new \Exception($ret);
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $is_event_relevant )
                $this->logger->debug('finished rename attempt for '.$typeclass.' '.$entity->getId(), array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

            // Don't need to clear any caches here, since the file encryption should handle it
        }
    }


    /**
     * Does the work of renaming files/images as part of a MassEdit event
     *
     * @param MassEditTriggerEvent $event
     *
     * @throws \Exception
     */
    public function onMassEditTrigger(MassEditTriggerEvent $event)
    {
        // Need these variables defined out here so that the catch block can use them in case
        //  of an error
        $is_event_relevant = false;
        $typeclass = null;

        try {
            // Get entities related to the event
            $drf = $event->getDataRecordFields();
            $datafield = $drf->getDataField();
            $datarecord = $drf->getDataRecord();
            $user = $event->getUser();

            // Only care about a file that get changed in a field using this plugin...
            $is_event_relevant = self::isEventRelevant($datafield, false);
            if ( $is_event_relevant ) {
                // Load all files/images uploaded to this field
                $typeclass = $datafield->getFieldType()->getTypeClass();

                $tmp = null;
                if ( $typeclass === 'File' ) {
                    $query = $this->em->createQuery(
                       'SELECT f
                        FROM ODRAdminBundle:File f
                        WHERE f.dataRecordFields = :drf
                        AND f.deletedAt IS NULL'
                    )->setParameters( array('drf' => $drf->getId()) );
                    $tmp = $query->getResult();
                }
                else {
                    $query = $this->em->createQuery(
                       'SELECT i
                        FROM ODRAdminBundle:Image i
                        WHERE i.dataRecordFields = :drf AND i.original = 1
                        AND i.deletedAt IS NULL'
                    )->setParameters( array('drf' => $drf->getId()) );
                    $tmp = $query->getResult();
                }

                // There could be nothing uploaded to the field, or there could be multiple files/images
                /** @var File[]|Image[] $tmp */
                $entities = array();
                foreach ($tmp as $num => $entity)
                    $entities[ $entity->getId() ] = $entity;
                /** @var File[]|Image[] $entities */

                // This file was uploaded to the correct field, so it now needs to be processed
                $this->logger->debug('Want to rename the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId().'...', array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));


                // ----------------------------------------
                // Like the FilePreEncrypt Event, need to figure out the relevant information to be
                //  able to rename the files/images...
                $ret = self::getNewFilenames($drf);
                if ( is_array($ret) ) {
                    foreach ($ret as $entity_id => $data) {
                        $new_filename = $data['new_filename'];

                        if ( strlen($new_filename) <= 255 ) {
                            // ...so for each file/image uploaded to the datafield...
                            /** @var File|Image $entity */
                            $entity = $entities[$entity_id];
                            $this->logger->debug('...renaming '.$typeclass.' '.$entity->getId().' to "'.$new_filename.'"...', array(self::class, 'onMassEditTrigger()', $typeclass.' '.$entity->getId()));

                            // ...save the new filename in the database...
                            $props = array('original_filename' => $new_filename);
                            if ($typeclass === 'File')
                                $this->entity_modify_service->updateFileMeta($user, $entity, $props, true);
                            else
                                $this->entity_modify_service->updateImageMeta($user, $entity, $props, true);

                            // If the plugin is enforcing a particular file extension...
                            if ( isset($data['new_ext']) ) {
                                // ...then need to also set a value in the File/Image entity itself
                                $new_ext = $data['new_ext'];

                                $entity->setExt($new_ext);
                                $this->em->persist($entity);
                            }
                        }
                        else {
                            $this->logger->debug('-- (ERROR) unable to save new filename "'.$new_filename.'" for '.$typeclass.' '.$entity->getId().' because it exceeds 255 characters', array(self::class, 'onMassEditTrigger()', $typeclass.' '.$entity->getId()));
                        }
                    }

                    // Now that the files/images are named correctly, flush the changes
                    $this->em->flush();
                }
                else {
                    // ...if getNewFilenames() returns null, then there's some unrecoverable problem
                    //  that prevents the file from being renamed
                    $this->logger->debug('-- (ERROR) unable to rename the '.$typeclass.'s...', array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));

                    // Regardless of the reason why there's a problem, this plugin can't fix it
                    // As such, nothing should be done
                    throw new \Exception($ret);
                }
            }
        }
        catch (\Exception $e) {
            // Can't really display the error to the user yet, but can log it...
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));

            // DO NOT want to rethrow the error here...if this subscriber "exits with error", then
            //  any additional subscribers won't run either
        }
        finally {
            // Would prefer if these happened regardless of success/failure...
            if ( $is_event_relevant )
                $this->logger->debug('finished rename attempt for the '.$typeclass.'s in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));

            // Don't need to clear caches here, since the mass update process will always do it
        }
    }


    /**
     * Returns an array of HTML strings for each RenderPluginOption in this RenderPlugin that needs
     * to use custom HTML in the RenderPlugin settings dialog.
     *
     * @param ODRUser $user                                     The user opening the dialog
     * @param boolean $is_datatype_admin                        Whether the user is able to make changes to this RenderPlugin's config
     * @param RenderPlugin $render_plugin                       The RenderPlugin in question
     * @param DataType $datatype                                The relevant datatype if this is a Datatype Plugin, otherwise the Datatype of the given Datafield
     * @param DataFields|null $datafield                        Will be null unless this is a Datafield Plugin
     * @param RenderPluginInstance|null $render_plugin_instance Will be null if the RenderPlugin isn't in use
     * @return string[]
     */
    public function getRenderPluginOptionsOverride($user, $is_datatype_admin, $render_plugin, $datatype, $datafield = null, $render_plugin_instance = null)
    {
        $custom_rpo_html = array();
        foreach ($render_plugin->getRenderPluginOptionsDef() as $rpo) {
            // This plugin currently has several options, but only "field_list" needs to use a
            //  custom render for the dialog...
            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                // This is the "field_list" option...for this plugin, we need to figure out which
                //  fields are available first...
                $available_configurations = self::getAvailableConfigurations($datafield);
                $available_prefixes = $available_configurations['prefixes'];
                $available_fields = $available_configurations['name_data'];
                $allowed_datatypes = $available_configurations['allowed_datatypes'];

                // ...and also need to figure out what the current config is if possible...
                $current_plugin_config = self::getCurrentPluginConfig($datafield);
                $current_prefix = '';
                if ( isset($current_plugin_config['prefix']) )
                    $current_prefix = $current_plugin_config['prefix'];
                $current_config = '';
                if ( isset($current_plugin_config['config']) )
                    $current_config = array_flip($current_plugin_config['config']);

                // ...to create a mapping from "df_uuid" => "df_name"...
                $uuid_mapping = array();
                foreach ($available_fields as $dt_id => $dt_data) {
                    foreach ($dt_data['fields'] as $df_id => $df_data) {
                        $df_uuid = $df_data['uuid'];
                        if ( isset($current_config[$df_uuid]) )
                            $uuid_mapping[$df_uuid] = $df_data['name'];
                    }
                }

                // ...which allows a template to be rendered
                $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Base:FileRenamer/plugin_settings_dialog_field_list_override.html.twig',
                    array(
                        'rpo_id' => $rpo->getId(),

                        'available_prefixes' => $available_prefixes,
                        'available_fields' => $available_fields,
                        'current_prefix' => $current_prefix,
                        'current_config' => $current_config,

                        'allowed_datatypes' => $allowed_datatypes,

                        'uuid_mapping' => $uuid_mapping,
                    )
                );
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever
        return $custom_rpo_html;
    }


    /**
     * Returns an array of datafields where MassEdit should enable the abiilty to run a background
     * job without actually changing their values.
     *
     * @param array $render_plugin_instance
     * @return array An array where the values are datafield ids
     */
    public function getMassEditOverrideFields($render_plugin_instance)
    {
        if ( !isset($render_plugin_instance['renderPluginMap']) )
            throw new ODRException('Invalid plugin config');

        // Since this is a datafield plugin, there will only be the one datafield...want it to
        //  always display this option
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            return array(
                $rpf['id']
            );
        }

        return array();
    }


    /**
     * The MassEdit system generates a checkbox for each RenderPlugin that returns something from
     * self::getMassEditOverrideFields()...if the user selects the checkbox, then certain RenderPlugins
     * may not want to activate if the user has also entered a value in the relevant field.
     *
     * For each datafield affected by this RenderPlugin, this function returns true if the plugin
     * should always be activated, or false if it should only be activated when the user didn't
     * also enter a value into the field.
     *
     * @param array $render_plugin_instance
     * @return array
     */
    public function getMassEditTriggerFields($render_plugin_instance)
    {
        // This datafield plugin does not care whether the user entered a value or not...the plugin's
        //  activation is independent of the user changing public status of the file/image field
        $trigger_fields = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            // Since this is a datafield plugin, it only has one entry in renderPluginMap
            $trigger_fields[ $rpf['id'] ] = true;
        }

        return $trigger_fields;
    }
}
