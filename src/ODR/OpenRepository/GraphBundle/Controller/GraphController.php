<?php

/**
 * Open Data Repository Data Publisher
 * Graph Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User;
// Services
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;


class GraphController extends ODRCustomController
{

    /**
     * TODO
     *
     * @param integer $plugin_id
     * @param integer $datatype_id
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function staticAction($plugin_id, $datatype_id, $datarecord_id, Request $request)
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
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $pm_service->getUserPermissionsArray($user);

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$pm_service->canViewDatarecord($user, $datarecord) )
                throw new \Exception('{ "message": "Permission Denied", "detail": "Insufficient permissions."}');
            // ----------------------------------------


            // ----------------------------------------
            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $include_links = false;

            $datarecord_array = $dri_service->getDatarecordArray($datarecord->getId(), $include_links);
            $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);
            $theme_array = $theme_service->getThemesForDatatype($datatype->getId(), $user);


            // ----------------------------------------
            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // Call Render Plugin
            // Filter Data Records to only include desired datatype
            foreach ($datarecord_array as $dr_id => $dr) {
                if ($dr['dataType']['id'] != $datatype_id) {
                    unset($datarecord_array[$dr_id]);
                }
            }

            // Determine if this is a single or rollup graph.
            // If single only send the one datarecord

            // Load and execute the render plugin
            $datatype = $datatype_array[$datatype_id];
            $render_plugin = $datatype['dataTypeMeta']['renderPlugin'];
//            $theme = $datatype['themes'][$requested_theme->getId()];
            $svc = $this->container->get($render_plugin['pluginClassName']);
            // Build Graph - Static Option
            // {% set rendering_options = {'is_top_level': is_top_level, 'is_link': is_link, 'display_type': display_type} %}
            $rendering_options = array();
            $rendering_options['is_top_level'] = $is_top_level;
            // TODO Figure out where display_type comes from.  Is it deprecated?
            $rendering_options['display_type'] = 100000;
            $rendering_options['is_link'] = false;
            $rendering_options['build_graph'] = true;
            if ($is_rollup) {
                $rendering_options['datarecord_id'] = 'rollup';
            }
            else {
                $rendering_options['datarecord_id'] = $datarecord_id;
            }


            // Render the static graph
            $filename = $svc->execute($datarecord_array, $datatype, $render_plugin, $theme_array, $rendering_options);
            return $this->redirect("/uploads/files/graphs/" . $filename);
        }
        catch (\Exception $e) {
            $message = $e->getMessage();
            $message_data = json_decode($message);
            if($message_data) {
                $response = self::svgWarning($message_data->message, $message_data->detail);
            }
            else {
                $response = self::svgWarning($message);
            }
            $headers = array(
                'Content-Type' => 'image:svg+xml',
                'Content-Disposition' => 'inline;filename=error_message.svg'
            );
            return new Response($response, '200', $headers);
        }
    }


    /**
     * @param string $message
     * @param string $detail
     *
     * @return mixed
     */
    public function svgWarning($message, $detail = "") {

        $templating = $this->get('templating');

        return $templating->render(
            'ODROpenRepositoryGraphBundle:Graph:graph_error.html.twig',
            array(
                'message' => $message,
                'detail' => $detail
            )
        );
    }
}
