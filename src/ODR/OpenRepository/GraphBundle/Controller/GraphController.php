<?php

/**
 * Open Data Repository Data Publisher
 * Graph Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * When the Graph Plugin is executed as part of twig rendering, it creates an <img> tag with a src
 * attribute that points to staticAction() in this controller.  When the browser attempts to load
 * the image, this controller action calls the Graph Plugin again with a slightly different set of
 * options that will cause it to return a link to the cached graph image.  If the graph image isn't
 * cached, the Graph Plugin will get phantomJS server to generate/save the image first.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
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
     * Regular rendering of the GraphPlugin in Display mode leaves a link to this controller action
     * when the cached version of the desired graph doesn't exist...this controller action calls
     * the GraphPlugin again, but this time instructs it to actually build the graph.
     *
     * @param integer $datatype_id
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function staticAction($datatype_id, $datarecord_id, Request $request)
    {
        try {
            $is_rollup = false;
            // Check if this is a rollup and filter datarecord_id
            if (preg_match('/rollup_/', $datarecord_id)) {
                $is_rollup = true;
                $datarecord_id = preg_replace("/rollup_/","",$datarecord_id);
            }

            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $theme_service */
            $theme_service = $this->container->get('odr.theme_info_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Data record no longer exists."}');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new \Exception('{ "message": "Item Deleted", "detail": "Data type no longer exists."}');

            // Save incase the user originally requested a child datarecord
//            $requested_datarecord = $datarecord;
//            $requested_datatype = $datatype;
//            $requested_theme = $theme;


            // ...want the grandparent datarecord and datatype for everything else, however
            $is_top_level = 1;
            if ($datarecord->getId() !== $datarecord->getGrandparent()->getId()) {
                $is_top_level = 0;
                $datarecord = $datarecord->getGrandparent();

                $datatype = $datarecord->getDataType();
                if ($datatype == null)
                    throw new \Exception('{ "message": "Item Deleted", "detail": "Data type no longer exists."}');
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $is_datatype_admin = $pm_service->isDatatypeAdmin($user, $datatype);

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new \Exception('{ "message": "Permission Denied", "detail": "Insufficient permissions."}');
            // ----------------------------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $include_links = false;

            $datarecord_array = $dri_service->getDatarecordArray($datarecord->getId(), $include_links);
            $datatype_array = $dbi_service->getDatatypeArray($datatype->getId(), $include_links);

            // This is only going to be rendering the graph as an image, so the master theme can
            //  be used here without any issue
            $theme = $theme_service->getDatatypeMasterTheme($datatype->getId());
            $theme_array = $theme_service->getThemeArray($theme->getId());

            // Need to restrict to the master theme of the datatype being rendered, however
            foreach ($theme_array as $theme_id => $t) {
                if ( $t['dataType']['id'] != $datatype_id )
                    unset( $theme_array[$theme_id] );
            }


            // ----------------------------------------
            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

            // Need to locate cached datarecord, datatype, and render plugin entries
            foreach ($datarecord_array as $dr_id => $dr) {
                if ( $dr['dataType']['id'] != $datatype_id ) {
                    unset( $datarecord_array[$dr_id]) ;
                }
            }
            $datatype = $datatype_array[$datatype_id];

            // The datatype could technically have multiple render plugins, but since the graph plugin
            //  is set to "render: true", there should only be one of them
            $render_plugin_instance = null;
            foreach ($datatype['renderPluginInstances'] as $rpi_num => $rpi) {
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $plugin_classname === 'odr_plugins.base.graph'
                    || $plugin_classname === 'odr_plugins.base.gcms'
                ) {
                    $render_plugin_instance = $rpi;
                    break;
                }
            }
            if ( is_null($render_plugin_instance) )
                throw new \Exception('{ "message": "Item Deleted", "detail": "RenderPluginInstance does not exist."}');

            // Build Graph - Static Option
            $rendering_options = array(
                'build_graph' => true,

                // The value of these options shouldn't really matter since this call is only
                //  telling the graph plugin to render/save a graph
                'is_top_level' => $is_top_level,
                'is_link' => 0,
                'display_type' => ThemeDataType::ACCORDION_HEADER,
                'multiple_allowed' => 0,
                'theme_type' => 'master',

                'is_datatype_admin' => $is_datatype_admin,
            );

            if ($is_rollup)
                $rendering_options['datarecord_id'] = 'rollup';
            else
                $rendering_options['datarecord_id'] = $datarecord_id;


            // ----------------------------------------
            // Render the static graph using the correct plugin
            $plugin_classname = $render_plugin_instance['renderPlugin']['pluginClassName'];

            /** @var DatatypePluginInterface $svc */
            $svc = $this->container->get($plugin_classname);
            $filename = $svc->execute($datarecord_array, $datatype, $render_plugin_instance, $theme_array, $rendering_options);

            return $this->redirect($filename);
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
