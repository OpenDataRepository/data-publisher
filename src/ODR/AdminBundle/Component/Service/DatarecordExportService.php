<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Export Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get xml or json versions of top-level datarecords...it's capable
 * of handling everything from exporting a single datarecord, to exporting multiple datarecords
 * across multiple datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class DatarecordExportService
{

    /**
     * @var EntityManager
     */
    private $em;

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
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * DatarecordExportService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatarecordInfoService $dri_service
     * @param DatatypeInfoService $dti_service
     * @param PermissionsManagementService $pm_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatarecordInfoService $datarecord_info_service,
        DatatypeInfoService $datatype_info_service,
        PermissionsManagementService $permissions_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dri_service = $datarecord_info_service;
        $this->dti_service = $datatype_info_service;
        $this->pm_service = $permissions_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Renders the specified datarecord in the requested format according to the user's permissions.
     *
     * @param string $version          Which version of the export to render
     * @param array $datarecord_ids    Which datarecords to render...this MUST NOT have datarecords the user isn't allowed to view
     * @param string $format           The format (json, xml, etc) to render the datarecord in
     * @param boolean $using_metadata  Whether to display additional metadata (who created it, public date, revision, etc)
     * @param ODRUser $user            Which user requested this
     * @param string $baseurl          The current baseurl of this ODR installation, used for file/image links
     *
     * @return string
     */
    public function getData($version, $datarecord_ids, $format, $using_metadata, $user, $baseurl, $show_records = 1, $record_search = false)
    {
        // ----------------------------------------
        // Since these datarecords could belong to multiple datatypes, it's faster to get ids
        //  straight from the database...
        $query = $this->em->createQuery(
           'SELECT dt.id AS dt_id, t.id AS t_id, dr.id AS dr_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            JOIN ODRAdminBundle:Theme AS t WITH t.dataType = dt
            WHERE dr.id IN (:datarecord_ids)
            AND t.themeType = :theme_type AND t = t.sourceTheme
            AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL AND t.deletedAt IS NULL'
        )->setParameters(
            array(
                'datarecord_ids' => $datarecord_ids,
                'theme_type' => 'master'
            )
        );
        $results = $query->getArrayResult();


        // Need unique lists of all ids...
        $top_level_dr_ids = array();
        $top_level_dt_ids = array();
        // Also need to store these ids in a slightly different format to make twig's life easier
        $lookup_array = array();

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $dt_id = $result['dt_id'];

            $top_level_dr_ids[$dr_id] = 1;
            $top_level_dt_ids[$dt_id] = 1;

            $lookup_array[$dr_id] = array(
                'datatype' => $dt_id
            );
        }


        // ----------------------------------------
        // Grab all datarecords and datatypes for rendering purposes
        $include_links = true;

        $datarecord_array = array();
        foreach ($top_level_dr_ids as $dr_id => $num) {
            $dr_data = $this->dri_service->getDatarecordArray($dr_id, $include_links);

            foreach ($dr_data as $local_dr_id => $data)
                $datarecord_array[$local_dr_id] = $data;
        }

        $datatype_array = array();
        foreach ($top_level_dt_ids as $dt_id => $num) {
            $dt_data = $this->dti_service->getDatatypeArray($dt_id, $include_links);

            foreach ($dt_data as $local_dt_id => $data)
                $datatype_array[$local_dt_id] = $data;
        }

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened arrays so that render plugins for a datatype can also
        //  correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array = array();
        foreach ($top_level_dr_ids as $dr_id => $num) {
            // Double-stacking the ids like this allows this service to export datarecords across
            //  multiple datatypes
            $stacked_datarecord_array[$dr_id] = array(
                $dr_id => $this->dri_service->stackDatarecordArray($datarecord_array, $dr_id)
            );
        }

        $stacked_datatype_array = array();
        foreach ($top_level_dt_ids as $dt_id => $num) {
            $stacked_datatype_array[$dt_id] = array(
                $dt_id => $this->dti_service->stackDatatypeArray($datatype_array, $dt_id)
            );
        }


        // ----------------------------------------
        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:XMLExport:datarecord_ajax.'.$format.'.twig';
        // Render the DataRecord
        $str = $this->templating->render(
            $template,
            array(
                'sorted_datarecord_ids' => $datarecord_ids,    // Use the given array of datarecord ids to as the output order

                'datatype_array' => $stacked_datatype_array,
                'datarecord_array' => $stacked_datarecord_array,
                'record_search' => $record_search,

                'lookup_array' => $lookup_array,

                'using_metadata' => $using_metadata,
                'baseurl' => $baseurl,
                'version' => $version,
                'show_records' => $show_records
            )
        );

        // If returning as json, reformat the data because twig can't correctly format this type of data
        if ($format == 'json')
            $str = self::reformatJson($str);

        return $str;
    }


    /**
     * Because of the recursive nature of ODR entities, any json generated by twig has a LOT of
     * whitespace, newlines, and trailing commas...this function deletes all the extra commas to
     * end up with valid JSON, and also strips as much of the extraneous whitespace as possible.
     *
     * Twig can't avoid generating trailing commas because of how it renders child records.
     *
     * @param string $data
     *
     * @return string
     */
    private function reformatJson($data)
    {
        // Get rid of all whitespace characters that aren't inside double-quotes
        $trimmed_str = '';
        $in_quotes = false;
        $just_wrote_comma = false;

        for ($i = 0; $i < strlen($data); $i++) {
            if (!$in_quotes) {
                // If not in quotes...
                switch ($data{$i} ) {
                    case "\"":
                        // ...and a quote was encountered, transcribe it and switch modes
                        $trimmed_str .= $data{$i};
                        $in_quotes = true;

                        // Last character transcribed was not a comma
                        $just_wrote_comma = false;
                        break;

                    case "}":
                        // ...and a closing brace was encountered...
                        if ( $just_wrote_comma ) {
                            // ...then transcribing this would create a closing brace immediately
                            //  after a comma.  Not proper JSON.

                            // Instead, replace the most recent comma with a closing brace
                            $trimmed_str = substr_replace($trimmed_str, '}', -1);

                            // Last character transcribed was not a comma
                            $just_wrote_comma = false;
                        }
                        else {
                            // Otherwise, closing brace after some non-comma, just transcribe it
                            $trimmed_str .= $data{$i};
                        }
                        break;

                    case "]":
                        // ...and a closing bracket was encountered...
                        if ( $just_wrote_comma ) {
                            // ...then transcribing this would create a closing bracket immediately
                            //  after a comma.  Not proper JSON.

                            // Instead, replace the most recent comma with a closing bracket
                            $trimmed_str = substr_replace($trimmed_str, ']', -1);

                            // Last character transcribed was not a comma
                            $just_wrote_comma = false;
                        }
                        else {
                            // Otherwise, closing bracket after some non-comma, just transcribe it
                            $trimmed_str .= $data{$i};
                        }
                        break;

                    case ",":
                        // ...and a comma was encountered...
                        if ( !$just_wrote_comma ) {
                            // ...then only transcribe a comma when the previous character transcribed
                            //  was not a comma.  Don't want duplicated commas.
                            $trimmed_str .= $data{$i};
                            $just_wrote_comma = true;
                        }
                        break;

                    case " ":
                    case "\n":
                        // If not in quotes and found a space/newline, don't transcribe it
                        break;

                    default:
                        // If not in quotes and found a non-space character, transcribe it
                        $trimmed_str .= $data{$i};

                        // Commas are handled earlier in the switch statement, so the character
                        //  transcribed here can't be a comma
                        $just_wrote_comma = false;
                        break;
                }
            }
            else {
                if ($data{$i} === "\"" && $data{$i-1} !== "\\")
                    $in_quotes = false;

                // If in quotes, always transcribe every character
                $trimmed_str .= $data{$i};
            }
        }

        // Get rid of any trailing commas at the very end of the string
        while ( substr($trimmed_str, -1) === ',' )
            $trimmed_str = substr($trimmed_str, 0, -1);

        return $trimmed_str;
    }
}
