<?php

/**
 * Open Data Repository Data Publisher
 * Reference Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The various Reference plugins are somewhat easier to use when they display a preview of the
 * fully formatted reference on the Edit page...but that requires a controller action to re-render
 * the reference each time a change is made.
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
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


class ReferenceController extends ODRCustomController
{

    /**
     * The various Reference plugins are somewhat easier to use when they display a preview of the
     *  fully formatted reference on the Edit page...but that requires a controller action to re-render
     *  the reference each time a change is made.
     *
     * @param string $request_datarecord_id
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function renderAction($request_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
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
                throw new ODRNotFoundException('Datarecord');

            $is_top_level = 1;
            if ($request_datarecord->getId() !== $request_datarecord->getGrandparent()->getId())
                $is_top_level = 0;

            $request_datatype = $request_datarecord->getDataType();
            if ($request_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $request_datatype_id = $request_datatype->getId();


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $permissions_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];
            $is_datatype_admin = $permissions_service->isDatatypeAdmin($user, $request_datatype);

            // If the user isn't allowed to view either the datatype or the datarecord, don't continue
            if ( !$permissions_service->canViewDatarecord($user, $request_datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Don't care which theme the user is currently using
            $master_theme = $theme_info_service->getDatatypeMasterTheme($request_datatype_id);
            $plugin_theme_array = $theme_info_service->getThemeArray($master_theme->getId());

            // Get all Datarecords and Datatypes that are associated with the datarecord to render
            $datarecord_array = $datarecord_info_service->getDatarecordArray($request_datarecord_id, false);
            $datatype_array = $database_info_service->getDatatypeArray($request_datatype_id, false);

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $permissions_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // The datatype to be passed to the plugin should not be wrapped with its id
            $plugin_dt_array = $database_info_service->stackDatatypeArray($datatype_array, $request_datatype_id);

            // The datatype could technically have multiple render plugins, but there should only be
            //  one graph plugin active
            $plugin_classname = null;
            $plugin_rpi_array = null;
            foreach ($datatype_array[ $request_datatype_id ]['renderPluginInstances'] as $rpi_id => $rpi) {
                // NOTE: not using the stacked version here due to odr_plugins.base.filter_graph...
                //  That plugin tends to have its renderPlugin entry in a childtype...
                $plugin_classname = $rpi['renderPlugin']['pluginClassName'];
                if ( $plugin_classname === 'odr_plugins.base.references'
                    || $plugin_classname === 'odr_plugins.chemin.chemin_references'
                    || $plugin_classname === 'odr_plugins.rruff.rruff_references'
                ) {
                    $plugin_rpi_array = $rpi;
                    break;
                }
            }
            if ( is_null($plugin_rpi_array) )
                throw new ODRException('Unable to find the RenderPluginInstance info');


            // ----------------------------------------
            // Need to determine the id of the parent datarecord...
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
                //  anything can make a request to render it...
            }
            if ( is_null($parent_dr_id) )
                throw new ODRException('Unable to find the Parent Datarecord info');

            // The first argument for the plugin service should only be a single cached datarecord
            //  entry, wrapped with its own id
            $plugin_dr_array = array($request_datarecord_id => $datarecord_info_service->stackDatarecordArray($datarecord_array, $request_datarecord_id));

            // The argument for the parent datarecord array does not need to be wrapped
            $plugin_parent_dr_array = null;
            if ( $is_top_level === 1 )
                $plugin_parent_dr_array = $plugin_dr_array[$request_datarecord_id];
            else
                $plugin_parent_dr_array = $datarecord_info_service->stackDatarecordArray($datarecord_array, $parent_dr_id);


            // ----------------------------------------
            // Need some additional data so twig doesn't complain when rendering
            $rendering_options = array(
                'is_link' => 0,    // these three values don't really matter at the moment
                'display_type' => 0,
                'multiple_allowed' => 0,

                'is_top_level' => $is_top_level,
                'context' => 'html',    // like 'text', but want files/urls...
                'is_datatype_admin' => $is_datatype_admin,
            );

            /** @var DatatypePluginInterface $svc */
            $svc = $this->container->get($plugin_classname);
            $str = $svc->execute($plugin_dr_array, $plugin_dt_array, $plugin_rpi_array, $plugin_theme_array, $rendering_options, $plugin_parent_dr_array, $datatype_permissions, $datafield_permissions);

            $return['d'] = $str;
        }
        catch (\Exception $e) {
            $source = 0x6005597e;
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
