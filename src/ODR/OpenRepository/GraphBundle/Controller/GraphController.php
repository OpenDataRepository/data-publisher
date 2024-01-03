<?php

/**
 * Open Data Repository Data Publisher
 * Graph Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The Graph plugins detect when the graph images don't exist, and silently trigger a rebuild when
 * they're executed on page load.  As such, a controller action to force a rebuild isn't all that
 * useful...but I'm not deleting it yet, just in case.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;


class GraphController extends ODRCustomController
{

    /**
     * The Graph plugins detect when the graph images don't exist, and silently trigger a rebuild when
     * they're executed on page load.  As such, a controller action to force a rebuild isn't all that
     * useful...but I'm not deleting it yet, just in case.
     *
     * @param string $request_datatype_id
     * @param string $request_datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function renderAction($request_datatype_id, $request_datarecord_id, Request $request)
    {
        try {
            // The requested datarecord id could have 'rollup_' in front of it
            $is_rollup = false;
            if (preg_match('/rollup_/', $request_datarecord_id)) {
                $is_rollup = true;
                $request_datarecord_id = substr($request_datarecord_id, 7);
            }

            $request_datatype_id = intval($request_datatype_id);
            $request_datarecord_id = intval($request_datarecord_id);

            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $datarecord_info_service */
            $datarecord_info_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatabaseInfoService $database_info_service */
            $database_info_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $permissions_service */
            $permissions_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            /** @var DataRecord $request_datarecord */
            $request_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($request_datarecord_id);
            if ($request_datarecord == null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Requested Datarecord does not exist."}');

            /** @var DataType $request_datatype */
            $request_datatype = $request_datarecord->getDataType();
            if ($request_datatype->getDeletedAt() != null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Requested Datatype does not exist."}');

            // NOTE: when odr_plugins.base.filter_graph calls this, it's likely that $request_datatype
            //  does not match $request_datatype_id...the id will point to whichever datatype the
            //  plugin is attached to


            // Need the grandparent datarecord/datatype for permissions and cached arrays
            $grandparent_datarecord = $request_datarecord->getGrandparent();
            if ($grandparent_datarecord->getDeletedAt() != null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Requested Datarecord does not exist."}');

            $grandparent_datatype = $request_datatype->getGrandparent();
            if ($grandparent_datatype->getDeletedAt() != null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Requested Datatype does not exist."}');

            $is_top_level = 1;
            if ($request_datarecord->getId() !== $request_datarecord->getGrandparent()->getId())
                $is_top_level = 0;


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];
            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $grandparent_datatype);

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$permissions_service->canViewDatarecord($user, $request_datarecord) )
                throw new \Exception('{ "message": "Permission Denied", "detail": "Insufficient permissions."}');
            // ----------------------------------------


            // ----------------------------------------
            // Don't care which theme the user is currently using
            $master_theme = $theme_info_service->getDatatypeMasterTheme($grandparent_datatype->getId());
            $plugin_theme_array = $theme_info_service->getThemeArray($master_theme->getId());

            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $datarecord_array = $datarecord_info_service->getDatarecordArray($grandparent_datarecord->getId());    // need links due to the odr_plugins.base.filter_graph plugin...
            $datatype_array = $database_info_service->getDatatypeArray($grandparent_datatype->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // The datatype to be passed to the plugin should not be wrapped with its id
            $plugin_dt_array = $database_info_service->stackDatatypeArray($datatype_array, $request_datatype_id);

            // The datatype could technically have multiple render plugins, but there should only be
            //  one graph plugin active
            $plugin_classname = null;
            $plugin_rpi_array = null;
            foreach ($datatype_array[$request_datatype_id]['renderPluginInstances'] as $rpi_id => $rpi) {
                // NOTE: not using the stacked version here due to odr_plugins.base.filter_graph...
                //  That plugin tends to have its renderPlugin entry in a childtype...
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $plugin_classname === 'odr_plugins.base.graph'
                    || $plugin_classname === 'odr_plugins.base.gcms'
                    || $plugin_classname === 'odr_plugins.base.filter_graph'
                ) {
                    $plugin_rpi_array = $rpi;
                    break;
                }
            }
            if ( is_null($plugin_rpi_array) )
                throw new \Exception('{ "message": "Item Deleted", "detail": "Unable to find the RenderPluginInstance info."}');


            // The various graph plugins require two related but different snapshots of the cached
            //  datarecord array
            $plugin_parent_dr_array = null;
            $plugin_dr_array = null;

            // First task is to determine the id of the parent datarecord...
            $parent_dr_id = null;

            if ( $is_top_level === 1 ) {
                // ...the requested datarecord is already top-level, so its "parent" is itself
                $parent_dr_id = $request_datarecord_id;
            }
            else {
                // ...the requested datarecord is not top-level, so need to find the parent.  Since
                //  $datarecord_array is not stacked, this can be done iteratively
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ( isset($dr['children'][$request_datatype_id]) ) {
                        if ( in_array($request_datarecord_id, $dr['children'][$request_datatype_id]) ) {
                            $parent_dr_id = $dr_id;
                            break;
                        }
                    }
                }

                // This should always find something, since the child record has to exist before
                //  the graph plugin can request to render something for it...
            }
            if ( is_null($parent_dr_id) )
                throw new \Exception('{ "message": "Item Deleted", "detail": "Unable to find the Parent Datarecord info."}');


            if ( !$is_rollup ) {
                // If this isn't a rollup graph, then the first argument for the plugin service should
                //  only be a single cached datarecord entry.  The array needs to be wrapped with
                //  its own id
                $plugin_dr_array = array($request_datarecord_id => $datarecord_info_service->stackDatarecordArray($datarecord_array, $request_datarecord_id));

                // The argument for the parent datarecord array does not need to be wrapped with its
                //  own id
                if ( $is_top_level === 1 )
                    $plugin_parent_dr_array = $plugin_dr_array[$request_datarecord_id];
                else
                    $plugin_parent_dr_array = $datarecord_info_service->stackDatarecordArray($datarecord_array, $parent_dr_id);
            }
            else {
                // If this is a rollup graph, then potentially need to provide multiple cached
                //  datarecord entries to the first argument of the plugin
                if ( $is_top_level === 1 ) {
                    // Regardless of which graph plugin is calling for a rebulid, this variable is
                    //  acquired the same way
                    $plugin_parent_dr_array = $datarecord_info_service->stackDatarecordArray($datarecord_array, $request_datarecord_id);

                    if ( $plugin_classname !== 'odr_plugins.base.filter_graph' ) {
                        // ...if this is a regular graph plugin, and the requested datarecord is
                        // top-level...then there's only one record anyways
                        $plugin_dr_array = array($request_datarecord_id => $plugin_parent_dr_array);
                    }
                    else {
                        // ...if this is the FilterGraph plugin, then the plugin needs datarecords
                        //  belonging to $request_datatype_id
                        if ( $plugin_parent_dr_array['dataType']['id'] === $request_datatype_id )
                            $plugin_dr_array = array($plugin_parent_dr_array['id'] => $plugin_parent_dr_array);
                        else if ( isset($plugin_parent_dr_array['children'][$request_datatype_id]) )
                            $plugin_dr_array = $plugin_parent_dr_array['children'][$request_datatype_id];
                        else
                            throw new \Exception('{ "message": "Not Implemented", "detail": "TODO"}');
                    }
                }
                else {
                    // ...if the requested datarecord is not top-level, then it's fastest to stack
                    //  the parent datarecord first...
                    $plugin_parent_dr_array = $datarecord_info_service->stackDatarecordArray($datarecord_array, $parent_dr_id);

                    // ...then use that to get all the child records of the relevant childtype
                    $plugin_dr_array = $plugin_parent_dr_array['children'][$request_datatype_id];
                }
            }

            if ( is_null($plugin_parent_dr_array) || is_null($plugin_dr_array) )
                throw new \Exception('{ "message": "Item Deleted", "detail": "Unable to find the Datarecord info."}');


            // ----------------------------------------
            // Need some additional data so twig doesn't complain when rendering graph_builder.html.twig
            $rendering_options = array(
                'build_graph' => true,

                // The value of the rest of these options shouldn't really matter since this call is
                //  only telling the graph plugin to render/save a graph
                'is_top_level' => $is_top_level,
                'is_link' => 0,
                'display_type' => ThemeDataType::ACCORDION_HEADER,
                'multiple_allowed' => 0,

                'is_datatype_admin' => $is_datatype_admin,
            );

            if ($is_rollup)
                $rendering_options['datarecord_id'] = 'rollup';
            else
                $rendering_options['datarecord_id'] = $request_datarecord_id;


            // ----------------------------------------
            // Render the static graph using the correct plugin
            $plugin_classname = $plugin_rpi_array['renderPlugin']['pluginClassName'];

            /** @var DatatypePluginInterface $svc */
            $svc = $this->container->get($plugin_classname);
            $svc->execute($plugin_dr_array, $plugin_dt_array, $plugin_rpi_array, $plugin_theme_array, $rendering_options, $plugin_parent_dr_array, $datatype_permissions, $datafield_permissions);

