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
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeExportService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class XMLExportController extends ODRCustomController
{

    /**
     * Renders and returns the json/XML version of the given DataRecord when accessed via the OAuth firewall
     *
     * @param string $version         'v1' or 'v2'
     * @param integer $datatype_id
     * @param string $format          'xml' or 'json'
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeDataAction($version, $datatype_id, $format, Request $request)
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

            /** @var DatatypeExportService $dte_service */
            $dte_service = $this->container->get('odr.datatype_export_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');


            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Only permitted on top-level datatypes');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
            if ( $user === 'anon.' ) {
                if ( !$datatype->isPublic() ) {
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

                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datarecord
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dte_service->getData($version, $datatype_id, $format, $user_permissions, $baseurl);

            // Set up a response to send the datarecord back
            $response = new Response();

            $response->setPrivate();
            $response->headers->set('Content-Type', $mime_type);
            //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
            $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_'.$datatype_id.'.'.$format.'";');

            $response->setContent($data);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xc3368234;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Renders and returns the json/XML version of the given DataRecord when accessed via the regular UI
     *
     * @param string $version         'v1' or 'v2'
     * @param integer $datarecord_id
     * @param string $format          'xml' or 'json'
     * @param Request $request
     * 
     * @return Response
     */
    public function getDatarecordExportAction($version, $datarecord_id, $format, Request $request)
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

            /** @var DatarecordExportService $dre_service */
            $dre_service = $this->container->get('odr.datarecord_export_service');

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

            if ($datarecord->getId() != $datarecord->getGrandparent()->getId())
                throw new ODRBadRequestException('Only permitted on top-level datarecords');


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = array();
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
                if (!$datatype->isPublic() && !$can_view_datatype)
                    throw new ODRForbiddenException();
                if (!$datarecord->isPublic() && !$can_view_datarecord)
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datarecord
            $baseurl = $this->container->getParameter('site_baseurl');
            $data = $dre_service->getData($version, $datarecord_id, $format, $user_permissions, $baseurl);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
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
