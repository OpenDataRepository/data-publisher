<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Export Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get xml or json versions of a single top-level datarecord.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\Theme;
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
    public function __construct(EntityManager $entity_manager, DatarecordInfoService $dri_service, DatatypeInfoService $dti_service, PermissionsManagementService $pm_service, EngineInterface $templating, Logger $logger)
    {
        $this->em = $entity_manager;
        $this->dri_service = $dri_service;
        $this->dti_service = $dti_service;
        $this->pm_service = $pm_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Renders the specified datarecord in the requested format according to the user's permissions.
     *
     * @param string $version           Which version of the export to render
     * @param integer $datarecord_id      Which datarecord to render
     * @param string $format            The format (json, xml, etc) to render the datarecord in
     * @param boolean $using_metadata   Whether to display additional metadata (who created it, public date, revision, etc)
     * @param array $user_permissions   The permissions of the user requesting this
     * @param string $baseurl           The current baseurl of this ODR installation, used for file/image links
     *
     * @return string
     */
    public function getData($version, $datarecord_id, $format, $using_metadata, $user_permissions, $baseurl)
    {
        // All of these should already exist
        /** @var DataRecord $datarecord */
        $datarecord = $this->em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
        $datatype = $datarecord->getDataType();

        /** @var Theme $theme */
        $theme = $this->em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));


        // ----------------------------------------
        // Grab all datarecords and datatypes for rendering purposes
        $datarecord_array = $this->dri_service->getDatarecordArray($datarecord_id);
        $datatype_array = $this->dti_service->getDatatypeArrayByDatarecords($datarecord_array);

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array[ $datarecord_id ] = $this->dri_service->stackDatarecordArray($datarecord_array, $datarecord_id);
        $stacked_datatype_array[ $datatype->getId() ] = $this->dti_service->stackDatatypeArray($datatype_array, $datatype->getId(), $theme->getId());


        // ----------------------------------------
        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:XMLExport:datarecord_ajax.'.$format.'.twig';

        // Render the DataRecord
        $str = $this->templating->render(
            $template,
            array(
                'datatype_array' => $stacked_datatype_array,
                'datarecord_array' => $stacked_datarecord_array,
                'theme_id' => $theme->getId(),

                'initial_datatype_id' => $datatype->getId(),
                'initial_datarecord_id' => $datarecord->getId(),

                'using_metadata' => $using_metadata,
                'baseurl' => $baseurl,
                'version' => $version,
            )
        );

        // If returning as json, reformat the data because twig can't correctly format this type of data
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
