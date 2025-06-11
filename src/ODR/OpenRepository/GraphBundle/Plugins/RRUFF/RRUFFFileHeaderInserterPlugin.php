<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF File Header Inserter Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The same rant for the default FileHeaderInserter plugin applies here too.
 * {@link \ODR\OpenRepository\GraphBundle\Plugins\Base\FileHeaderInserterPlugin}
 *
 * The reason there's two of them is because making the default plugin work for RRUFF was untenable.
 * The headers for RRUFF's spectra files require conditional logic based on whether something exists
 * (i.e. no powder diffraction means no line for cell parameters), and several of the lines require
 * transformation into a more readable format (i.e. measured chemistry + chemistry notes and
 * oriented raman data)
 *
 * In the long run, it's just easier to have a different plugin with a different config to gather
 * the information to deal with it.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\RRUFF;

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
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityDeletionService;
use ODR\AdminBundle\Component\Service\ODRUploadService;
use ODR\OpenRepository\GraphBundle\Plugins\Base\FileHeaderInserterPlugin;
use ODR\OpenRepository\GraphBundle\Plugins\DatafieldPluginInterface;
use ODR\OpenRepository\GraphBundle\Plugins\PluginSettingsDialogOverrideInterface;
use ODR\OpenRepository\GraphBundle\Plugins\MassEditTriggerEventInterface;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class RRUFFFileHeaderInserterPlugin implements DatafieldPluginInterface, PluginSettingsDialogOverrideInterface, MassEditTriggerEventInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CryptoService
     */
    private $crypto_service;

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
     * @var EntityDeletionService
     */
    private $entity_deletion_service;

    /**
     * @var ODRUploadService
     */
    private $upload_service;

    /**
     * @var string
     */
    private $odr_tmp_directory;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * RRUFF File Header Inserter Plugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param CryptoService $crypto_service
     * @param DatabaseInfoService $database_info_service
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param EntityDeletionService $entity_deletion_service
     * @param ODRUploadService $upload_service
     * @param string $odr_tmp_directory
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CryptoService $crypto_service,
        DatabaseInfoService $database_info_service,
        DatarecordInfoService $datarecord_info_service,
        DatatreeInfoService $datatree_info_service,
        EntityDeletionService $entity_deletion_service,
        ODRUploadService $upload_service,
        string $odr_tmp_directory,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->crypto_service = $crypto_service;
        $this->database_info_service = $database_info_service;
        $this->datarecord_info_service = $datarecord_info_service;
        $this->datatree_info_service = $datatree_info_service;
        $this->entity_deletion_service = $entity_deletion_service;
        $this->upload_service = $upload_service;
        $this->odr_tmp_directory = $odr_tmp_directory;
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

            // The RRUFF FileHeaderInserter Plugin should work in the 'edit' context
            if ( $context === 'edit' )
                return true;

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

            // This plugin needs to cooperate with the FileRenamerPlugin, and the easiest way to do
            //  it is to have one of the plugins handle both
            $uses_file_renamer_plugin = false;
            foreach ($datafield['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_renamer' ) {
                    $uses_file_renamer_plugin = true;
//                    break;
                }

                // Due to both this plugin and the FileHeaderInserterPlugin sharing a render value,
                //  the RenderPlugin system doesn't stop them from both being active at the same
                //  time...because I'm not inclined to overhaul the plugin system at this time,
                //  I'm going to throw an exception here
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter' ) {
                    $datafield_name = $datafield['dataFieldMeta']['fieldName'];
                    throw new ODRException('The datafield "'.$datafield_name.'" should not have both the Base FileHeaderInserter and the RRUFF FileHeaderInserter plugin active simultaneously');
                }
            }

            $output = "";
            if ( $rendering_options['context'] === 'display' ) {
                // TODO - also work in the 'display' context?  but finding errors to display is expensive...

//                $output = $this->templating->render(
//                    'ODROpenRepositoryGraphBundle:RRUFF:FileRenamer/display_file_datafield.html.twig',
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
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFFileHeaderInserter/file_header_inserter_edit_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'uses_file_renamer_plugin' => $uses_file_renamer_plugin,
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
     *
     * @return bool
     */
    private function isEventRelevant($datafield)
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

        $is_correct_plugin = false;
        foreach ($df['renderPluginInstances'] as $rpi_id => $rpi) {
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.file_header_inserter' ) {
                // Datafield is using the correct plugin...
                $is_correct_plugin = true;
            }

            // Due to both this plugin and the FileHeaderInserterPlugin sharing a render value, the
            //  RenderPlugin system doesn't stop them from both being active at the same time...
            //  ...because I'm not inclined to overhaul the plugin system at this time, I'm going to
            //  throw an exception here
            if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.base.file_header_inserter' ) {
                $datafield_name = $datafield->getFieldName();
                throw new ODRException('The datafield "'.$datafield_name.'" should not have both the Base FileHeaderInserter and the RRUFF FileHeaderInserter plugin active simultaneously');
            }
        }

        if ($is_correct_plugin)
            return true;

        // Otherwise, the event needs to be ignored
        return false;
    }


    /**
     * Locates the configuration for the plugin if it exists, and converts it into a more useful
     * array format for actual use.  For instance...
     * <code>
     * array(
     *     'prefix' => '3_44_47',
     *     'comment_prefix' => '##',
     *     'allowed_extensions' => array('txt'),
     *     'newline_separator' => "\r\n",
     *     'field_list' => array('mineral_name' => 17,...),
     *      ...
     * );
     * </code>
     *
     * The 'prefix' entry is a string of datatype ids which indicate the "ultimate ancestor" of a
     * record that is about to have one of its uploaded files modified.  It also assists with actually
     * finding the correct values to use in the file header later on...it will always have at least
     * one datatype id in it.
     *
     * The 'comment_prefix' entry is what's prepended to each line of the 'header' entry to indicate
     * those are different from 'data' lines...the 'placeholder' entry indicates where the values
     * should be inserted in the 'header' entry, and the 'fields' array is a shortcut to the
     * datafield ids listed in the header.
     *
     * The 'newline_separator' is going to be either "\r\n" or "\n", and determines what the plugin
     * actually uses for the newlines when writing the header to a file.
     *
     * The 'allowed_extensions' is an array of strings for which files the plugin is allowed to run
     * on.  This is an attempt to stop the plugin from wrecking files which aren't plain-text...
     * such as PDF or JPEG files...but there's nothing stopping the user from doing so if they really
     * want to.
     *
     * The main difference between the base FileHeaderInserterPlugin and this one is that the user
     * can't arbitrarily create their own header, but instead maps fields to pre-defined roles.
     *
     * @param DataFields $datafield
     * @return array
     */
    private function getCurrentPluginConfig($datafield)
    {
        // Going to try to create an array of datafield uuids and string constants...
        $config = array();

        // Events don't have access to the renderPluginInstance, so might as well just always get
        //  the data from the cached datatype array
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
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.file_header_inserter' ) {
                    // Need to get all the regular configuration options for the plugin
                    $comment_prefix = '##';
                    $allowed_extensions = 'txt';
                    $newline_separator = 'windows';
                    $replace_newlines_in_fields = true;

                    $baseurl = '';
                    if ( isset($rpi['renderPluginOptionsMap']['baseurl']) )
                        $baseurl = trim($rpi['renderPluginOptionsMap']['baseurl']);

                    $context = '';
                    if ( isset($rpi['renderPluginOptionsMap']['context']) )
                        $context = trim($rpi['renderPluginOptionsMap']['context']);

                    $replace_existing_files = true;
                    if ( isset($rpi['renderPluginOptionsMap']['replace_existing_file'])
                        && trim($rpi['renderPluginOptionsMap']['replace_existing_file']) === 'no'
                    ) {
                        $replace_existing_files = false;
                    }

                    // The newline separator is easier for the plugin if it's the actual character
                    //  sequence
                    if ( $newline_separator === 'windows' )
                        $newline_separator = "\r\n";
                    else //if ( $newline_separator === 'linux' )
                        $newline_separator = "\n";

                    // The extensions probably should be converted into an array
                    $allowed_extensions = explode(',', $allowed_extensions);


                    // ----------------------------------------
                    // Need to also get the semi-encoded option containing the header data
                    $header_data = trim($rpi['renderPluginOptionsMap']['header_data']);

                    // Unlike the base version, the user doesn't define what the header looks like
                    // It instead demands mappings for two dozen plus fields, which are stored in a
                    //  comma-separated list
                    $header_data = explode(',', $header_data);

                    $prefix = '';
                    $fields_by_name = array();
                    foreach ($header_data as $num => $line) {
                        $data = explode('=', $line);
                        $key = trim($data[0]);
                        $value = trim($data[1]);

                        if ( $data[0] === 'prefix' ) {
                            $prefix = $value;
                        }
                        else {
                            $value = intval($value);
                            $fields_by_name[$key] = $value;
                            // Doing it this way in case there are undefined fields
                        }
                    }

                    // Require the datatype prefix to exist and all fields to be mapped for the config
                    //  to be valid
                    $invalid = false;
                    if ( $baseurl === '' || $context === '' )
                        $invalid = true;
                    if ( $prefix === '' || $prefix === 'undefined' )
                        $invalid = true;
                    if ( count($fields_by_name) !== 28 )
                        $invalid = true;

                    $config = array(
                        'invalid' => $invalid,

                        'baseurl' => $baseurl,
                        'context' => $context,

                        'prefix' => $prefix,
                        'comment_prefix' => $comment_prefix,
                        'allowed_extensions' => $allowed_extensions,
                        'newline_separator' => $newline_separator,
                        'replace_newlines_in_fields' => $replace_newlines_in_fields,
                        'replace_existing_files' => $replace_existing_files,

                        'fields_by_name' => $fields_by_name,
                    );
                }
            }
        }

        // Otherwise, attempt to return the plugin's config
        return $config;
    }


    /**
     * This works identically to {@link FileHeaderInserterPlugin::getAvailableConfigurations()}.
     *
     * The user still needs a list of fields to map to the correct roles, even if there's technically
     * only one "correct config" at the end of the day.
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
        //  of their descendants.  This entire setup is somewhat overkill, but I'm keeping it as
        //  close to the FileRenamer Plugin as possible here.

        // This does mean that the rest of the plugin is going to potentially have to choose between
        //  multiple values to use for the header.

        $datatypes_to_check = array_keys($all_valid_datatypes);
        while ( !empty($datatypes_to_check) ) {
            $tmp = array();
            foreach ($datatypes_to_check as $num => $ancestor_dt_id) {
                if ( isset($by_ancestors[$ancestor_dt_id]) ) {
                    foreach ($by_ancestors[$ancestor_dt_id] as $descendant_id => $multiple_allowed) {
//                        if ( !$multiple_allowed ) {    // NOTE: commented out due to wanting all descendants
                            // This ancestor datatype has a descendant that only allows a single
                            //  child/linked record, so it's considered valid...
                            $all_valid_datatypes[$descendant_id] = 1;
                            // ...and need to also find all descendants of this descendant datatype
                            $tmp[] = $descendant_id;
//                        }
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
                case 'Boolean':
                case 'Integer':
                case 'Decimal':
                case 'Short Text':
                case 'Medium Text':
                case 'Long Text':
                case 'Paragraph Text':
                case 'DateTime':
                case 'Single Radio':
                case 'Single Select':
                    $valid_fieldtypes[] = $ft->getId();
                    break;

                // These fields are impossible to convert into a filename
//                case 'File':
//                case 'Image':
//                case 'Markdown':
//                case 'Multiple Radio':
//                case 'Multiple Select':
//                case 'Tags':
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
           'SELECT dt.id AS dt_id, df.id AS df_id, dfm.fieldName
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
            $df_name = $result['fieldName'];

//            $name_data[$dt_id]['fields'][$df_id] = array('uuid' => $df_uuid, 'name' => $df_name);
            $name_data[$dt_id]['fields'][$df_id] = $df_name;
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
//                            if ( !$multiple_allowed ) {    // NOTE: commented out due to wanting all descendants
                                // This ancestor datatype has a descendant that only allows a single
                                //  child/linked record, so it's considered valid...
//                                $descendants_by_prefix[$descendant_id] = 1;
                                // ...and need to also find all descendants of this descendant datatype
                                $tmp[] = $descendant_id;
//                            }
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
        // Need to have the names of the fields
        $field_names = array(
            'mineral_name' => 'Mineral Name',
            'rruff_id' => 'RRUFF ID',
            'ima_formula' => 'IMA Formula',
            'sample_loc' => 'Sample Location',
            'sample_owner' => 'Sample Owner',
            'sample_source' => 'Sample Source',
            'sample_desc' => 'Sample Description',
            'sample_status' => 'Sample Status',

            'probe_formula' => 'Measured Chemistry',
            'probe_notes' => 'Measured Chemistry Notes',

            'pin_id' => 'Pin ID',
            'pin_parallel_x' => 'Vector Parallel X',
            'pin_parallel_y' => 'Vector Parallel Y',
            'pin_parallel_z' => 'Vector Parallel Z',
            'pin_parallel_ref' => 'Vector Parallel Ref Space',
            'pin_perp_x' => 'Vector Perpendicular X',
            'pin_perp_y' => 'Vector Perpendicular Y',
            'pin_perp_z' => 'Vector Perpendicular Z',
            'pin_perp_ref' => 'Vector Perpendicular Ref Space',

            'powder_desc' => 'Powder Description',
            'powder_a' => 'a',
            'powder_b' => 'b',
            'powder_c' => 'c',
            'powder_alpha' => 'alpha',
            'powder_beta' => 'beta',
            'powder_gamma' => 'gamma',
            'powder_vol' => 'volume',
            'powder_crys' => 'crystal system',
        );

        return array(
            'prefixes' => $prefixes,
            'name_data' => $name_data,
            'allowed_datatypes' => $descendants_by_prefix,
            'field_names' => $field_names,
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
     * Determines what the header should be for the files uploaded to this datafield, based on the
     * provided plugin config.
     *
     * This process consists of taking the datarecord that just had a file uploaded to it, then
     * finding its "ultimate ancestor" datarecord through a targeted database query...due to the
     * rules enforced by {@link self::getAvailableConfigurations()}, once this "ultimate ancestor"
     * record is loaded from the cache entries, it'll contain all the possible values the header
     * could use.
     *
     * The cached entry can then be iterated over with the rest of the config info to find the values
     * to use for the header...though if the plugin isn't configured correctly, or told to run on
     * databases it shouldn't, or records that are expected to exist don't actually exist...then the
     * plugin has to attempt to compensate for this...
     *
     * @param DataRecordFields $original_drf
     * @param array $config_info {@link self::getCurrentPluginConfig()}
     * @return string
     */
    public function getFileHeader($original_drf, $config_info)
    {
        // ----------------------------------------
        if ( is_null($original_drf) )
            throw new ODRBadRequestException('Unable to create a header for a null drf', 0x65554240);

        // If the plugin isn't properly configured, then don't attempt to do anything
        if ( !isset($config_info['invalid']) || $config_info['invalid'] == true )
            throw new ODRBadRequestException('The RRUFFFileHeaderInserter plugin is not properly configured', 0x65554240);

        $baseurl = $config_info['baseurl'];
        $context = $config_info['context'];
        $prefix = $config_info['prefix'];
        $comment_prefix = $config_info['comment_prefix'];
        $allowed_extensions = $config_info['allowed_extensions'];
        $newline_separator = $config_info['newline_separator'];
        $fields_by_name = $config_info['fields_by_name'];


        // ----------------------------------------
        // Going to need both the datafield and the datarecord belonging to this drf entry...
        $dr = $original_drf->getDataRecord();
        $df = $original_drf->getDataField();
        $typeclass = $df->getFieldType()->getTypeClass();
        if ( $typeclass !== 'File' )
            throw new ODRBadRequestException('Unable to create a header for a '.$typeclass.' field', 0x65554240);

        // Makes no sense to run all this stuff if the field has no files in it
        $entities = $original_drf->getFile()->toArray();
        if ( empty($entities) )
            throw new ODRBadRequestException('There are no files uploaded to this datafield for the RRUFFFileHeaderInserter plugin to run on', 0x65554240);
        /** @var File[] $entities */

        // Need to determine whether at least one of the files uploaded to the datafield has an
        //  extension that the plugin is allowed to work on...
        $has_valid_file = false;
        foreach ($entities as $f) {
            if ( in_array($f->getExt(), $allowed_extensions) ) {
                $has_valid_file = true;
                break;
            }
        }

        // ...if none of the files match, then don't attempt to do anything
        if ( !$has_valid_file )
            throw new ODRBadRequestException('None of the files uploaded to this datafield are valid for The RRUFFFileHeaderInserter plugin to operate on', 0x65554240);
        // Not strictly an exception, but makes it obvious...


        // ----------------------------------------
        // Need to find the "ultimate ancestor" of this datarecord, as far as the config for this
        //  render plugin is concerned at least.
        $ancestor_dr_id = null;
        // Also will be useful to get all the intermediate ids
        $intermediate_dr_ids = array();
        // The "prefix" string...an underscore-separated list of datatype ids...indicates how far
        //  to go looking for this "ultimate ancestor".
        $split_prefix = explode('_', $prefix);


        // If the "prefix" string only has a single datatype id in it, then we don't need to locate
        //  the "ultimate ancestor"...it's just the datarecord of the drf given
        if ( count($split_prefix) == 1 ) {
            // Note that this is desired behavior even if the record not top-level or linked to from
            //  somewhere...it just means that the values for the header only come from this record
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

            // Due to doctrine's querybuilder being a pain, I'm going to duplicate it's work instead
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

            // Need one more WHERE clause to tie the entire query to the datarecord the files
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
            //  been linked...), or it could return a lot of records (typically when the "prefix"
            //  includes too many ancestor datatypes)

            // Regardless of why there's a problem, it means that there's no way to get the correct
            //  values to use for a file header...so nothing should happen at all
            if ( count($results) < 1 )
                throw new ODRBadRequestException("Unable to find the correct ancestors for datarecord ".$dr->getId()."...most likely, one of the ancestors is supposed to be a linked record, but it hasn't been linked to.", 0x65554240);
            else if ( count($results) > 1 )
                throw new ODRBadRequestException("Multiple possible ancestors for datarecord ".$dr->getId()."...plugin is likely either configured wrong, or being used incorrectly.", 0x65554240);

            // It"ll be useful to save each of the datarecord ids found in the previous query...
            for ($i = 0; $i < $dr_num; $i++)
                $intermediate_dr_ids[$i] = intval($results[0]['dr_'.$i.'_id']);
            $ancestor_dr_id = $intermediate_dr_ids[0];

            // ...but after this point, they're only useful if they're flipped
            $intermediate_dr_ids = array_flip($intermediate_dr_ids);
        }

        // This variable shouldn't be null at this point, but make sure...if it is, then there's no
        //  way to determine what values to use for the header
        if ( is_null($ancestor_dr_id) )
            throw new ODRBadRequestException("Unable to find the correct ancestors for datarecord ".$dr->getId()."...no clue why.", 0x65554240);


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

        // While not quite as free-form as the regular FileHeaderInserter Plugin, the dialog for this
        //  plugin still has no way to know when the user selects datafields which are invalid. As
        //  such, it still needs to do validation. The fields can't...
        // 1) have an invalid typeclass (e.g. Image field)
        // 2) belong to a datatype that's unrelated to the prefix
        // 3) are non-public

        // That means it's up to this function to actually do the verification.  The first issue
        //  is simple enough to check...
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        $valid_fieldtypes = array();
        foreach ($fieldtypes as $ft) {
            switch ($ft->getTypeName()) {
                // These fieldtypes can be converted into a string
                case 'Boolean':
                case 'Integer':
                case 'Decimal':
                case 'Short Text':
                case 'Medium Text':
                case 'Long Text':
                case 'Paragraph Text':
                case 'DateTime':
                case 'Single Radio':
                case 'Single Select':
                    $valid_fieldtypes[] = $ft->getId();
                    break;

                // These fields are impossible to convert into a filename
//                case 'File':
//                case 'Image':
//                case 'Markdown':
//                case 'Multiple Radio':
//                case 'Multiple Select':
//                case 'Tags':
                default:
                    /* do nothing */
            }
        }

        // The second can be dealt with by extracting the datatype ids for all datarecords in the
        //  cached data
        // NOTE: this isn't guaranteed to get all related datatypes...if a datarecord doesn't have
        //  a child/linked descendant of a particular datatype, then this array won't have the datatype
        //  id...but that also means that there's no possible value for datafields of that datatype,
        //  so it ends up meaning the same thing in the long run
        $valid_datatypes = array();
        foreach ($dr_array as $dr_id => $dr) {
            $dt_id = $dr['dataType']['id'];
            $valid_datatypes[$dt_id] = 1;
        }
        $valid_datatypes = array_keys($valid_datatypes);

        // Verifying all of these requirements is easier to do with the query below...bonus points
        //  for keeping things looking similar to how the FileRenamer plugin works
        $query = $this->em->createQuery(
           'SELECT df.id, dfm.fieldName
            FROM ODRAdminBundle:DataFields df
            LEFT JOIN ODRAdminBundle:DataFieldsMeta dfm WITH dfm.dataField = df
            WHERE df.dataType IN (:datatype_ids) AND df.id IN (:datafield_ids)
            AND dfm.fieldType IN (:fieldtype_ids) AND dfm.publicDate != :public_date
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
        )->setParameters(
            array(
                'datatype_ids' => $valid_datatypes,
                'datafield_ids' => array_values($fields_by_name),
                'fieldtype_ids' => $valid_fieldtypes,
                'public_date' => '2200-01-01 00:00:00',
            )
        );
        $results = $query->getArrayResult();

        $datafield_names = array();
        foreach ($results as $result) {
            $id = $result['id'];
            $field_name = $result['fieldName'];

            $datafield_names[$id] = $field_name;
        }

        // NOTE: the header might technically be empty, though it really shouldn't be


        // ----------------------------------------
        // Unfortunately, there doesn't seem to be a better method to actually get the values than to
        //  iterate over every single datafield listed in the plugin's config...if the database was
        //  queried here, it would either have to be done with a massive really slow query like the
        //  one in DatarecordInfoService, or a potentially large number of simple queries...

        $original_mapping = array();
        foreach ($datafield_names as $df_id => $df_name) {
            // In theory, the header is supposed to receive a single value from the datarecords that
            //  are related via the config_prefix.  However, since this plugin intentionally allows
            //  multiple descendant records, it is very likely that there will be multiple values
            //  for each datafield
            $original_mapping[$df_id] = array();

            // ...so we need to iterate over all records in the cached array and hope we find the
            //  correct/desired value
            foreach ($dr_array as $dr_id => $dr) {
                if ( isset($dr['dataRecordFields'][$df_id]) ) {
                    $drf = $dr['dataRecordFields'][$df_id];

                    $typeclasses = array(
                        'boolean', 'integerValue', 'decimalValue', 'shortVarchar', 'mediumVarchar',
                        'longVarchar', 'longText', 'datetimeValue', 'radioSelection'
                    );
                    foreach ($typeclasses as $typeclass) {
                        if ( isset($drf[$typeclass]) ) {
                            if ( $typeclass !== 'radioSelection' ) {
                                $original_mapping[$df_id][$dr_id] = $drf[$typeclass][0]['value'];
                            }
                            else {
                                // Because of the config, this shouldn't be a multiple radio/select...
                                foreach ($drf['radioSelection'] as $ro_id => $ro)
                                    $original_mapping[$df_id][$dr_id] = $ro['radioOption']['optionName'];
                            }
                        }
                    }
                }
            }
        }

        // At this point, $original_mapping could have any number of values for each datafield.
        // While ideally there would be zero or one value per datafield, $original_mapping could
        //  easily have more than one value per datafields...this is caused by allowing multiple
        //  descendants.

        // e.g. Raman Spectra typically have multiple wavelengths (532nm, 785nm, etc)...if this
        //  entire process is called on the 532nm spectra, then the values for the 785nm spectra will
        //  also be in here as a sibling record of sorts.
        // These sibling records can be filtered out using $intermediate_dr_ids.
        $filtered_mapping = array();
        foreach ($original_mapping as $df_id => $data) {
            foreach ($data as $dr_id => $value) {
                // Only want to keep values from datarecords in $intermediate_dr_ids for this
                //  particular array
                if ( isset($intermediate_dr_ids[$dr_id]) ) {
                    if ( !isset($filtered_mapping[$df_id]) )
                        $filtered_mapping[$df_id] = array();
                    $filtered_mapping[$df_id][$dr_id] = $value;
                }
            }
        }
        // Note that this filtered mapping will not contain values for single-allowed datatypes which
        //  are descended from other datatypes in the prefix... e.g. RRUFF Sample is allowed to link
        //  to a single IMA Mineral, so there will only be at most one mineral name...but it won't
        //  be in $filtered_mapping, because IMA Mineral isn't an ancestor of RRUFF Spectra.

        // The reason for this logical split is because the RRUFFFileHeaderInserter plugin is forced to
        //  also permit descendants of multiple-allowed datatypes...e.g. the user wants to use values
        //  from references that the RRUFF Sample links to as part of the header for a Raman Spectra
        //  file.  The only solution in this case is to pick one, or to merge all of them together.

        // ...and while the above is completely true in general, it's still undesireable behavior
        //  when the plugin is dealing with raman data...due to permitting descendants of multiple-
        //  allowed datatypes, $original_mapping will contain all of the oriented pins, and the loop
        //  that builds $header_values will just pick the first one (which isn't necessarily correct)

        // The fix is to do an extra step...one that requires specific knowledge of RRUFF's structure
        //  ...and only keep the oriented pin data when it's more tightly related to the file being
        //  checked
        $pin_fields = array(
            $fields_by_name['pin_id'],
            $fields_by_name['pin_parallel_x'],
            $fields_by_name['pin_parallel_y'],
            $fields_by_name['pin_parallel_z'],
            $fields_by_name['pin_parallel_ref'],
            $fields_by_name['pin_perp_x'],
            $fields_by_name['pin_perp_y'],
            $fields_by_name['pin_perp_z'],
            $fields_by_name['pin_perp_ref'],
        );
        foreach ($pin_fields as $df_id) {
            if ( isset($original_mapping[$df_id]) ) {
                // This RRUFF sample could have multiple oriented raman sub-samples
                $dr_values = $original_mapping[$df_id];

                // Because we know the structure of this database, we know that the parent
                //  of the raman spectra record and the pin data record is the second entry
                //  in $intermediate_dr_ids
                $correct_parent_dr_id = array_search(1, $intermediate_dr_ids);

                // We can then use this id to unset the "unrelated" values inside the pin fields
                foreach ($dr_values as $dr_id => $val) {
                    if ( !isset($dr_array[$dr_id]) || $dr_array[$dr_id]['parent']['id'] !== $correct_parent_dr_id ) {
                        // Going to unset the entry in $original_mapping instead of inserting
                        //  the value into $filtered_mapping
                        unset( $original_mapping[$df_id][$dr_id] );
                    }
                }
            }
        }


        // ----------------------------------------
        // At this point we have all the possible values from datafields to use in this header,
        //  assuming that the plugin is correctly configured and the fields have values.

        // Because the RRUFFFileHeaderInserter plugin has a pre-defined structure, it's better to
        //  call a template file to do the work...but it's easier on twig if php figures out the
        //  values first...
        $header_values = array();
        foreach ($fields_by_name as $name => $df_id) {
            // Prefer to use values from the $filtered_mapping array first...
            if ( isset($filtered_mapping[$df_id]) && !empty($filtered_mapping[$df_id]) ) {
                foreach ($filtered_mapping[$df_id] as $dr_id => $value) {
                    // While there should only be one value in here, there's no way to know the
                    //  $dr_id beforehand
                    if ( $config_info['replace_newlines_in_fields'] )
                        $header_values[$name] = str_replace(array("\r", "\n"), " ", $value);
                    else
                        $header_values[$name] = $value;
                    break;
                }
            }
            else if ( isset($original_mapping[$df_id]) && !empty($original_mapping[$df_id]) ) {
                // ...but fall back to the $original_mapping array if a filtered value doesn't exist
                foreach ($original_mapping[$df_id] as $dr_id => $value) {
                    if ( $config_info['replace_newlines_in_fields'] )
                        $header_values[$name] = str_replace(array("\r", "\n"), " ", $value);
                    else
                        $header_values[$name] = $value;
                    // Might as well just use the first value, since there's no way to determine
                    //  which of the potential values is "more correct"
                    break;

                    // TODO - provide an option to change this behavior?  do nothing, pick first, pick last, merge together?  meh...
                }
            }
            else {
                // ...and use a blank value if neither have anything
                $header_values[$df_id] = '';
            }
        }

        // ...and easier still when php combines a couple of them together
        // Specifically, the oriented data needs to be converted from 8 fields into two entries
        self::determineOrientedVectors($header_values);

        // ...and the two microprobe fields need to be recombined into a single field
        if ( isset($header_values['probe_formula']) && !isset($header_values['probe_notes']) ) {
            $header_values['measured_chemistry'] = $header_values['probe_formula'];
        }
        else if ( !isset($header_values['probe_formula']) && isset($header_values['probe_notes']) ) {
            $header_values['measured_chemistry'] = $header_values['probe_notes'];
        }
        else if ( isset($header_values['probe_formula']) && isset($header_values['probe_notes']) ) {
            $header_values['measured_chemistry'] = $header_values['probe_formula'].' ; '.$header_values['probe_notes'];
        }
        // Regardless of which if statement executed, no longer need the two probe fields
        unset( $header_values['probe_formula'] );
        unset( $header_values['probe_notes'] );

        // Don't leave blanks around just in case
        foreach ($header_values as $field_name => $field_value) {
            if ( $field_value === '' )
                unset($header_values[$field_name] );
        }

        // Get twig to render the header
        $header_text = $this->templating->render(
            'ODROpenRepositoryGraphBundle:RRUFF:RRUFFFileHeaderInserter/file_header.txt.twig',
            array(
                'header_values' => $header_values,

                'baseurl' => $baseurl,
                'context' => $context,
                'comment_prefix' => $comment_prefix,
                'newline_separator' => $newline_separator,
            )
        );

        // Clean up any duplicated newlines and return
        $header_text = str_replace("\r\n\r\n", "\r\n", $header_text);
        $header_text = str_replace("\n\n", "\n", $header_text);
        return $header_text;
    }


    /**
     * Converts the eight separate oriented values into two strings.
     *
     * @param array $header_values
     */
    private function determineOrientedVectors(&$header_values)
    {
        if ( isset($header_values['pin_parallel_x'])
            && isset($header_values['pin_parallel_y'])
            && isset($header_values['pin_parallel_z'])
            && isset($header_values['pin_parallel_ref'])
        ) {
            $header_values['vector_p'] = self::getVectorStr(
                $header_values['pin_parallel_x'],
                $header_values['pin_parallel_y'],
                $header_values['pin_parallel_z'],
                $header_values['pin_parallel_ref']
            );
        }

        if ( isset($header_values['pin_perp_x'])
            && isset($header_values['pin_perp_y'])
            && isset($header_values['pin_perp_z'])
            && isset($header_values['pin_perp_ref'])
        ) {
            $header_values['vector_f'] = self::getVectorStr(
                $header_values['pin_perp_x'],
                $header_values['pin_perp_y'],
                $header_values['pin_perp_z'],
                $header_values['pin_perp_ref']
            );
        }

        // Remove any of the original vector data
        unset( $header_values['pin_parallel_x'] );
        unset( $header_values['pin_parallel_y'] );
        unset( $header_values['pin_parallel_z'] );
        unset( $header_values['pin_parallel_ref'] );
        unset( $header_values['pin_perp_x'] );
        unset( $header_values['pin_perp_y'] );
        unset( $header_values['pin_perp_z'] );
        unset( $header_values['pin_perp_ref'] );
    }


    /**
     * Converts the selected pin data into a single string.
     *
     * @param string $x
     * @param string $y
     * @param string $z
     * @param string $ref
     * @return string
     */
    private function getVectorStr($x, $y, $z, $ref)
    {
        $vector_str = $x.' '.$y.' '.$z;

        $dir = '';
        switch ($vector_str) {
            // Aligned to a single direction
            case '-1 0 0':
                $dir = '-a';
                break;
            case '1 0 0':
                $dir = 'a';
                break;
            case '0 -1 0':
                $dir = '-b';
                break;
            case '0 1 0':
                $dir = 'b';
                break;
            case '0 0 -1':
                $dir = '-c';
                break;
            case '0 0 1':
                $dir = 'c';
                break;

            // Aligned to more than one direction
            case '0 1 1':
            case '1 0 1':
            case '1 1 0':
            case '1 1 1':
                $dir = '';
                break;

            // Nothing else is allowed
            default:
                $vector_str = '';
                break;
        }

        if ( $vector_str === '' ) {
            // Not a valid alignment
            return $vector_str;
        }
        else {
            if ( $ref === 'direct' ) {
                $vector_str = '['.$vector_str.']';

                if ( $dir !== '' )
                    $vector_str = $dir.' '.$vector_str;
                return $vector_str;
            }
            else if ( $ref === 'reciprocal' ) {
                $vector_str = '('.$vector_str.')';

                if ( $dir !== '' )
                    $vector_str = $dir.'* '.$vector_str;
                return $vector_str;
            }
            else {
                // Some other problem
                return '';
            }
        }
    }


    /**
     * Since there are at least three different places that can trigger rebuilds of the file header,
     * it's better for the plugin to do as much work as possible.
     *
     * @param DataRecordFields $drf
     * @param ODRUser $user
     * @param boolean $notify_user If true, then this function will throw exceptions to notify users of errors
     * @throws \Exception
     */
    public function executeOnFileDatafield($drf, $user, $notify_user = false)
    {
        // Hydrate all the files uploaded to this drf
        $query = $this->em->createQuery(
           'SELECT f
            FROM ODRAdminBundle:File f
            WHERE f.dataRecordFields = :drf
            AND f.deletedAt IS NULL'
        )->setParameters( array('drf' => $drf->getId()) );
        $results = $query->getResult();

        // There could be nothing uploaded to the field, or there could be multiple files
        /** @var File[] $results */
        $entities = array();
        foreach ($results as $num => $entity)
            $entities[ $entity->getId() ] = $entity;
        /** @var File[] $entities */

        // If nothing is uploaded, then do not continue
        if ( empty($entities) ) {
            $this->logger->debug('No files to rebuild the file headers for in datafield '.$drf->getDataField()->getId().' datarecord '.$drf->getDataRecord()->getId(), array(self::class, 'executeOnFileDatafield()', 'drf '.$drf->getId()));
            return;
        }
        $this->logger->debug('Attempting to rebuild the file headers for files in datafield '.$drf->getDataField()->getId().' datarecord '.$drf->getDataRecord()->getId().'...', array(self::class, 'executeOnFileDatafield()', 'drf '.$drf->getId()));


        // ----------------------------------------
        // Going to need the plugin config for later
        $plugin_config = self::getCurrentPluginConfig($drf->getDataField());
        if ( $plugin_config['invalid'] ) {
            $this->logger->debug('Plugin config for files in datafield '.$drf->getDataField()->getId().' datarecord '.$drf->getDataRecord()->getId().' is not valid, aborting', array(self::class, 'executeOnFileDatafield()', 'drf '.$drf->getId()));
            return;
        }

        // Determine what the header should be for files in this field
        $new_header = self::getFileHeader($drf, $plugin_config);
        // Since the process of finding a header didn't return an error, we can continue

        try {
            // For each file uploaded to this field...
            foreach ($entities as $file) {
                // ...ensure it exists in decrypted format so it can be read
                $filepath = $this->crypto_service->decryptFile($file->getId());

                // Read the file to get the current header if it has one
                $existing_header = self::readExistingFile($filepath, $plugin_config);

                // If there's a difference between the existing header and the desired header...
                if ( $new_header !== $existing_header ) {
                    // ...then move the decrypted file into the user's temp directory
                    $destination_folder = 'user_'.$user->getId().'/chunks/completed';
                    if ( !file_exists($this->odr_tmp_directory.'/'.$destination_folder) )
                        mkdir( $this->odr_tmp_directory.$destination_folder, 0777, true );

                    $tmp_filepath = $this->odr_tmp_directory.'/'.$destination_folder.'/File_'.$file->getId().'.'.$file->getExt();
                    rename($filepath, $tmp_filepath);

                    if ( $plugin_config['replace_existing_files'] === false ) {
                        // Because changing the header means there's going to be a new file, there's
                        //  no point replacing the header...the FilePreEncryptEvent is going to be
                        //  fired regardless, so might as well let self::onFilePreEncrypt() handle it

                        // Do need to save two properties of the previous file prior to its deletion
                        $prev_public_date = $file->getPublicDate();
                        $prev_quality = $file->getQuality();

                        // Delete the previous file, and fire off its events
                        $this->entity_deletion_service->deleteFile($file, $user);
                        // TODO - this fires off DatafieldModified and DatarecordModified events...are they superfluous?

                        // Need to refresh the Doctrine-cached version of this entity, otherwise other
                        //  FilePreEncryptEvent handlers will only have references to the now-deleted
                        //  file
                        $this->em->refresh($drf);

                        // Have the upload service create a new file
                        $this->upload_service->uploadNewFile($tmp_filepath, $user, $drf, null, $prev_public_date, $prev_quality, false);
                        $this->logger->debug('...finished dealing with what used to be File '.$file->getId().'...', array(self::class, 'executeOnFileDatafield()', $drf->getId()));
                    }
                    else {
                        // Update the headers in the decrypted version of the file
                        self::insertNewHeader($tmp_filepath, $new_header, $plugin_config);
                        $this->logger->debug('...replaced header for File '.$file->getId().'...', array(self::class, 'executeOnFileDatafield()', $drf->getId()));

                        // Have the upload service replace the existing file with the modified version
                        $this->upload_service->replaceExistingFile($file, $tmp_filepath, $user);
                        $this->logger->debug('...re-encrypted File '.$file->getId().'...', array(self::class, 'executeOnFileDatafield()', $drf->getId()));

                        // replaceExistingFile() will end up triggering the required events
                    }
                }
                else {
                    $this->logger->debug('...existing header already matches desired header for File '.$file->getId().'...', array(self::class, 'executeOnFileDatafield()', $drf->getId()));
                }

                // If there's no difference between the headers, then delete the decrypted version of
                //  the file if it was originally non-public
                if ( !$file->isPublic() && file_exists($filepath) )
                    unlink($filepath);

                // If there was a difference between the headers, then the previous decrypted version
                //  of the file no longer exists as a result of rename()
            }
        }
        catch (\Exception $e) {
            $this->logger->debug('-- (ERROR) '.$e->getMessage(), array(self::class, 'executeOnFileDatafield()', 'drf '.$drf->getId()));

            if ( $notify_user ) {
                // Since this isn't a background job or an event, however, the suspected reason
                //  for the problem can get displayed to the user
                throw $e;
            }
            else {
                // If this is a background job or event, then silently return in order to not screw
                //  up subsequent events
                return;
            }
        }
    }


    /**
     * Reads an already decrypted file in an attempt to extract a header from it.
     *
     * @param string $filepath
     * @param array $plugin_config @see self::getCurrentPluginConfig()
     * @throws \Exception
     *
     * @return string
     */
    private function readExistingFile($filepath, $plugin_config)
    {
        // Open the file for reading
        $handle = fopen($filepath, 'r');
        if ( !$handle )
            throw new \Exception('unable to open file at "'.$filepath.'"');

        // The header should be at the beginning of the file, indicated by lines beginning with the
        //  given prefix
        $comment_prefix = $plugin_config['comment_prefix'];

        // Locate the first character of the input file that comes after the header...
        $offset = self::findHeaderOffset($handle, $comment_prefix);

        if ( $offset === 0 ) {
            // The existing file has no header
            fclose($handle);
            return '';
        }
        else {
            // Return to the beginning of the file...
            fseek($handle, 0, SEEK_SET);
            // ...then read the entirety of the file's header in one go
            $existing_header = fread($handle, $offset);

            // Done reading the file, return whatever was read
            fclose($handle);
            return $existing_header;
        }
    }


    /**
     * Replaces whatever header exists in $tmp_filepath with $new_header.
     *
     * TODO - ...should this entire plugin be overhauled to dynamically intercept download requests and splice a header into the request?
     *
     * @param string $tmp_filepath
     * @param string $new_header
     * @param array $plugin_config
     * @throws \Exception
     */
    private function insertNewHeader($tmp_filepath, $new_header, $plugin_config)
    {
        // Open the original file for reading
        $input_handle = fopen($tmp_filepath, 'r');
        if ( !$input_handle )
            throw new \Exception('unable to open input file at "'.$tmp_filepath.'"');

        // Create a new file in the temp directory to write to
        $output_handle = fopen($tmp_filepath.'.new', 'w');
        if ( !$output_handle )
            throw new \Exception('unable to open output file at "'.$tmp_filepath.'.new"');

        // Write the new header to the output file first
        fwrite($output_handle, $new_header);


        // The header should be at the beginning of the file, indicated by lines beginning with the
        //  given prefix
        $comment_prefix = $plugin_config['comment_prefix'];

        // Locate the first character of the input file that comes after the header...
        $offset = self::findHeaderOffset($input_handle, $comment_prefix);
        // ...ensure the file pointer is to right after the header...
        fseek($input_handle, $offset, SEEK_SET);

        // ...then copy the remainder of the input file to the output
        while (true) {
            $char = fgetc($input_handle);
            if ( $char !== false )
                // If fgetc() did not return EOF, then write the character to the output file
                fwrite($output_handle, $char);
            else
                // If fgetc() did return EOF, then stop copying
                break;
        }


        // No longer need the input/output files
        fclose($input_handle);
        fclose($output_handle);

        // Replace the original file with the modified file
        rename($tmp_filepath.'.new', $tmp_filepath);
    }


    /**
     * Reads the given file until it finds a line that does not start with the given comment_prefix,
     * and returns an integer of the file position of the first line to do so
     *
     * @param resource $handle
     * @param string $comment_prefix
     *
     * @return integer
     */
    private function findHeaderOffset($handle, $comment_prefix)
    {
        // Ensure the reading starts from the beginning of the file
        $pos = 0;
        fseek($handle, $pos, SEEK_SET);

        $prefix_length = strlen($comment_prefix);
        while ( true ) {
            $str = fread($handle, $prefix_length);
            if ( $str === $comment_prefix ) {
                // This line begins with the comment prefix, so find the next line
                while ( true ) {
                    $char = fgetc($handle);
                    if ( $char === "\n" )    // Note that this ends up working whether the separator is "\r\n" or "\n"
                        break;
                }

                // Update $pos to the beginning of the next line
                $pos = ftell($handle) + 1;
            }
            else {
                // This line does not begin with the comment prefix, so $pos is the beginning of
                //  the first line of "real data"
                if ( $pos == 0 )
                    return 0;
                else
                    return $pos - 1;
            }
        }
    }


    /**
     * Attempts to insert/verify a header for a file before it actually gets encrypted by the other
     * parts of ODR.
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
            $datarecord = $drf->getDataRecord();
            $user = $entity->getCreatedBy();

            $typeclass = $datafield->getFieldType()->getTypeClass();

            // Only care about a file that get uploaded to a field using this plugin...
            $is_event_relevant = self::isEventRelevant($datafield);
            if ( $is_event_relevant ) {
                // This file was uploaded to the correct field, so it now needs to be processed
                $this->logger->debug('Received request to update headers for a newly uploaded file in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'onFilePreEncrypt()', 'drf '.$drf->getId()));


                // ----------------------------------------
                // Can't just use self::executeOnFileDatafield() here...not only is the file
                //  unencrypted, we don't want to potentially execute on other files in this field

                // Since the file hasn't been encrypted yet, it's currently in something of an
                //  odd state...getLocalFileName() doesn't return the entire path to the file, but
                //  just the directory the file/image is in instead
                $local_filepath = $entity->getLocalFileName().'/'.$entity->getOriginalFileName();

                // Going to need the plugin config for later
                $plugin_config = self::getCurrentPluginConfig($drf->getDataField());
                if ( $plugin_config['invalid'] ) {
                    $this->logger->debug('...plugin config for File '.$entity->getId().' is not valid, aborting', array(self::class, 'onFilePreEncrypt()', 'drf '.$drf->getId()));
                    return;
                }

                // Determine what the header should be for files in this field
                $new_header = self::getFileHeader($drf, $plugin_config);
                // Since the process of finding a header didn't return an error, we can continue

                // Read the file to get the current header if it has one
                $existing_header = self::readExistingFile($local_filepath, $plugin_config);

                // If there's a difference between the existing header and the desired header...
                if ( $new_header !== $existing_header ) {
                    // ...then rewrite it to have the new header
                    self::insertNewHeader($local_filepath, $new_header, $plugin_config);
                    $this->logger->debug('...replaced header for File '.$entity->getId().'...', array(self::class, 'onFilePreEncrypt()', $drf->getId()));

                    // Inserting a header almost certainly changed the filesize...need to update that
                    //  value in the database so that encryption and future decryption attempts aren't
                    //  trying to do math with an incorrect filesize
                    clearstatcache(true, $local_filepath);
                    $entity->setFilesize( filesize($local_filepath) );
                    $this->em->persist($entity);
                    $this->em->flush($entity);
                }
                else {
                    $this->logger->debug('...existing header already matches desired header for File '.$entity->getId().'...', array(self::class, 'onFilePreEncrypt()', $drf->getId()));
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
                $this->logger->debug('finished header insertion attempt for '.$typeclass.' '.$entity->getId(), array(self::class, 'onFilePreEncrypt()', $typeclass.' '.$entity->getId()));

            // Don't need to clear any caches or fire any events here, since the file encryption
            //  should handle it
        }
    }


    /**
     * Attempts to insert a header into a file as part of a MassEdit event.
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

        try {
            // Get entities related to the event
            $drf = $event->getDataRecordFields();
            $datafield = $drf->getDataField();
            $datarecord = $drf->getDataRecord();
            $user = $event->getUser();

            // Only care about a file that get changed in a field using this plugin...
            $is_event_relevant = self::isEventRelevant($datafield);
            if ( $is_event_relevant ) {
                // Since there are at least three places where this can be called from,
                //  it's better to have the render plugin do all the work
                $this->logger->debug('Received request to update headers for files in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));

                // This particular place, however, is NOT allowed to throw exceptions to notify
                //  the user of issues
                $notify_user = false;
                self::executeOnFileDatafield($drf, $user, $notify_user);
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
                $this->logger->debug('finished header insertion attempt for the files in datafield '.$datafield->getId().' datarecord '.$datarecord->getId(), array(self::class, 'onMassEditTrigger()', 'drf '.$drf->getId()));

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
            // Only the "header_data" option needs to use a custom render for the dialog...
            /** @var RenderPluginOptionsDef $rpo */
            if ( $rpo->getUsesCustomRender() ) {
                $available_configurations = self::getAvailableConfigurations($datafield);
                $available_prefixes = $available_configurations['prefixes'];
                $available_fields = $available_configurations['name_data'];
                $allowed_datatypes = $available_configurations['allowed_datatypes'];
                $field_names = $available_configurations['field_names'];

                // ...and also need to figure out what the current config is if possible...
                $current_plugin_config = self::getCurrentPluginConfig($datafield);
                // NOTE: don't care if the config isn't vaild here...the user needs a chance to fix it

                $current_prefix = '';
                if ( isset($current_plugin_config['prefix']) )
                    $current_prefix = $current_plugin_config['prefix'];

                $fields_by_name = array();
                if ( isset($current_plugin_config['fields_by_name']) )
                    $fields_by_name = $current_plugin_config['fields_by_name'];

                // ...which allows a template to be rendered
                $custom_rpo_html[$rpo->getId()] = $this->templating->render(
                    'ODROpenRepositoryGraphBundle:RRUFF:RRUFFFileHeaderInserter/plugin_settings_dialog_field_list_override.html.twig',
                    array(
                        'rpo_id' => $rpo->getId(),

                        'available_prefixes' => $available_prefixes,
                        'available_fields' => $available_fields,
                        'current_prefix' => $current_prefix,

                        'allowed_datatypes' => $allowed_datatypes,
                        'field_names' => $field_names,
                        'fields_by_name' => $fields_by_name,
                    )
                );
            }
        }

        // As a side note, the plugin settings dialog does no logic to determine which options should
        //  have custom rendering...it's solely determined by the contents of the array returned by
        //  this function.  As such, there's no validation whatsoever.
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
        //  activation is independent of the user changing public status of the file field
        $trigger_fields = array();
        foreach ($render_plugin_instance['renderPluginMap'] as $rpf_name => $rpf) {
            // Since this is a datafield plugin, it only has one entry in renderPluginMap
            $trigger_fields[ $rpf['id'] ] = true;
        }

        return $trigger_fields;
    }
}
