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
use ODR\AdminBundle\Entity\DataRecord;
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
     * @var ThemeInfoService
     */
    private $theme_service;

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
     * @param ThemeInfoService $theme_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatarecordInfoService $dri_service,
        DatatypeInfoService $dti_service,
        PermissionsManagementService $pm_service,
        ThemeInfoService $theme_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dri_service = $dri_service;
        $this->dti_service = $dti_service;
        $this->pm_service = $pm_service;
        $this->theme_service = $theme_service;
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
    public function getData($version, $datarecord_ids, $format, $using_metadata, $user, $baseurl, $show_records = 1)
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
        $top_level_t_ids = array();
        // Also need to store these ids in a slightly different format to make twig's life easier
        $lookup_array = array();

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $dt_id = $result['dt_id'];
            $t_id = $result['t_id'];

            $top_level_dr_ids[$dr_id] = 1;
            $top_level_dt_ids[$dt_id] = 1;
            $top_level_t_ids[$t_id] = 1;

            $lookup_array[$dr_id] = array(
                'datatype' => $dt_id,
                'theme' => $t_id
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

        $theme_array = array();
        foreach ($top_level_t_ids as $t_id => $num) {
            $t_data = $this->theme_service->getThemeArray($t_id);

            foreach ($t_data as $local_t_id => $data)
                $theme_array[$local_t_id] = $data;
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

        $stacked_theme_array = array();
        foreach ($top_level_t_ids as $t_id => $num) {
            $stacked_theme_array[$t_id] = array(
                $t_id => $this->theme_service->stackThemeArray($theme_array, $t_id)
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
                'theme_array' => $stacked_theme_array,

                'lookup_array' => $lookup_array,

                'using_metadata' => $using_metadata,
                'baseurl' => $baseurl,
                'version' => $version,
                'show_records' => $show_records
            )
        );

        // If returning as json, reformat the data because twig can't correctly format this type of data
        // TODO - twig should have nothing to do with formatting JSON data.
        if ($format == 'json')
            $str = self::reformatJson($str);

        return $str;
    }


    /**
     * Because of the recursive nature of ODR entities, any json generated by twig has a LOT of whitespace
     * and newlines...this function cleans up after twig by stripping as much of the extraneous whitespace as
     * possible.  It also ensures the final json string won't have the ",}" or ",]" character sequences outside of quotes.
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

        for ($i = 0; $i < strlen($data); $i++) {
            if (!$in_quotes) {
                if ($data{$i} === "\"") {
                    // If not in quotes and a quote is encountered, transcribe it and switch modes
                    $trimmed_str .= $data{$i};
                    $in_quotes = true;
                }
                else if ($data{$i} === '}' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes and would end up transcribing a closing brace immediately after a comma, replace the last comma with a closing brace instead
                    $trimmed_str = substr_replace($trimmed_str, '}', -1);
                }
                else if ($data{$i} === ']' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes and would end up transcribing a closing bracket immediately after a comma, replace the last comma with a closing bracket instead
                    $trimmed_str = substr_replace($trimmed_str, ']', -1);
                }
                else if ($data{$i} === ',' && substr($trimmed_str, -1) === ',') {
                    // If not in quotes, then don't transcribe a comma when the previous character is also a comma
                }
                else if ($data{$i} !== ' ' && $data{$i} !== "\n") {
                    // If not in quotes and found a non-space character, transcribe it
                    $trimmed_str .= $data{$i};
                }
            }
            else {
                if ($data{$i} === "\"" && $data{$i-1} !== "\\")
                    $in_quotes = false;

                // If in quotes, always transcribe every character
                $trimmed_str .= $data{$i};
            }
        }

        // Also get rid of parts that signify no child/linked datarecords
        $trimmed_str = str_replace( array(',"child_records":{}', ',"linked_records":{}'), '', $trimmed_str );

        return $trimmed_str;
    }
}