//            $site_baseurl = $this->container->getParameter('site_baseurl');
//            return $this->redirect($site_baseurl.$filename);


            // ----------------------------------------
            // Do not want browsers caching the redirect to this request...it can generate completely
            //  different URLs for the same input
//            $response = new RedirectResponse($site_baseurl.$filename);
//            $response->setMaxAge(0);
//            $response->headers->addCacheControlDirective('no-cache', true);
//            $response->headers->addCacheControlDirective('must-revalidate', true);
//            $response->headers->addCacheControlDirective('no-store', true);
//
//            return $response;

//            $return = array();
//            $return['r'] = 0;
//            $return['t'] = '';
//            $return['d'] = '';

            // TODO - can't really return a redirect because one request could create multiple graphs...
            // TODO - ...but just returning this doesn't seem right
            $response = new Response(json_encode( array() ), 202);
            return $response;
        }
        catch (\Exception $e) {
            $message = $e->getMessage();
            $message_data = json_decode($message);

            if ($message_data) {
                // Not sure whether this gets used
                $response = self::svgWarning($message_data->message, $message_data->detail);
            }
            else {
                // Error div only has space for around 75 characters
                $message = str_split($message, 75);
                $response = self::svgWarning($message);
            }

            $headers = array(
                'Content-Type' => 'image/svg+xml',
//                'Content-Disposition' => 'inline;filename=error_message.svg'    // uncommenting this breaks IE and chrome
            );
            return new Response($response, '200', $headers);
        }
    }


    /**
     * @param string|array $message
     * @param string $detail
     *
     * @return mixed
     */
    public function svgWarning($message, $detail = "") {

        $templating = $this->get('templating');

        return $templating->render(
            'ODROpenRepositoryGraphBundle:Base:Graph/graph_error.html.twig',
            array(
                'message' => $message,
                'detail' => $detail
            )
        );
    }
}
