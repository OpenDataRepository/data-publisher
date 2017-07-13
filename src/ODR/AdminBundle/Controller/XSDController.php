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
// Forms
// Services
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
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
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DataType $datatype_id */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // Ensure this is a top-level datatype
            $top_level_datatypes = parent::getTopLevelDatatypes();
//print '<pre>'.print_r($top_level_datatypes, true).'</pre>'; exit();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('Unable to generate an XML Schema Document for a Datatype that is not top level');

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            // ----------------------------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if ( $user === 'anon.' ) {
                if ( $datatype->isPublic() ) {
                    /* anonymous users aren't restricted from viewing a public datatype */
                }
                else {
                    // ...otherwise, datatype is not public, don't let anonymous users see it
                    throw new ODRForbiddenException();
                }
            }
            else {
                // Grab user's permissions
                $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];

                // If user has view permissions, show non-public sections of the datarecord
                $has_view_permission = false;
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dt_view' ]) )
                    $has_view_permission = true;

                // If datatype is not public and user doesn't have the correct permissions, don't let them view the datatype
                if ( !($datatype->isPublic() || $has_view_permission) )
                    throw new ODRForbiddenException();
            }
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $source);
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
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');

        // All of these should already exist
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        /** @var Theme $theme */
        $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // ----------------------------------------
        // Determine user privileges
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        $user_permissions = array();

        if ($user === 'anon.') {
            // public datatype, anybody can view
        }
        else {
            // Grab user's permissions
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
        }
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

}
