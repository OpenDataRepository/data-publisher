<?php

/**
 * Open Data Repository Data Publisher
 * Datatype Export Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get xml or json versions of a single top-level datatype.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;


class DatatypeExportService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

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
     * DatatypeExportService constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param PermissionsManagementService $permissions_service
     * @param ThemeInfoService $theme_info_service
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        PermissionsManagementService $permissions_service,
        ThemeInfoService $theme_info_service,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dbi_service = $database_info_service;
        $this->pm_service = $permissions_service;
        $this->theme_service = $theme_info_service;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Renders the specified datatype in the requested format according to the user's permissions.
     *
     * @param string $version           Which version of the export to render
     * @param integer $datatype_id      Which datatype to render
     * @param string $format            The format (json, xml, etc) to render the datatype in
     * @param boolean $using_metadata   Whether to display additional metadata (who created it, public date, revision, etc)
     * @param ODRUser $user             Which user requested this
     * @param string $baseurl           The current baseurl of this ODR installation, used for file/image links
     *
     * @return string
     */
    public function getData($version, $datatype_id, $format, $using_metadata, $user, $baseurl)
    {
        // All of these should already exist
        /** @var DataType $datatype */
        $datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);

        $master_theme = $this->theme_service->getDatatypeMasterTheme($datatype->getId());

        $user_permissions = $this->pm_service->getUserPermissionsArray($user);


        // ----------------------------------------
        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $include_links = true;

        $datarecord_array = array();
        $datatype_array = $this->dbi_service->getDatatypeArray($datatype->getId(), $include_links);
        $theme_array = $this->theme_service->getThemeArray($master_theme->getId());

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datatype_array[ $datatype->getId() ] = $this->dbi_service->stackDatatypeArray($datatype_array, $datatype->getId());
        $stacked_theme_array[ $master_theme->getId() ] = $this->theme_service->stackThemeArray($theme_array, $master_theme->getId());


        // ----------------------------------------
        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:XMLExport:datatype_ajax.'.$format.'.twig';

        // Render the DataRecord
        $str = $this->templating->render(
            $template,
            array(
                'datatype_array' => $stacked_datatype_array,
                'theme_array' => $stacked_theme_array,

                'initial_datatype_id' => $datatype->getId(),
                'initial_theme_id' => $master_theme->getId(),

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
                switch ($data[$i] ) {
                    case "\"":
                        // ...and a quote was encountered, transcribe it and switch modes
                        $trimmed_str .= $data[$i];
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
                            $trimmed_str .= $data[$i];
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
                            $trimmed_str .= $data[$i];
                        }
                        break;

                    case ",":
                        // ...and a comma was encountered...
                        if ( !$just_wrote_comma ) {
                            // ...then only transcribe a comma when the previous character transcribed
                            //  was not a comma.  Don't want duplicated commas.
                            $trimmed_str .= $data[$i];
                            $just_wrote_comma = true;
                        }
                        break;

                    case " ":
                    case "\n":
                        // If not in quotes and found a space/newline, don't transcribe it
                        break;

                    default:
                        // If not in quotes and found a non-space character, transcribe it
                        $trimmed_str .= $data[$i];

                        // Commas are handled earlier in the switch statement, so the character
                        //  transcribed here can't be a comma
                        $just_wrote_comma = false;
                        break;
                }
            }
            else {
                if ($data[$i] === "\"" && $data[$i-1] !== "\\")
                    $in_quotes = false;

                // If in quotes, always transcribe every character
                $trimmed_str .= $data[$i];
            }
        }

        // Get rid of any trailing commas at the very end of the string
        while ( substr($trimmed_str, -1) === ',' )
            $trimmed_str = substr($trimmed_str, 0, -1);

        return $trimmed_str;
    }
}
