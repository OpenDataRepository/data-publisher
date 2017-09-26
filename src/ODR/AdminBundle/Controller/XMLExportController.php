<?php

/**
 * Open Data Repository Data Publisher
 * XML Export Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The XML controller handles exporting XML versions of various
 * ODR entities.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class XMLExportController extends ODRCustomController
{

    /**
     * Renders and returns the XML version of the given DataRecord
     *
     * @param string $version
     * @param integer $datarecord_id
     * @param string $format
     * @param Request $request
     * 
     * @return Response
     */
    public function getDatarecordXMLAction($version, $datarecord_id, $format, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // ----------------------------------------
            // Verify the format first
            if ($format == '')
                throw new ODRBadRequestException('Invalid Format: Must request either XML or JSON');

            // Assume the user wants the export in xml...setRequestFormat() here so any error messages returned are in the desired format
            $mime_type = 'text/xml';
            $request->setRequestFormat('xml');
            if ($format == 'json') {
                $mime_type = 'application/json';
                $request->setRequestFormat('json');
            }

            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ( $user === 'anon.' ) {
                if ( !$datatype->isPublic() || !$datarecord->isPublic() ) {
                    // non-public datatype and anonymous user, can't view
                    throw new ODRForbiddenException();
                }
                else {
                    // public datatype, anybody can view
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                $can_view_datatype = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_view' ]) )
                    $can_view_datatype = true;

                $can_view_datarecord = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;

                // If either the datatype or the datarecord is not public, and the user doesn't have the correct permissions...then don't allow them to view the datarecord
                if ( (!$datatype->isPublic() && !$can_view_datatype) || (!$datarecord->isPublic() && !$can_view_datarecord) )
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------

            if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
                throw new ODRBadRequestException('Only permitted on top-level datarecords');


            // ----------------------------------------
            // Render the requested datarecord
            $data = self::GetDisplayData($em, $version, $datarecord_id, $format, $request);

            // If returning as json, reformat the data because twig can't really do a good enoug job by itself for this type of data
            if ($format == 'json')
                $data = self::reformatJson($data);


            // Set up a response to send the file back
            $response = new Response();

            $response->setPrivate();
            $response->headers->set('Content-Type', $mime_type);
            //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
            $response->headers->set('Content-Disposition', 'attachment; filename="Datarecord_'.$datarecord_id.'.'.$format.'";');

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xcd237ac9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
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
        $trimmed_str = str_replace( array(',"child_datarecords":{}', ',"linked_datarecords":{}'), '', $trimmed_str );

        return $trimmed_str;
    }


    /**
     * Renders the XMLExport version of the datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $version                  "v1" identifies datafields/datatypes by id with names as attributes, "v2" identifies datafields/datatypes by using their XML-safe name
     * @param integer $datarecord_id
     * @param string $format                   "xml" or "json"
     * @param Request $request
     *
     * @return string
     */
    private function GetDisplayData($em, $version, $datarecord_id, $format, Request $request)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');

        // All of these should already exist
        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
        $datatype = $datarecord->getDataType();

        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));


        // ----------------------------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        $user_permissions = array();

        if ( $user === 'anon.' ) {
            // no permissions to load
        }
        else {
            // Grab user's permissions
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
        }
        // ----------------------------------------


        // ----------------------------------------
        // Grab all datarecords and datatypes for rendering purposes
        $datarecord_array = $dri_service->getDatarecordArray($datarecord_id);
        $datatype_array = $dti_service->getDatatypeArrayByDatarecords($datarecord_array);

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array[ $datarecord_id ] = $dri_service->stackDatarecordArray($datarecord_array, $datarecord_id);
        $stacked_datatype_array[ $datatype->getId() ] = $dti_service->stackDatatypeArray($datatype_array, $datatype->getId(), $theme->getId());


        // ----------------------------------------
        // Determine which template to use for rendering
        $baseurl = $this->container->getParameter('site_baseurl');
        $template = 'ODRAdminBundle:XMLExport:datarecord_ajax.'.$format.'.twig';

        // Render the DataRecord
        $using_metadata = true;
        $templating = $this->get('templating');
        $html = $templating->render(
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

        return $html;
    }


    /**
     * Returns whether the provided string is valid for use as an XML "name"
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @param $str
     *
     * @return boolean
     */
    public function isValidXMLName($str)
    {
        // Ensure str doesn't start with 'xml'
        if ( strpos( strtolower($str) , 'xml') !== false )
            return false;

        // Ensure str doesn't start with an invalid character
        $pattern = self::xml_invalidnamestartchar();
        print $pattern."\n";
        if ( preg_match($pattern, substr($str, 0, 1)) == 1 )
            return false;

        // Ensure rest of str only has legal characters
        $pattern = self::xml_invalidnamechar();
        print $pattern."\n";
        if ( preg_match($pattern, substr($str, 1)) == 1 )
            return false;

        // No error in name
        return true;
    }


    /**
     * Utility function to return a regexp pattern for finding illegal "NameStartCharacters" for XML strings
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @return string
     */
    private function xml_invalidnamestartchar()
    {
        $tmp = array(
            "[\\x0-\\x39]",
            //"[\\x3A]",            // colon
            "[\\x3B-\\x40]",
            //"[\\x41-\\x5A]",      // A-Z
            "[\\x5B-\\x5E]",
            //"[\\x5F]",            // underscore
            "[\\x60]",
            //"[\\x61-\\x7A]",      // a-z
            "[\\x7B-\\xBF]",
            //"[\\xC0-\\xD6]",
            "[\\xD7]",              // multiplication sign
            //"[\\xD8-\\xF6]",
            "[\\xF7]",              // division sign
            //"[\\xF8-\\x2FF]",
            "[\\x300-\\x36F]",
            //"[\\x370-\\x37D]",
            "[\\x37E]",             // greek semicolon
            //"[\\x37F-\\x1FFF]",
            "[\\x{2000}-\\x{200B}]",
            //"[\\x200C-\\x200D]",
            "[\\x{200E}-\\x{206F}]",
            //"[\\x2070-\\x218F]",
            "[\\x{2190}-\\x{2BFF}]",
            //"[\\x2C00-\\x2FEF]",
            "[\\x{2FF0}-\\x{3000}]",
            //"[\\x3001-\\xD7FF]",
            "[\\x{D800}-\\x{F8FF}]",    // private use area
            //"[\\xF900-\\xFDCF]",
            "[\\x{FDD0}-\\x{FDEF}]",    // not characters
            //"[\\xFDF0-\\xFFFD]",
            "[\\x{FFFE}-\\x{FFFF}]",    // not characters
            //"[\\x10000-\\xEFFFF]"
        );

        return '/'.implode("|", $tmp).'/u';
    }


    /**
     * Utility function to return a regexp pattern for finding illegal "NameCharacters" for XML strings
     * @see http://www.w3.org/TR/xml/#sec-common-syn
     *
     * @return string
     */
    private function xml_invalidnamechar()
    {
        $tmp = array(
            "[\\x0-\\x2C]",
            //"[\\x2D-\\x2E]",      // hyphen, period
            "[\\x2F]",
            //"[\\x30-\\x39]",      // 0-9
            //"[\\x3A]",            // colon
            "[\\x3B-\\x40]",
            //"[\\x41-\\x5A]",      // A-Z
            "[\\x5B-\\x5E]",
            //"[\\x5F]",            // underscore
            "[\\x60]",
            //"[\\x61-\\x7A]",      // a-z
            "[\\x7B-\\xB6]",
            //"[\\xB7]",            // "middle dot"
            "[\\xB8-\\xBF]",
            //"[\\xC0-\\xD6]",
            "[\\xD7]",              // multiplication sign
            //"[\\xD8-\\xF6]",
            "[\\xF7]",              // division sign
            //"[\\xF8-\\x2FF]",
            //"[\\x300-\\x36F]",
            //"[\\x370-\\x37D]",
            "[\\x37E]",             // greek semicolon
            //"[\\x37F-\\x1FFF]",
            "[\\x{2000}-\\x{200B}]",
            //"[\\x200C-\\x200D]",
            "[\\x{200E}-\\x{203E}]",
            //"[\\x203F-\\x2040]",
            "[\\x{2041}-\\x{206F}]",
            //"[\\x2070-\\x218F]",
            "[\\x{2190}-\\x{2BFF}]",
            //"[\\x2C00-\\x2FEF]",
            "[\\x{2FF0}-\\x{3000}]",
            //"[\\x3001-\\xD7FF]",
            "[\\x{D800}-\\x{F8FF}]",    // private use area
            //"[\\xF900-\\xFDCF]",
            "[\\x{FDD0}-\\x{FDEF}]",    // not characters
            //"[\\xFDF0-\\xFFFD]",
            "[\\x{FFFE}-\\x{FFFF}]",    // not characters
            //"[\\x10000-\\xEFFFF]"
        );

        return '/'.implode("|", $tmp).'/u';
    }
}
