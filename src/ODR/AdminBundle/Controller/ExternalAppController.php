<?php

/**
 * Open Data Repository Data Publisher
 * External App Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Contains actions to manage External Apps.
 *
 */

namespace ODR\AdminBundle\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\ExternalApp;
use ODR\AdminBundle\Entity\ExternalAppDatatypeLink;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Utility\UserUtility;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Templating\EngineInterface;


class ExternalAppController extends ODRCustomController
{

    /**
     * Renders a barebones page to list active external apps.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function listAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');
            /** @var EngineInterface $templating */
            $templating = $this->get('templating');


            // ----------------------------------------
            // Need a list of top-level datatype names, to make linking easier
            $query =
               'SELECT dt.id AS data_type_id, dtm.short_name
                FROM odr_data_type dt
                LEFT JOIN odr_data_type_meta dtm ON dt.id = dtm.data_type_id
                WHERE dt.id = dt.grandparent_id AND dt.metadata_for_id IS NULL
                AND dt.is_master_type = 0 AND dt.unique_id = dt.template_group
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query);

            $datatype_lookup = array();
            foreach ($results as $result) {
                $dt_id = $result['data_type_id'];
                $short_name = $result['short_name'];

                $datatype_lookup[$dt_id] = $short_name;
            }
            asort($datatype_lookup);


            // Get the data for the existing external apps
            $query = $em->createQuery(
               'SELECT ea, eam, partial eadtl.{id}, partial dt.{id},
                    partial ea_cb.{id, username, email, firstName, lastName}

                FROM ODRAdminBundle:ExternalApp AS ea
                LEFT JOIN ea.createdBy AS ea_cb
                LEFT JOIN ea.externalAppMeta AS eam
                LEFT JOIN ea.externalAppDatatypeLinks AS eadtl
                LEFT JOIN eadtl.dataType AS dt
                WHERE ea.deletedAt IS NULL AND eam.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

            // Clean up the array a bit
            $app_list = array(0 => array());
            foreach ($results as $result) {
                $app_id = $result['id'];
                $tmp = $result;

                // Flatten meta entry
                if ( isset($tmp['externalAppMeta'][0]) )
                    $tmp['externalAppMeta'] = $tmp['externalAppMeta'][0];
                else
                    throw new ODRException('Unable to render external app list because of a database error for app '.$app_id);

                // Scrub irrelevant data from the app's createdBy entry
                $tmp['createdBy'] = UserUtility::cleanUserData( $tmp['createdBy'] );

                // Flatten data for any datatype that can use this app
                if ( !empty($tmp['externalAppDatatypeLinks']) ) {
                    $cleaned = array();
                    foreach ($tmp['externalAppDatatypeLinks'] as $num => $eadtl) {
                        $dt_id = $eadtl['dataType']['id'];
                        $dt_name = $datatype_lookup[$dt_id];

                        $cleaned[$dt_id] = $dt_name;
                    }

                    $tmp['externalAppDatatypeLinks'] = $cleaned;
                }

                // Re-assign the cleaned array
                $app_list[$app_id] = $tmp;
            }

            // Need csrf tokens for all of these too
            $csrf_tokens = array();
            foreach ($app_list as $app_id => $app_data) {
                $token_id = '';
                if ($app_id == 0)
                    $token_id = 'app_list_'.$user->getId();
                else
                    $token_id = 'app_'.$app_id.'_'.$user->getId();

                $token = $token_manager->getToken($token_id)->getValue();
                $csrf_tokens[$app_id] = $token;
            }

            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:ExternalApp:list_apps.html.twig',
                    array(
                        'app_list' => $app_list,
                        'datatype_lookup' => $datatype_lookup,
                        'csrf_tokens' => $csrf_tokens,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x5c1eaf8b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves a new external app config.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function createAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

             // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');

            // Grab the data from the POST request
            $post = $request->request->all();
            if ( !isset($post['csrf_token'])
                || !isset($post['app_name'])
                || !isset($post['app_url'])
//                || !isset($post['app_description'])  // optional, technically
            ) {
                throw new ODRBadRequestException('Invalid form');
            }

            $csrf_token = $post['csrf_token'];
            $app_name = trim($post['app_name']);
            $app_url = trim($post['app_url']);
            $app_description = '';
            if ( isset($post['app_description']) )
                $app_description = trim($post['app_description']);


            // Check the csrf token
            $token_id = 'app_list_'.$user->getId();
            $check_token = $token_manager->getToken($token_id)->getValue();
            if ( $csrf_token !== $check_token )
                throw new ODRBadRequestException('Invalid CSRF Token');

            // Need to unescape these values if they're coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ($is_wordpress_integrated) {
                $app_name = stripslashes($app_name);
                $app_description = stripslashes($app_description);
                $app_url = stripslashes($app_url);
            }

            if ( $app_name === '' || $app_url === '' )
                throw new ODRBadRequestException('Name/URL must not be empty');


            // ----------------------------------------
            // Create the new entry with the given data
            $entity_create_service->createExternalApp($user, $app_name, $app_description, $app_url);

            // Flush and return
            $em->flush();

            $return['d'] = array(
                'reload' => true,  // TODO
            );
        }
        catch (\Exception $e) {
            $source = 0x3acbb870;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Saves changes made to an external app config.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function editAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            // Grab the data from the POST request
            $post = $request->request->all();
            if ( !isset($post['app_id'])
                || !isset($post['app_name'])
//                || !isset($post['app_description'])  // optional, technically
                || !isset($post['app_url'])
//                || !isset($post['linked_datatypes'])  // optional, technically
                || !isset($post['csrf_token'])
            ) {
                throw new ODRBadRequestException('Invalid form');
            }

            $app_id = intval($post['app_id']);
            $app_name = trim($post['app_name']);
            $app_url = trim($post['app_url']);
            $csrf_token = $post['csrf_token'];

            $app_description = '';
            if ( isset($post['app_description']) )
                $app_description = trim($post['app_description']);

            $linked_datatypes = array();
            if ( isset($post['linked_datatypes']) )
                $linked_datatypes = array_unique( $post['linked_datatypes'] );

            // Need to unescape these values if they're coming from a wordpress install...
            $is_wordpress_integrated = $this->getParameter('odr_wordpress_integrated');
            if ($is_wordpress_integrated) {
                $app_name = stripslashes($app_name);
                $app_description = stripslashes($app_description);
                $app_url = stripslashes($app_url);
            }


            // ----------------------------------------
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $entity_modify_service */
            $entity_modify_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            // ----------------------------------------
            // Check the csrf token
            $token_id = 'app_'.$app_id.'_'.$user->getId();
            $check_token = $token_manager->getToken($token_id)->getValue();
            if ( $csrf_token !== $check_token )
                throw new ODRBadRequestException('Invalid CSRF Token');

            /** @var ExternalApp $external_app */
            $external_app = $em->getRepository('ODRAdminBundle:ExternalApp')->find($app_id);
            if ($external_app == null)
                throw new ODRNotFoundException('External App');

            // Verify that the datatypes are legit
            $query =
               'SELECT dt.id AS data_type_id, dtm.short_name
                FROM odr_data_type dt
                LEFT JOIN odr_data_type_meta dtm ON dt.id = dtm.data_type_id
                WHERE dt.id = dt.grandparent_id
                AND dt.is_master_type = 0 AND dt.metadata_for_id IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL';
            $conn = $em->getConnection();
            $results = $conn->executeQuery($query);

            $datatype_lookup = array();
            foreach ($results as $result) {
                $dt_id = $result['data_type_id'];
                $short_name = $result['short_name'];

                $datatype_lookup[$dt_id] = $short_name;
            }

            if ( !empty($linked_datatypes) ) {
                foreach ($linked_datatypes as $num => $dt_id) {
                    if ( !isset($datatype_lookup[$dt_id]) )
                        throw new ODRBadRequestException('Invalid Datatype: "'.$dt_id.'"');
                }
            }

            // ----------------------------------------
            // Update the meta entry first
//            $changed_name = $changed_description = false;
//            if ( $external_app->getAppName() !== $app_name )
//                $changed_name = true;
//            if ( $external_app->getAppDescription() !== $app_description )
//                $changed_description = true;

            $properties = array(
                'appName' => $app_name,
                'appDescription' => $app_description,
                'appUrl' => $app_url,
            );
            $entity_modify_service->updateExternalAppMeta($user, $external_app, $properties, true);

            // ----------------------------------------
            // Update the links next
            /** @var ExternalAppDatatypeLink[] $tmp */
            $tmp = $external_app->getExternalAppDatatypeLinks();

            $external_app_links = array();
            foreach ($tmp as $app_link)
                $external_app_links[ $app_link->getDataType()->getId() ] = $app_link;
            /** @var ExternalAppDatatypeLink[] $external_app_links */

            // $linked_datatypes contains all datatypes that this app should be linked to...
            foreach ($linked_datatypes as $num => $dt_id) {
                // ...if the app is already linked to this datatype, then remove it from both arrays
                if ( isset($external_app_links[$dt_id]) ) {
                    unset( $external_app_links[$dt_id] );
                    unset( $linked_datatypes[$num] );
                }
            }

            // Any entries remaining in $external_app_links should get deleted
            foreach ($external_app_links as $dt_id => $app_link)
                $em->remove($app_link);

            // Any entries remaining in $linked_datatypes should get created
            foreach ($linked_datatypes as $num => $dt_id) {
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($dt_id);
                $entity_create_service->createExternalAppDatatypeLink($user, $external_app, $datatype, true);
            }

            // ----------------------------------------
            // Flush and return
            $em->flush();

            $return['d'] = array(
                'reload' => true,  // TODO
            );
        }
        catch (\Exception $e) {
            $source = 0xbfcad08f;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes the given external app.
     *
     * @param int $external_app_id
     * @param Request $request
     *
     * @return Response
     */
    public function deleteAction($external_app_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ExternalApp $external_app */
            $external_app = $em->getRepository('ODRAdminBundle:ExternalApp')->find($external_app_id);
            if ($external_app == null)
                throw new ODRNotFoundException('External App');

            // Need to update the deletedBy properties first...
            $external_app->setDeletedBy($user);
            $em->persist($external_app);

            /** @var ExternalAppDatatypeLink[] $external_app_links */
            $external_app_links = $external_app->getExternalAppDatatypeLinks();
            foreach ($external_app_links as $app_link) {
                $app_link->setDeletedBy($user);
                $em->persist($app_link);
            }

            $em->flush();

            // ...after which the entities can get deleted
            foreach ($external_app_links as $app_link)
                $em->remove($app_link);

            $em->remove($external_app->getExternalAppMeta());
            $em->remove($external_app);

            $em->flush();

            $return['d'] = array(
                'reload' => true,  // TODO
            );
        }
        catch (\Exception $e) {
            $source = 0x715774d6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
