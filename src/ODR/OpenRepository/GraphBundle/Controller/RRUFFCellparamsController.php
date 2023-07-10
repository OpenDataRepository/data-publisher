<?php

/**
 * Open Data Repository Data Publisher
 * RRUFF Cellparams Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The downside of having the crystal system, point group, and space group fields marked as
 * "no_user_edits" via the plugin system is that the regular system for saving changes will refuse
 * to work with them.
 *
 * ...which means there has to be a controller specifically for saving changes to those fields. Yay.
 *
 * On the bright side, at least this means verification can be done on the submitted values...
 */

namespace ODR\OpenRepository\GraphBundle\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Events
use ODR\AdminBundle\Component\Event\DatafieldModifiedEvent;
use ODR\AdminBundle\Component\Event\DatarecordModifiedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\OpenRepository\GraphBundle\Plugins\RRUFF\RRUFFCellParametersPlugin;
// Symfony
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class RRUFFCellparamsController extends ODRCustomController
{

    /**
     * Saves changes made to the crystal system, point group, and space group fields from this
     * plugin.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function saveAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $request->request->all();

            if ( !isset($post['datarecord_id']) || !isset($post['token']) || !isset($post['values']) )
                throw new ODRBadRequestException('Invalid Form');

            $datarecord_id = $post['datarecord_id'];
            $csrf_token = $post['token'];
            $values = $post['values'];
            if ( count($values) !== 3 )
                throw new ODRBadRequestException('Invalid Form');


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatabaseInfoService $dbi_service */
            $dbi_service = $this->container->get('odr.database_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var CsrfTokenManager $token_manager */
            $token_manager = $this->container->get('security.csrf.token_manager');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Ensure the datatype is using the correct render plugin...
            $found_plugin = false;
            $relevant_fields = array();

            $dt_array = $dbi_service->getDatatypeArray($datatype->getGrandparent()->getId(), false);    // don't want links

            $dt = $dt_array[$datatype->getId()];
            foreach ($dt['renderPluginInstances'] as $rpi_id => $rpi) {
                if ( $rpi['renderPlugin']['pluginClassName'] === 'odr_plugins.rruff.cell_parameters' ) {
                    $found_plugin = true;

                    // Need to know the datafield ids of the fields modified by this plugin
                    $relevant_fields['Crystal System'] = $rpi['renderPluginMap']['Crystal System']['id'];
                    $relevant_fields['Point Group'] = $rpi['renderPluginMap']['Point Group']['id'];
                    $relevant_fields['Space Group'] = $rpi['renderPluginMap']['Space Group']['id'];

                    break;
                }
            }

            if ( !$found_plugin )
                throw new ODRBadRequestException('Invalid Plugin Data');

            // All three of the relevant symmetry fields need to be in the post data
            foreach ($relevant_fields as $rpf_name => $df_id) {
                if ( !isset($values[$df_id]) )
                    throw new ODRBadRequestException('Invalid Form');
            }

            // It's not valid to have a space group without a point group, or a point group without
            //  a crystal system
            $submitted_crystal_system = trim( $values[ $relevant_fields['Crystal System'] ] );
            $submitted_point_group = trim( $values[ $relevant_fields['Point Group'] ] );
            $submitted_space_group = trim( $values[ $relevant_fields['Space Group'] ] );

            if ( $submitted_space_group !== '' && $submitted_point_group === '' )
                throw new ODRBadRequestException('Not allowed to have a space group without a point group');
            if ( $submitted_point_group !== '' && $submitted_crystal_system === '' )
                throw new ODRBadRequestException('Not allowed to have a point group without a crystal system');


            // Rebuild the CSRF token to verify it's accurate...
            $token_id = 'RRUFFCellParams_'.$datatype->getId().'_'.$datarecord->getId();
            $token_id .= '_'.$relevant_fields['Crystal System'];
            $token_id .= '_'.$relevant_fields['Point Group'];
            $token_id .= '_'.$relevant_fields['Space Group'];
            $token_id .= '_Form';

            $check_token = $token_manager->getToken($token_id)->getValue();
            if ( $csrf_token !== $check_token )
                throw new ODRBadRequestException('Invalid CSRF Token');


            // ...and finally, hydrate the given datafields for later
            /** @var DataFields[] $df_lookup */
            $df_lookup = array();
            foreach ($values as $df_id => $df_value) {
                $df = $em->getRepository('ODRAdminBundle:DataFields')->find($df_id);
                if ($df == null)
                    throw new ODRNotFoundException('Datafield');

                $df_lookup[$df_id] = $df;
            }


            // ----------------------------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Ensure the user is allowed to edit each of the datafields
            foreach ($df_lookup as $df) {
                if ( !$pm_service->canEditDatafield($user, $df) )
                    throw new ODRForbiddenException();
            }
            // ----------------------------------------


            // ----------------------------------------
            // Loading the service makes sense now that all of the prerequisite data is verified
            /** @var RRUFFCellParametersPlugin $plugin_service */
            $plugin_service = $this->container->get('odr_plugins.rruff.cell_parameters');

            $all_point_groups = $plugin_service->point_groups;
            $space_group_mapping = $plugin_service->space_group_mapping;
            $space_groups = $plugin_service->space_groups;

            // Verify that the submitted data follows crystallography rules...
            if ( $submitted_crystal_system !== '' ) {
                // If the crystal system exists, it must belong to the available crystal systems
                if ( !isset($all_point_groups[$submitted_crystal_system]) )
                    throw new ODRBadRequestException('Invalid Crystal System');
                $allowed_point_groups = $all_point_groups[$submitted_crystal_system];

                if ( $submitted_point_group !== '' ) {
                    // If the point group also exists, then it must belong to the legal point groups
                    //  for that crystal system
                    if ( !in_array($submitted_point_group, $allowed_point_groups) )
                        throw new ODRBadRequestException('Invalid Point Group');
                    $allowed_space_groups = $space_group_mapping[$submitted_point_group];

                    if ( $submitted_space_group !== '' ) {
                        // If the space group also exists, then it must belong to the legal space
                        //  groups for that point group
                        $found_sg = false;
                        foreach ($allowed_space_groups as $num => $sg_id) {
                            $sg_synonyms = $space_groups[$sg_id];
                            if ( in_array($submitted_space_group, $sg_synonyms) ) {
                                $found_sg = true;
                                break;
                            }
                        }

                        if ( !$found_sg )
                            throw new ODRBadRequestException('Invalid Space Group');
                    }
                }
            }


            // ----------------------------------------
            // If this point is reached, then all three of the submitted fields can get saved
            $crystal_system_datafield = $df_lookup[ $relevant_fields['Crystal System'] ];
            $crystal_system_storage_entity = $ec_service->createStorageEntity($user, $datarecord, $crystal_system_datafield);
            $emm_service->updateStorageEntity($user, $crystal_system_storage_entity, array('value' => $submitted_crystal_system));

            $point_group_datafield = $df_lookup[ $relevant_fields['Point Group'] ];
            $point_group_storage_entity = $ec_service->createStorageEntity($user, $datarecord, $point_group_datafield);
            $emm_service->updateStorageEntity($user, $point_group_storage_entity, array('value' => $submitted_point_group));

            $space_group_datafield = $df_lookup[ $relevant_fields['Space Group'] ];
            $space_group_storage_entity = $ec_service->createStorageEntity($user, $datarecord, $space_group_datafield);
            $emm_service->updateStorageEntity($user, $space_group_storage_entity, array('value' => $submitted_space_group));
            // Saving the space group should also trigger an update to the Lattice field


            // ----------------------------------------
            // Need to mark this datarecord as updated
            try {
                // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                /** @var EventDispatcherInterface $event_dispatcher */
                $dispatcher = $this->get('event_dispatcher');
                $event = new DatarecordModifiedEvent($datarecord, $user);
                $dispatcher->dispatch(DatarecordModifiedEvent::NAME, $event);
            }
            catch (\Exception $e) {
                // ...don't want to rethrow the error since it'll interrupt everything after this
                //  event
//                if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                    throw $e;
            }

            // Delete any cached search results involving these datafields
            $dfs_for_events = array(
                $crystal_system_datafield,
                $point_group_datafield,
                $space_group_datafield,
            );
            // The lattice datafield doesn't need to have an event fired here, the render plugin
            //  will do it as part of the "derivation from space group" process

            foreach ($dfs_for_events as $df) {
                // Fire off an event notifying that the modification of the datafield is done
                try {
                    // NOTE - $dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
                    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode
                    /** @var EventDispatcherInterface $event_dispatcher */
                    $dispatcher = $this->get('event_dispatcher');
                    $event = new DatafieldModifiedEvent($df, $user);
                    $dispatcher->dispatch(DatafieldModifiedEvent::NAME, $event);
                }
                catch (\Exception $e) {
                    // ...don't want to rethrow the error since it'll interrupt everything after this
                    //  event
//                    if ( $this->container->getParameter('kernel.environment') === 'dev' )
//                        throw $e;
                }
            }

        }
        catch (\Exception $e) {
            $source = 0x13e896a9;
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
