<?php

/**
 * Open Data Repository Data Publisher
 * XSD Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The XSD controller builds the XSD schema definitions for
 * the various entites in the database that need a schema to
 * validate XML imports.  It also provides users the ability
 * to download these XSD schemas.
 *
 * TODO - no download action for radio_option xsd
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class XSDController extends ODRCustomController
{

    /**
     * Renders and returns the XML version of the given DataRecord
     *
     * @param string $version
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function getDatatypeXSDAction($version, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Ensure this is a top-level datatype
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
//print '<pre>'.print_r($top_level_datatypes, true).'</pre>'; exit();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to generate an XML Schema Document for a Datatype that is not top level');

            $theme = $theme_service->getDatatypeMasterTheme($datatype->getId());


            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Render the requested datarecord
            $xml = self::XSD_GetDisplayData($em, $version, $datatype_id, $request);

            // Set up a response to send the file back
            $response = new Response();

            $response->setPrivate();
            $response->headers->set('Content-Type', 'text/xml');
            //$response->headers->set('Content-Length', filesize($xml_export_path.$filename));
            $response->headers->set('Content-Disposition', 'attachment; filename="Datatype_'.$datatype_id.'.xsd";');

            $response->setContent($xml);
            return $response;
        }
        catch (\Exception $e) {
            $source = 0xd6c39f82;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode());
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Renders and returns the XSD schema definition for a DataType.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $version                  "v1" identifies datafields/datatypes by id with names as attributes, "v2" identifies datafields/datatypes by using their XML-safe name
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return string
     */
    private function XSD_GetDisplayData($em, $version, $datatype_id, Request $request)
    {
        throw new ODRNotImplementedException();

        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');

        // These should already exist
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        $theme = $theme_service->getDatatypeMasterTheme($datatype->getId());

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // ----------------------------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        $user_permissions = $pm_service->getUserPermissionsArray($user);
        // ----------------------------------------


        // ----------------------------------------
        // Always bypass cache if in dev mode?
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


        // ----------------------------------------
        // Determine which datatypes/childtypes to load from the cache
        $include_links = true;
        $associated_datatypes = parent::getAssociatedDatatypes($em, array($datatype_id), $include_links);

//print '<pre>'.print_r($associated_datatypes, true).'</pre>'; exit();

        // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
        $datatree_array = parent::getDatatreeArray($em, $bypass_cache);

//print '<pre>'.print_r($datatree_array, true).'</pre>'; exit();

        $datatype_array = array();
        foreach ($associated_datatypes as $num => $dt_id) {
            $datatype_data = parent::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$dt_id)));
            if ($bypass_cache || $datatype_data == null)
                $datatype_data = parent::getDatatypeData($em, $datatree_array, $dt_id, $bypass_cache);

            foreach ($datatype_data as $dt_id => $data)
                $datatype_array[$dt_id] = $data;
        }

//print '<pre>'.print_r($datatype_data, true).'</pre>'; exit();

        // ----------------------------------------
        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $datarecord_array = array();
        $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

//print '<pre>'.print_r($datarecord_array, true).'</pre>';  exit();
//print '<pre>'.print_r($datatype_array, true).'</pre>';  exit();


        // ----------------------------------------
        // Render the schema layout
        $templating = $this->get('templating');
        $xml = $templating->render(
            'ODRAdminBundle:XSDCreate:xsd_ajax.html.twig',
            array(
                'datatype_array' => $datatype_array,
                'initial_datatype_id' => $datatype_id,
                'theme_id' => $theme->getId(),

                'version' => $version,
            )
        );

        return $xml;
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
