<?php

/**
 * Open Data Repository Data Publisher
 * DataType Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataType controller handles creation and editing (most)
 * properties of datatypes.  Deletion is handled in DisplayTemplate.
 * It also handles rendering the pages that allow the user to design
 * datatypes, or modify datarecords of each datatype.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Forms
use ODR\AdminBundle\Form\UpdateDatatypePropertiesForm;
use ODR\AdminBundle\Form\CreateDatatypeForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneMasterDatatypeService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\UUIDService;
use FOS\UserBundle\Doctrine\UserManager;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class DatatypeController extends ODRCustomController
{

    /**
     * Update the database properties metadata and database metadata
     * to reflect the updated database name.
     *
     * Also updates metadata for all datatypes in the template set.
     *
     * @param Request $request
     * @return Response
     */
    public function update_propertiesAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = array();

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Create new DataType form
            $submitted_data = new DataTypeMeta();
            $form = $this->createForm(UpdateDatatypePropertiesForm::class, $submitted_data);

            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if ($form->isValid()) {
                    // Get datatype by looking up meta ...
                    $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

                    /** @var DataType $datatype */
                    $datatype = $repo_datatype->find($datatype_id);

                    // This is a properties database - it should have metadata_for
                    if($datatype->getMetadataFor() == null) {
                        throw new ODRException('Incorrect datatype.  Must be properties datatype.');
                    }

                    $datatype_array = $repo_datatype->findBy(array('template_group' => $datatype->getTemplateGroup()));

                    /** @var DataType $dt */
                    foreach($datatype_array as $dt) {

                        // Must be admin to update the related metadata
                        if($pm_service->isDatatypeAdmin($user, $dt)) {
                            $metadata = $dt->getDataTypeMeta();
                            $new_meta = clone $metadata;

                            $new_meta->setCreated(new \DateTime());
                            $new_meta->setUpdated(new \DateTime());
                            $new_meta->setCreatedBy($user);
                            $new_meta->setUpdatedBy($user);

                            if($dt->getUniqueId() == $dt->getTemplateGroup()) {
                                // This is the actual datatype
                                $new_meta->setLongName($submitted_data->getLongName());
                                $new_meta->setDescription($submitted_data->getDescription());
                            }
                            elseif($dt->getMetadataFor() !== null) {
                                // This is the datatype properties
                                $new_meta->setLongName($submitted_data->getLongName());
                                $new_meta->setDescription($submitted_data->getDescription());
                            }
                            else {
                                $new_meta->setLongName($submitted_data->getLongName() . " - " . $new_meta->getShortName());
                            }


                            // Save and commit
                            $em->persist($new_meta);
                            $em->remove($metadata);
                            $em->flush();
                        }
                    }

                    // Need to create a form for editing datatype metadata
                    // Should edit the properties type and the datatype itself...
                    $em->refresh($datatype);
                    $new_datatype_meta = $datatype->getDataTypeMeta();
                    $params = array(
                        'form_settings' => array(
                        )
                    );
                    $form = $this->createForm(UpdateDatatypePropertiesForm::class, $new_datatype_meta, $params);

                    $templating = $this->get('templating');
                    $html = $templating->render(
                        'ODRAdminBundle:Datatype:update_datatype_properties_form.html.twig',
                        array(
                            'datatype' => $datatype,
                            'form' => $form->createView(),
                        )
                    );

                    $return['d'] = array(
                        'datatype_id' => $datatype->getId(),
                        'html' => $html,
                    );
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x28381af2;
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
     * Redirects to the datatype's metadata entry, creating a datarecord for that purpose if it
     * doesn't already exist.
     *
     * @param $datatype_id
     * @param int $wizard
     * @param Request $request
     *
     * @return Response
     */
    public function propertiesAction($datatype_id, $wizard, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            // TODO Change the checker to re-route to landing when complete? not sure
            if ($datatype->getSetupStep() == DataType::STATE_CLONE_FAIL) {
                throw new ODRException('Cloning failure, please contact the ODR team');
            }
            else if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
                $templating = $this->get('templating');
                $return['t'] = "html";
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                        array(
                            "datatype" => $datatype
                        )
                    )
                );
            }
            else {

                $properties_datatype = null;
                if ( !is_null($datatype->getMetadataDatatype()) ) {
                    // If this is a regular datatype, load its metadata datatype
                    $properties_datatype = $datatype->getMetadataDatatype();
                }
                else if ( !is_null($datatype->getMetadataFor()) ) {
                    // This is already a metadata datatype
                    $properties_datatype = $datatype;
                }
                else {
                    // TODO - how to handle a datatype without a metadata entry
                    throw new ODRException('This datatype does not have a metadata entry');
                }

                // Ensure user has admin permissions to both the datatype and its metadata datatype
                if (!$pm_service->isDatatypeAdmin($user, $datatype))
                    throw new ODRForbiddenException();
                if (!$pm_service->isDatatypeAdmin($user, $properties_datatype))
                    throw new ODRForbiddenException();


                // Retrieve what should be the first and only datarecord...
                $results = $em->getRepository('ODRAdminBundle:DataRecord')->findBy(
                    array(
                        'dataType' => $properties_datatype->getId()
                    )
                );

                if ( count($results) == 0 ) {
                    // A metadata datarecord doesn't exist...create one
                    $datarecord = $ec_service->createDatarecord($user, $properties_datatype, true);    // don't flush immediately...

                    // Don't need to do anything else to the metadata datarecord, immediately
                    //  remove provisioned flag
                    $datarecord->setProvisioned(false);
                    $em->flush();
                }
                else {
                    $datarecord = $results[0];
                }


                // ----------------------------------------
                // Render the required version of the page
                $edit_html = $odr_render_service->getEditHTML(
                    $user,
                    $datarecord,
                    null,       // don't care about search_key
                    null        // ...or search_theme_id
                );

                // Need to create a form for editing datatype metadata
                // Should edit the properties type and the datatype itself...
                $new_datatype_data = $properties_datatype->getDataTypeMeta();
                $params = array(
                    'form_settings' => array()
                );
                $form = $this->createForm(UpdateDatatypePropertiesForm::class, $new_datatype_data, $params);


                // $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));
                $redirect_path = '';
                $datatype_permissions = $pm_service->getDatatypePermissions($user);

                $templating = $this->get('templating');
                $record_header_html = $templating->render(
                    'ODRAdminBundle:Edit:properties_edit_header.html.twig',
                    array(
                        'datatype_permissions' => $datatype_permissions,
                        'datarecord' => $datarecord,
                        'datatype' => $datatype,

                        // values used by search_header.html.twig
                        'redirect_path' => $redirect_path,
                    )
                );

                $html = $templating->render(
                    'ODRAdminBundle:Datatype:properties.html.twig',
                    array(
                        'wizard' => $wizard,
                        'datatype' => $datatype,
                        'user' => $user,
                        'form' => $form->createView(),
                        'edit_html' => $edit_html
                    )
                );

                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => $record_header_html . $html,
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x5ae7d1e5;
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
     * Takes a unique_id or a search slug string, and returns a redirect to the datatype the string
     * refers to.
     *
     * @param string $datatype_unique_id
     * @param Request $request
     *
     * @return Response
     */
    public function find_landingAction($datatype_unique_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            if ( $datatype_unique_id === 'admin' ) {
                $return['d'] = $this->generateUrl('odr_admin_homepage');
            }
            else {
                $use_search_slug = false;

                /** @var DataType $datatype */
                $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array('unique_id' => $datatype_unique_id)
                );
                if ($datatype == null) {
                    // Attempt find by slug
                    /** @var DataTypeMeta $datatype_meta */
                    $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')
                        ->findOneBy(array('searchSlug' => $datatype_unique_id));
                    if ( is_null($datatype_meta) )
                        throw new ODRNotFoundException('Datatype');

                    $use_search_slug = true;
                    $datatype = $datatype_meta->getDataType();

                    if ($datatype->getTemplateGroup() == null) {
                        // Not a valid database for dashboard access.
                        throw new ODRNotFoundException('Datatype');
                    }
                }

                if ($datatype == null)
                    throw new ODRNotFoundException('Datatype');

                /** @var DataType $landing_datatype */
                $landing_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(
                    array('unique_id' => $datatype->getTemplateGroup())
                );

                if ($use_search_slug) {
                    $url_prefix = $this->generateUrl('odr_search', array(
                        'search_slug' => $landing_datatype->getDataTypeMeta()->getSearchSlug(),
                        'search_string' => ''
                    ), false);
                }
                else {
                    $url_prefix = $this->generateUrl('odr_search', array(
                        'search_slug' => $landing_datatype->getUniqueId(),
                        'search_string' => ''
                    ), false);
                }

                $url = $this->generateUrl('odr_datatype_landing', array(
                    'datatype_id' => $landing_datatype->getId()
                ), false);

                $return['d'] = $url_prefix."#".$url;
            }
        }
        catch (\Exception $e) {
            $source = 0x22b8dae6;
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
     * Renders and returns the HTML for a datatype's "landing" page...has links for administration
     * and for listing related datatypes.
     *
     * @param $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function landingAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');
            $grandparent_datatype = $datatype->getGrandparent();

            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // ----------------------------------------
            // Check if this is a master template based datatype that is still in the creation process...
            // TODO Change the checker to re-route to landing when complete? not sure
            if ($datatype->getSetupStep() == DataType::STATE_CLONE_FAIL) {
                throw new ODRException('Cloning failure, please contact the ODR team');
            }
            else if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
                // The database is still in the process of being created...return the HTML for the page that'll periodically check for progress
                $templating = $this->get('templating');
                $return['t'] = "html";
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_status_checker.html.twig',
                        array(
                            "datatype" => $datatype
                        )
                    )
                );
            }
            else {
                // Ensure user has permissions to be doing this
                if ( !$pm_service->canViewDatatype($user, $datatype) )
                    throw new ODRForbiddenException();

                $datatype_permissions = $pm_service->getDatatypePermissions($user);


                // ----------------------------------------
                // Need to locate all datatypes that link to and are linked to by the requested datatype
                $datatree_array = $dti_service->getDatatreeArray();
                $linked_anestors = $dti_service->getLinkedAncestors( array($grandparent_datatype->getId()), $datatree_array );
                $linked_descendants = $dti_service->getLinkedDescendants( array($grandparent_datatype->getId()), $datatree_array );

                $linked_datatypes = array_merge($linked_anestors, $linked_descendants);

                // Get Data for Related Records
                $query = $em->createQuery(
                   'SELECT dt, dtm, partial gp.{id}, md, mf, dt_cb, dt_ub
                    FROM ODRAdminBundle:DataType AS dt
                    LEFT JOIN dt.dataTypeMeta AS dtm
                    LEFT JOIN dt.grandparent AS gp
                    LEFT JOIN dt.metadata_datatype AS md
                    LEFT JOIN dt.metadata_for AS mf
                    LEFT JOIN dt.createdBy AS dt_cb
                    LEFT JOIN dt.updatedBy AS dt_ub
                    WHERE dt.setup_step IN (:setup_steps)
                    AND (dt.template_group LIKE :template_group OR dt.id IN (:linked_datatypes))
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND gp.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'template_group' => $datatype->getTemplateGroup(),
                        'linked_datatypes' => $linked_datatypes,
                        'setup_steps' => DataType::STATE_VIEWABLE
                    )
                );
                // AND dt.is_master_type = (:is_master_type)
                // 'is_master_type' => $is_master_type

                $results = $query->getArrayResult();

                $datatypes = array();
                foreach ($results as $result) {
                    $dt_id = $result['id'];

                    $dt = $result;
                    $dt['dataTypeMeta'] = $result['dataTypeMeta'][0];
                    $dt['createdBy'] = UserUtility::cleanUserData($result['createdBy']);
                    $dt['updatedBy'] = UserUtility::cleanUserData($result['updatedBy']);
                    if (isset($result['metadata_datatype']) && count($result['metadata_datatype']) > 0) {
                        // $dt['metadata_datatype']['dataTypeMeta'] = $result['metadata_datatype']['dataTypeMeta'][0];
                        $dt['metadata_datatype'] = $result['metadata_datatype'];
                    }
                    if (isset($result['metadata_for']) && count($result['metadata_for']) > 0) {
                        $dt['metadata_for'] = $result['metadata_for'];
                    }

                    // TODO - why was this data loaded?  it wasn't used in the twig files...
//                    $dt['associated_datatypes'] = $dti_service->getAssociatedDatatypes(array($dt_id));
                    $datatypes[$dt_id] = $dt;
                }


                // ----------------------------------------
                // Determine how many datarecords the user has the ability to view for each datatype
                $datatype_ids = array_keys($datatypes);
                $related_metadata = self::getDatarecordCounts($em, $datatype_ids, $datatype_permissions);

                // Only want to display recent changes for the top-level datatypes...
                $datatype_names = array();
                foreach ($datatypes as $dt_id => $dt) {
                    if ( $dt['id'] === $dt['grandparent']['id'] ) {
                        // ...don't want to display changes for the metadata datatypes
                        if ( is_null($dt['metadata_for']) )
                            $datatype_names[$dt_id] = $dt['dataTypeMeta']['shortName'];
                    }
                }

                // Build the graphs for each of the top-level datatypes
                $dashboard_graphs = self::getDashboardGraphs($em, $datatype_names, $datatype_permissions);


                // ----------------------------------------
                // Render the required version of the page
                $templating = $this->get('templating');

                $html = $templating->render(
                    'ODRAdminBundle:Datatype:landing.html.twig',
                    array(
                        'user' => $user,
                        'initial_datatype_id' => $datatype->getId(),
                        'datatype_permissions' => $datatype_permissions,
                        'related_datatypes' => $datatypes,
                        'related_metadata' => $related_metadata,

                        'dashboard_graphs' => $dashboard_graphs,
                    )
                );

                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'html' => $html,
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x74c7b210;
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
     * Recalculates the dashboard blurb for a specified datatype.  Caching barely speeds this up.
     *
     * @param EntityManager $em
     * @param array $datatype_ids
     * @param array $datatype_permissions
     *
     * @return string
     */
    private function getDashboardGraphs($em, $graph_datatypes, $datatype_permissions)
    {
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        $templating = $this->get('templating');

        $conn = $em->getConnection();

        $graph_str = '';
        foreach ($graph_datatypes as $dt_id => $dt_name) {
            $str = $cache_service->get('dashboard_'.$dt_id);
            if ( $str === false || $str === ''  ) {
                // Going to need to run queries to figure out these values...
                $created = array();
                $total_created = 0;
                $updated = array();
                $total_updated = 0;

                // Going to need to know whether the user can view non-public datarecords in order
                //  to calculate the correct values...
                $can_view_datarecord = false;
                if ( isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dr_view'])
                ) {
                    $can_view_datarecord = true;
                }

                for ($i = 1; $i <= 6; $i++) {
                    // Created...
                    $query_str = '';
                    if ( $can_view_datarecord ) {
                        $query_str =
                           'SELECT COUNT(*) AS dr_count
                            FROM odr_data_record dr
                            WHERE dr.data_type_id = '.$dt_id.'
                            AND dr.created >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                            AND dr.created < DATE_SUB(NOW(), INTERVAL '.($i - 1).'*7 DAY)
                            AND dr.deletedAt IS NULL';
                    }
                    else {
                        $query_str =
                           'SELECT COUNT(*) AS dr_count
                            FROM odr_data_record dr
                            JOIN odr_data_record_meta drm ON drm.data_record_id = dr.id
                            WHERE dr.data_type_id = '.$dt_id.' AND drm.public_date != "2200-01-01 00:00:00"
                            AND dr.created >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                            AND dr.created < DATE_SUB(NOW(), INTERVAL '.($i - 1).'*7 DAY)
                            AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
                    }

                    $result = $conn->executeQuery($query_str);
                    $results = $result->fetchAll();

                    $num = $results[0]['dr_count'];
                    $total_created += $num;
                    $created[] = $num;

                    // Updated...
                    if ( $can_view_datarecord ) {
                        $query_str =
                           'SELECT COUNT(*) AS dr_count
                            FROM odr_data_record dr
                            WHERE dr.data_type_id = '.$dt_id.'
                            AND dr.updated >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                            AND dr.updated < DATE_SUB(NOW(), INTERVAL '.($i - 1).'*7 DAY)
                            AND dr.deletedAt IS NULL';
                    }
                    else {
                        $query_str =
                           'SELECT COUNT(*) AS dr_count
                            FROM odr_data_record dr
                            JOIN odr_data_record_meta drm ON drm.data_record_id = dr.id
                            WHERE dr.data_type_id = '.$dt_id.' AND drm.public_date != "2200-01-01 00:00:00"
                            AND dr.updated >= DATE_SUB(NOW(), INTERVAL '.($i).'*7 DAY)
                            AND dr.updated < DATE_SUB(NOW(), INTERVAL '.($i - 1).'*7 DAY)
                            AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL';
                    }

                    $result = $conn->executeQuery($query_str);
                    $results = $result->fetchAll();

                    $num = $results[0]['dr_count'];
                    $total_updated += $num;
                    $updated[] = $num;
                }

                $created_str = $total_created.' created';
                $updated_str = $total_updated.' modified';

                $value_str = '';
                for ($i = 5; $i >= 0; $i--)
                    $value_str .= $created[$i].':'.$updated[$i].',';
                $value_str = substr($value_str, 0, -1);

                $graph = $templating->render(
                    'ODRAdminBundle:Datatype:dashboard_graphs.html.twig',
                    array(
                        'datatype_name' => $dt_name,
                        'created_str' => $created_str,
                        'updated_str' => $updated_str,
                        'value_str' => $value_str,
                    )
                );

                // Not caching since it barely makes a difference
//                $cache_service->set('dashboard_'.$dt_id, $graph);
//                $cache_service->expire('dashboard_'.$dt_id, 1*24*60*60);    // Cache this dashboard entry for upwards of one day

                $graph_str .= $graph;
            }
            else {
                $graph_str .= $str;
            }
        }

        return $graph_str;
    }


    /**
     * Builds and returns a list of top-level datatypes or master templates.
     *
     * @param string $section Either "databases", "templates", or "datatemplates"
     * @param Request $request
     *
     * @return Response
     */
    public function listAction($section, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // Grab each top-level datatype from the repository
            $is_master_type = ($section == "templates" || $section == "datatemplates") ? 1 : 0;

            $query_sql =
               'SELECT dt, dtm, md, mf, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.metadata_datatype AS md
                LEFT JOIN dt.metadata_for AS mf
                LEFT JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.updatedBy AS dt_ub
                WHERE 
                dt.id IN (:datatypes)
                AND dt.is_master_type = (:is_master_type)
                AND dt.deletedAt IS NULL 
                AND dtm.deletedAt IS NULL
                AND (
                        dt.preload_status IS NULL 
                        OR dt.preload_status LIKE \'issued\'
                        OR dt.preload_status LIKE \'\'
                    )
                ';

            if($section == 'databases')
                $query_sql .= ' AND dt.unique_id = dt.template_group';

            if ($section == "datatemplates")
                $query_sql .= ' AND dt.metadata_datatype IS NULL';

            $query = $em->createQuery($query_sql);
            $query->setParameters(
                array(
                    'datatypes' => $top_level_datatypes,
                    'is_master_type' => $is_master_type
                )
            );

            $results = $query->getArrayResult();

            // TODO This whole loop seems superfluous
            $datatypes = array();
            $metadata_datatype_ids = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];

                $dt = $result;
                $dt['dataTypeMeta'] = $result['dataTypeMeta'][0];
                $dt['createdBy'] = UserUtility::cleanUserData($result['createdBy']);
                $dt['updatedBy'] = UserUtility::cleanUserData($result['updatedBy']);
                if (isset($result['metadata_datatype']) && count($result['metadata_datatype']) > 0) {
                    $dt['metadata_datatype'] = $result['metadata_datatype'];
                    array_push($metadata_datatype_ids, $dt['metadata_datatype']['id']);
                }
                if (isset($result['metadata_for']) && count($result['metadata_for']) > 0) {
                    $dt['metadata_for'] = $result['metadata_for'];
                }

                $datatypes[$dt_id] = $dt;
            }


            // ----------------------------------------
            // Determine how many datarecords the user has the ability to view for each datatype
            $datatype_ids = array_keys($datatypes);
            $metadata = self::getDatarecordCounts($em, $datatype_ids, $datatype_permissions);
            $datatypes = self::getCorrectedNames($em, $metadata_datatype_ids, $datatypes);

            // Get corrected names


            // Render and return the html for the datatype list
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:type_list.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'section' => $section,
                        'datatypes' => $datatypes,
                        'metadata' => $metadata,
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            $source = 0x24d5aae9;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

    private function getCorrectedNames($em, $datatype_ids, $datatypes) {
        // We need to get true database name for this datatype
        $query_sql =
            'SELECT
                        dt, mf, dr, drm, drf, drf_df, drf_drm, e_lt, e_lvc, e_mvc, e_svc
                        FROM ODRAdminBundle:DataType dt
                        LEFT JOIN dt.metadata_for AS mf
                        LEFT JOIN dt.dataRecords AS dr
                        LEFT JOIN dr.dataRecordMeta AS drm
                        LEFT JOIN dr.dataRecordFields AS drf
                        LEFT JOIN drf.dataField AS drf_df
                        LEFT JOIN drf_df.dataFieldMeta AS drf_drm
                        LEFT JOIN drf.longText AS e_lt
                        LEFT JOIN drf.longVarchar AS e_lvc
                        LEFT JOIN drf.mediumVarchar AS e_mvc
                        LEFT JOIN drf.shortVarchar AS e_svc
                        
                        WHERE 
                        dt.id IN (:datatype_ids)
                        AND dt.deletedAt IS NULL 
                        AND drf_drm.internal_reference_name LIKE \'datatype_name\'
                        ';


        $query = $em->createQuery($query_sql);
        $query->setParameters(
            array(
                'datatype_ids' => $datatype_ids
            )
        );

        $datatype_results = $query->getArrayResult();

        foreach ($datatype_results as $datatype_result) {
            if (
                isset($datatype_result['dataRecords'])
                && isset($datatype_result['dataRecords'][0])
                && isset($datatype_result['dataRecords'][0]['dataRecordFields'])
                && isset($datatype_result['dataRecords'][0]['dataRecordFields'][0])
            ) {
                $field = $datatype_result['dataRecords'][0]['dataRecordFields'][0];
                $datatype_id = $datatype_result['metadata_for']['id'];
                if (count($field['longText']) > 0) {
                    $datatypes[$datatype_id]['dataTypeMeta']['shortName'] = $field['longText'][0]['value'];
                    $datatypes[$datatype_id]['dataTypeMeta']['longName'] = $field['longText'][0]['value'];
                } else if (count($field['longVarchar']) > 0) {
                    $datatypes[$datatype_id]['dataTypeMeta']['shortName'] = $field['longVarchar'][0]['value'];
                    $datatypes[$datatype_id]['dataTypeMeta']['longName'] = $field['longVarchar'][0]['value'];
                } else if (count($field['mediumVarchar']) > 0) {
                    $datatypes[$datatype_id]['dataTypeMeta']['shortName'] = $field['mediumVarchar'][0]['value'];
                    $datatypes[$datatype_id]['dataTypeMeta']['longName'] = $field['mediumVarchar'][0]['value'];
                } else if (count($field['shortVarchar']) > 0) {
                    $datatypes[$datatype_id]['dataTypeMeta']['shortName'] = $field['shortVarchar'][0]['value'];
                    $datatypes[$datatype_id]['dataTypeMeta']['longName'] = $field['shortVarchar'][0]['value'];
                }
            }
        }

        return $datatypes;

    }

    /**
     * Returns an array with how many datarecords the user is allowed to see for each datatype in
     * $datatype_ids
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $datatype_ids
     * @param array $datatype_permissions
     *
     * @return array
     */
    private function getDatarecordCounts($em, $datatype_ids, $datatype_permissions)
    {
        $can_view_public_datarecords = array();
        $can_view_nonpublic_datarecords = array();

        foreach ($datatype_ids as $num => $dt_id) {
            if ( isset($datatype_permissions[$dt_id])
                && isset($datatype_permissions[$dt_id]['dr_view'])
            ) {
                $can_view_nonpublic_datarecords[] = $dt_id;
            } else {
                $can_view_public_datarecords[] = $dt_id;
            }
        }

        // Figure out how many datarecords the user can view for each of the datatypes
        $metadata = array();
        if ( count($can_view_nonpublic_datarecords) > 0 ) {
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                WHERE dt IN (:datatype_ids) AND dr.provisioned = FALSE
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL
                GROUP BY dt.id'
            )->setParameters(
                array(
                    'datatype_ids' => $can_view_nonpublic_datarecords
                )
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $count = $result['datarecord_count'];
                $metadata[$dt_id] = $count;
            }
        }

        if ( count($can_view_public_datarecords) > 0 ) {
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id, COUNT(dr.id) AS datarecord_count
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataRecord AS dr WITH dr.dataType = dt
                JOIN ODRAdminBundle:DataRecordMeta AS drm WITH drm.dataRecord = dr
                WHERE dt IN (:datatype_ids) AND drm.publicDate != :public_date AND dr.provisioned = FALSE
                AND dt.deletedAt IS NULL AND dr.deletedAt IS NULL AND drm.deletedAt IS NULL
                GROUP BY dt.id'
            )->setParameters(
                array(
                    'datatype_ids' => $can_view_public_datarecords,
                    'public_date' => '2200-01-01 00:00:00'
                )
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $dt_id = $result['dt_id'];
                $count = $result['datarecord_count'];
                $metadata[$dt_id] = $count;
            }
        }

        return $metadata;
    }


    /**
     * Starts the create database wizard and loads master templates available for creation
     * from templates.
     *
     * @param bool $create_master - Create a master template (true/false). Only allows custom type creation when true.
     * @param Request $request
     *
     * @return Response
     */
    public function createAction($create_master, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            if ( $user === 'anon.' )
                throw new ODRForbiddenException();
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // Master Templates must have Dataset Properties/metadata
            $query = $em->createQuery(
               'SELECT dt, dtm, md, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.metadata_datatype AS md
                LEFT JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                AND md.id IS NOT NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters(array('datatypes' => $top_level_datatypes));
            $master_templates = $query->getArrayResult();


            // Render and return the html
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:create_type_choose_template.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'master_templates' => $master_templates,
                        'create_master' => $create_master
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x1bb84021;
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
     * Datatypes without metadata datatypes are given a choice of available templates to create a
     * metadata datatype from.
     *
     * @param $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function choosemetaAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Check if user is datatype admin
            if (!$pm_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------

            if ( !is_null($datatype->getMetadataDatatype()) )
                throw new ODRBadRequestException('This database already has a metadata datatype');
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Metadata datatypes are not allowed to have their own metadata');


            // ----------------------------------------
            // Create master == 0
            $create_master = 0;

            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // Master Templates must have Dataset Properties/metadata
            $query = $em->createQuery(
               'SELECT dt, dtm, md, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.metadata_datatype AS md
                LEFT JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                AND md.id IS NOT NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatypes' => $top_level_datatypes) );
            $master_templates = $query->getArrayResult();

            // TODO - modify this so that you can create a metadata entry that isn't based on a template

            // Render and return the html
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:create_type_choose_template.html.twig',
                    array(
                        'user' => $user,
                        'datatype_permissions' => $datatype_permissions,
                        'master_templates' => $master_templates,
                        'create_master' => $create_master,
                        'datatype_id' => $datatype_id
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0x72002e34;
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
     * Starts the create database wizard and loads master templates available for creation
     * from templates.
     *
     * @param integer $template_choice
     * @param integer $creating_master_template
     * @param Request $request
     *
     * @return Response
     */
    public function createinfoAction($template_choice, $creating_master_template, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( $user === 'anon.' )
                throw new ODRForbiddenException();

            $create_master = false;
            if ($creating_master_template > 0)
                $create_master = true;

            if ($template_choice != 0 && $create_master)
                throw new ODRBadRequestException('Currently unable to copy a new Master Template from an existing Master Template');

            // If cloning from master and master has metadata...
            if($template_choice > 0) {
                // Immediately creates templates and databases.
                return self::direct_add_datatype($template_choice);
            }
            else {
                // Build a form for creating a new datatype, if needed
                $new_datatype_data = new DataTypeMeta();
                $params = array(
                    'form_settings' => array(
                        'is_master_type' => $creating_master_template,
                        'master_type_id' => $template_choice,
                    )
                );
                $form = $this->createForm(CreateDatatypeForm::class, $new_datatype_data, $params);

                // Grab a list of top top-level datatypes
                $top_level_datatypes = $dti_service->getTopLevelDatatypes();

                // TODO - why does this not exclude metadata datatypes while the one in createAction() does?
                // Get the master templates
                $query = $em->createQuery(
                    'SELECT dt, dtm, dt_cb, dt_ub
                    FROM ODRAdminBundle:DataType AS dt
                    JOIN dt.dataTypeMeta AS dtm
                    JOIN dt.createdBy AS dt_cb
                    JOIN dt.updatedBy AS dt_ub
                    WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
                )->setParameters(array('datatypes' => $top_level_datatypes));
                $master_templates = $query->getArrayResult();

                // Render and return the html
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODRAdminBundle:Datatype:create_type_database_info.html.twig',
                        array(
                            'user' => $user,
                            'form' => $form->createView(),

                            // required for the wizard  TODO - why aren't these all empty or 0?
                            // TODO - The wizard is used in multiple places.  Options are set to tell it how to display.
                            'master_templates' => $master_templates,
                            'master_type_id' => $template_choice,
                            'create_master' => $create_master,
                        )
                    )
                );

            }
        }
        catch (\Exception $e) {
            $source = 0xeaff78ff;
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
     * Creates a metadata dataype for the given datatype, based off the selected template.
     *
     * @param $template_choice
     * @param $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function addmetadataAction($template_choice, $datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Check if user is datatype admin
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            if ( !is_null($datatype->getMetadataDatatype()) )
                throw new ODRBadRequestException('This database already has a metadata datatype');
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Metadata datatypes are not allowed to have their own metadata');


            return self::direct_add_datatype($template_choice, $datatype_id);

        }
        catch (\Exception $e) {
            $source = 0x7123adfe;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }


    /**
     * Adds a new database from a master template where the master template
     * has an associated properties/metadata template.
     *
     * Also creates initial properties record and forwards user to
     * properties page as next step in creation sequence.
     *
     * @param $master_datatype_id
     * @param int $datatype_id
     * @param null $admin
     * @param bool $bypass_queue
     * @return RedirectResponse
     */
    public function direct_add_datatype($master_datatype_id, $datatype_id = 0, $admin = null, $bypass_queue = false)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = array();

        try {
            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var ODRUser $admin */
            if($admin === null) {
                $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            }

            /** @var DatatypeCreateService $dtc_service */
            $dtc_service = $this->container->get('odr.datatype_create_service');
            $datatype = $dtc_service->direct_add_datatype(
                $master_datatype_id,
                $datatype_id,
                $admin,
                $bypass_queue,
                $this->get('pheanstalk'),
                $this->container->getParameter('memcached_key_prefix'),
                $this->container->getParameter('beanstalk_api_key')

            );

            // Forward to database properties page.
            $url = $this->generateUrl(
                'odr_datatype_properties',
                array(
                    'datatype_id' => $datatype->getId(),
                    'wizard' => 1
                ),
                false
            );
            $redirect = $this->redirect($url);

            return $redirect;
        }
        catch (\Exception $e) {
            $source = 0xf5ea6e9a;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source), $e);
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }
    }

    /**
     * Creates a new top-level DataType.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function addAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = array();

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');


            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Create new DataType form
            $submitted_data = new DataTypeMeta();
            $form = $this->createForm(CreateDatatypeForm::class, $submitted_data);

            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                // This was a POST request

                // Can't seem to figure out why it occassionally attempts to create an empty datatype, so...guessing here
                if ($form->isEmpty())
                    $form->addError( new FormError('Form is empty?') );

                $short_name = trim($submitted_data->getShortName());
                // Set Long Name equal to Short Name
                // DEPRECATED => Long Name
                $long_name = $short_name;
                if ($short_name == '' || $long_name == '')
                    $form->addError( new FormError('New databases require a database name') );

                if ( strlen($short_name) > 32)
                    $form->addError( new FormError('Shortname has a maximum length of 32 characters') );    // underlying database column only permits 32 characters

                if ($form['is_master_type']->getData() > 0 && $form['master_type_id']->getData() > 0)
                    $form->addError( new FormError('Currently unable to copy a new Master Template from an existing Master Template') );

                if ($form->isValid()) {
                    // ----------------------------------------
                    // TODO - convert to use EntityCreationService?
                    // Create a new Datatype entity
                    $datatype = new DataType();
                    $datatype->setRevision(0);

                    $unique_id = $uuid_service->generateDatatypeUniqueId();
                    $datatype->setUniqueId($unique_id);
                    $datatype->setTemplateGroup($unique_id);

                    // Top-level datatypes exist in one of two states...in the "initial" state, they
                    //  shouldn't be viewed by users because they're lacking themes and permissions
                    // Once they have those, then they should be put into the "operational" state
                    $datatype->setSetupStep(DataType::STATE_INITIAL);

                    // Is this a Master Type?
                    $datatype->setIsMasterType(false);
                    if ($form['is_master_type']->getData() > 0)
                        $datatype->setIsMasterType(true);

                    // If the user decided to create this datatype from a master template...
                    if ($form['master_type_id']->getData() > 0) {
                        // ...locate the master template datatype and store that it's the "source" for this new datatype
                        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
                        /** @var DataType $master_datatype */
                        $master_datatype = $repo_datatype->find($form['master_type_id']->getData());
                        if ($master_datatype == null)
                            throw new ODRNotFoundException('Master Datatype');

                        $datatype->setMasterDataType($master_datatype);
                    }

                    $datatype->setCreatedBy($admin);
                    $datatype->setUpdatedBy($admin);

                    // Save all changes made
                    $em->persist($datatype);
                    $em->flush();
                    $em->refresh($datatype);

                    // Top level datatypes are their own parent/grandparent
                    $datatype->setParent($datatype);
                    $datatype->setGrandparent($datatype);
                    $em->persist($datatype);

                    // Fill out the rest of the metadata properties for this datatype...don't need to set short/long name since they're already from the form
                    $submitted_data->setDataType($datatype);
                    $submitted_data->setLongName($short_name);

                    /** @var RenderPlugin $default_render_plugin */
                    $default_render_plugin = $em->getRepository('ODRAdminBundle:RenderPlugin')->findOneBy( array('pluginClassName' => 'odr_plugins.base.default') );
                    $submitted_data->setRenderPlugin($default_render_plugin);

                    if ($submitted_data->getDescription() == null)
                        $submitted_data->setDescription('');

                    // Default search slug to Dataset ID
                    $submitted_data->setSearchSlug($datatype->getUniqueId());
                    $submitted_data->setXmlShortName('');

                    // Master Template Metadata
                    // Once a child database is completely created from the master template, the creation process will update the revisions appropriately.
                    $submitted_data->setMasterRevision(0);
                    $submitted_data->setMasterPublishedRevision(0);
                    $submitted_data->setTrackingMasterRevision(0);

                    if ($form['is_master_type']->getData() > 0) {
                        // Master Templates must increment revision so that data fields can reference the "to be published" revision.
                        // Whenever an update is made to any data field the revision should be updated.
                        // Master Published revision should only be updated when curators "publish" the latest revisions through the publication dialog.
                        $submitted_data->setMasterPublishedRevision(0);
                        $submitted_data->setMasterRevision(1);
                    }

                    $submitted_data->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

                    $submitted_data->setNewRecordsArePublic(false);    // newly created datarecords default to not-public

                    $submitted_data->setExternalIdField(null);
                    $submitted_data->setNameField(null);
                    $submitted_data->setSortField(null);
                    $submitted_data->setBackgroundImageField(null);

                    $submitted_data->setCreatedBy($admin);
                    $submitted_data->setUpdatedBy($admin);
                    $em->persist($submitted_data);

                    // Ensure the "in-memory" version of the new datatype knows about its meta entry
                    $datatype->addDataTypeMetum($submitted_data);
                    $em->flush();


                    $datatypes_to_process = array();
                    array_push($datatypes_to_process, $datatype);

                    /*
                     * Create Datatype Metadata Object (a second datatype to store one record with the properties
                     * for the parent datatype).
                     */
                    // If is_master_type - automatically create master_type_metadata and set metadata_for_id
                    if ($datatype->getIsMasterType() && $form['is_master_type']->getData() == 1) {
                        $metadata_datatype = clone $datatype;

                        // Set this to be metadata for new datatype
                        $metadata_datatype->setParent($metadata_datatype);
                        $metadata_datatype->setGrandparent($metadata_datatype);

                        $unique_id = $uuid_service->generateDatatypeUniqueId();
                        $metadata_datatype->setUniqueId($unique_id);
                        // Should already have the correct template_group

                        // Set new datatype meta
                        $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                        $metadata_datatype_meta->setShortName($metadata_datatype_meta->getShortName() . " Properties");
                        $metadata_datatype_meta->setLongName($metadata_datatype_meta->getLongName() . " Properties");
                        $metadata_datatype_meta->setDataType($metadata_datatype);

                        // Associate the metadata
                        $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);
                        $metadata_datatype->setMetadataFor($datatype);

                        // New Datatype
                        $em->persist($metadata_datatype);
                        // New Datatype Meta
                        $em->persist($metadata_datatype_meta);

                        // Write to db
                        $em->flush();

                        // Set Metadata Datatype for Datatype
                        $datatype->setMetadataDatatype($metadata_datatype);
                        $em->persist($datatype);

                        // Set search slug
                        $metadata_datatype_meta->setSearchSlug($metadata_datatype->getUniqueId());
                        $em->persist($metadata_datatype_meta);
                        $em->flush();

                        array_push($datatypes_to_process, $metadata_datatype);
                    }
                    else if($datatype->getMasterDataType()) {
                        // If is non-master datatype, clone master-related metadata type
                        $master_datatype = $datatype->getMasterDataType();
                        $master_metadata = $master_datatype->getMetadataDatatype();

                        $unique_id = $uuid_service->generateDatatypeUniqueId();
                        $master_metadata->setUniqueId($unique_id);
                        // Should already have the correct template_group

                        if ($master_metadata != null) {
                            $metadata_datatype = clone $master_metadata;
                            // Unset is master type
                            $metadata_datatype->setIsMasterType(0);
                            $metadata_datatype->setGrandparent($metadata_datatype);
                            $metadata_datatype->setParent($metadata_datatype);
                            $metadata_datatype->setMasterDataType($master_metadata);
                            // Clone has wrong state - set to initial
                            $metadata_datatype->setSetupStep(DataType::STATE_INITIAL);

                            $unique_id = $uuid_service->generateDatatypeUniqueId();
                            $metadata_datatype->setUniqueId($unique_id);
                            // Should already have the correct template_group

                            // Set new datatype meta
                            $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                            $metadata_datatype_meta->setShortName($datatype->getDataTypeMeta()->getShortName() . " Properties");
                            $metadata_datatype_meta->setLongName($datatype->getDataTypeMeta()->getLongName() . " Properties");
                            $metadata_datatype_meta->setDataType($metadata_datatype);

                            // Associate the metadata
                            $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);

                            // New Datatype
                            $em->persist($metadata_datatype);
                            // New Datatype Meta
                            $em->persist($metadata_datatype_meta);
                            // Set Metadata Datatype
                            $datatype->setMetadataDatatype($metadata_datatype);
                            $em->persist($datatype);
                            $em->flush();

                            array_push($datatypes_to_process, $metadata_datatype);

                        }
                    }
                    /*
                     * END Create Datatype Metadata Object
                     */


                    /*
                     * Clone theme or create theme as needed for new datatype(s)
                     */
                    foreach ($datatypes_to_process as $datatype) {
                        // ----------------------------------------
                        // If the datatype is being created from a master template...
                        if ($datatype->getMasterDatatype()) {
                            // Start the job to create the datatype from the template
                            $pheanstalk = $this->get('pheanstalk');
                            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                            $api_key = $this->container->getParameter('beanstalk_api_key');

                            // Insert the new job into the queue
                            $priority = 1024;   // should be roughly default priority
                            $payload = json_encode(
                                array(
                                    "user_id" => $admin->getId(),
                                    "datatype_id" => $datatype->getId(),

                                    "redis_prefix" => $redis_prefix,    // debug purposes only
                                    "api_key" => $api_key,
                                )
                            );

                            $delay = 0;
                            $pheanstalk->useTube('create_datatype')->put($payload, $priority, $delay);
                        }
                        else {
                            // ...otherwise, this is a new custom datatype

                            // Create a default master theme for it
                            $master_theme = $ec_service->createTheme($admin, $datatype, true);    // Don't flush immediately...

                            $master_theme_meta = $master_theme->getThemeMeta();
                            $master_theme_meta->setIsDefault(true);
                            $master_theme_meta->setShared(true);
                            $em->persist($master_theme_meta);

                            // Create a default search results theme for it too...
                            $search_theme = $ec_service->createTheme($admin, $datatype, true);    // Don't flush immediately...
                            $search_theme->setThemeType('search_results');
                            $search_theme->setSourceTheme($master_theme);
                            $em->persist($search_theme);

                            $search_theme_meta = $search_theme->getThemeMeta();
                            $search_theme_meta->setIsDefault(true);
                            $search_theme_meta->setShared(true);
                            $em->persist($search_theme_meta);

                            // Now flush the new theme stuff
                            $em->flush();


                            // Delete the cached version of the datatree array and the list of top-level datatypes
                            $cache_service->delete('cached_datatree_array');
                            $cache_service->delete('top_level_datatypes');
                            $cache_service->delete('top_level_themes');


                            // Create the groups for the new datatype here so the datatype can be viewed
                            $ec_service->createGroupsForDatatype($admin, $datatype);

                            // Ensure the user who created this datatype becomes a member of the new
                            //  datatype's "is_datatype_admin" group
                            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') ) {
                                /** @var Group $admin_group */
                                $admin_group = $em->getRepository('ODRAdminBundle:Group')->findOneBy(
                                    array(
                                        'dataType' => $datatype->getId(),
                                        'purpose' => 'admin'
                                    )
                                );
                                $ec_service->createUserGroup($admin, $admin_group, $admin);

                                // Delete cached version of this user's permissions
                                $cache_service->delete('user_'.$admin->getId().'_permissions');
                            }

                            // This dataype is now fully created
                            $datatype->setSetupStep(DataType::STATE_OPERATIONAL);
                            $em->persist($datatype);
                            $em->flush();
                        }
                    }
                    /*
                     * END Clone theme or create theme as needed for new datatype(s)
                     */


                    // Note: Since the system will always create metadata database second, this redirect will push
                    // the user edit their metadata template first as it is the last thing set to $datatype above.
                    // Perhaps this should be more explicitly chosen.
                    // TODO - This is not good.  A long copy above may not be finished by the time the time the user arrives at design system.
                    $url = $this->generateUrl('odr_design_master_theme', array('datatype_id' => $datatype->getId()), false);
                    $return['d']['redirect_url'] = $url;
                }
                else {
                    // Return any errors encountered
                    $error_str = parent::ODR_getErrorMessages($form);
                    throw new ODRException($error_str);
                }
            }
            else {
                // Otherwise, this was a GET request
                $templating = $this->get('templating');
                $return['d'] = $templating->render(
                    'ODRAdminBundle:Datatype:create_datatype_info_form.html.twig',
                    array(
                        'form' => $form->createView()
                    )
                );
            }
        }
        catch (\Exception $e) {
            $source = 0x6151265b;
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
     * Creates a new blank metadata datatype for the requested datatype
     *
     * @param int $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function createblankmetaAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            if ( !is_null($datatype->getMetadataDatatype()) )
                throw new ODRBadRequestException('This database already has a metadata datatype');
            if ( !is_null($datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Metadata datatypes are not allowed to have their own metadata');


            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Create a new metadata datatype
            $new_metadata_datatype = $ec_service->createDatatype($admin, $datatype->getShortName()." Properties", true);    // don't flush immediately...
            $new_metadata_datatype->setTemplateGroup($datatype->getTemplateGroup());
            $new_metadata_datatype->setIsMasterType($datatype->getIsMasterType());
            $new_metadata_datatype->setMetadataFor($datatype);
            $em->persist($new_metadata_datatype);

            // Create a default master theme for the new metadata datatype
            $master_theme = $ec_service->createTheme($admin, $new_metadata_datatype, true);    // Don't flush immediately...

            $master_theme_meta = $master_theme->getThemeMeta();
            $master_theme_meta->setIsDefault(true);
            $master_theme_meta->setShared(true);
            $em->persist($master_theme_meta);

            // Need to flush so createGroupsForDatatype() works
            $em->flush();

            // Delete the cached version of the datatree array and the list of top-level datatypes
            $cache_service->delete('cached_datatree_array');
            $cache_service->delete('top_level_datatypes');
            $cache_service->delete('top_level_themes');

            // Create the groups for the new datatype here so the datatype can be viewed
            $ec_service->createGroupsForDatatype($admin, $new_metadata_datatype);

            // Ensure the user who created this datatype becomes a member of the new
            //  datatype's "is_datatype_admin" group
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') ) {
                /** @var Group $admin_group */
                $admin_group = $em->getRepository('ODRAdminBundle:Group')->findOneBy(
                    array(
                        'dataType' => $datatype->getId(),
                        'purpose' => 'admin'
                    )
                );
                $ec_service->createUserGroup($admin, $admin_group, $admin);

                // Delete cached version of this user's permissions
                $cache_service->delete('user_'.$admin->getId().'_permissions');
            }


            // Ensure the datatype/template points to the new metadata datatype
            $datatype->setMetadataDatatype($new_metadata_datatype);
            $em->persist($datatype);
            $new_metadata_datatype->setSetupStep(DataType::STATE_OPERATIONAL);
            $em->persist($new_metadata_datatype);

            $em->flush();
        }
        catch (\Exception $e) {
            $source = 0x40a08257;
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
     * Renders and returns a list of databases that can be copied into a new database.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function listcopydatabasesAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $templating = $this->get('templating');

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var CsrfTokenManager $token_generator */
            $token_generator = $this->get('security.csrf.token_manager');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');


            // ----------------------------------------
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Need to locate all users currently in ODR...
            $user_list = $user_manager->findUsers();    // twig filters out deleted users

            // Order by name so it's easier to locate people
            usort($user_list, function($a, $b) {
                /** @var ODRUser $a */
                /** @var ODRUser $b */
                return strcasecmp($a->getUserString(), $b->getUserString());
            });

            // Also need to load all top-level datatypes that are not templates or metadata...
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            $query = $em->createQuery(
               'SELECT dt, dtm, dt_cb,
                        master_dt, meta_dt, meta_master_dt
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.createdBy AS dt_cb

                LEFT JOIN dt.masterDataType AS master_dt
                LEFT JOIN dt.metadata_datatype AS meta_dt
                LEFT JOIN meta_dt.masterDataType AS meta_master_dt

                WHERE dt.id IN (:datatypes) AND dt.is_master_type = :is_master_type
                AND dt.unique_id = dt.template_group
                AND dt.setup_step IN (:setup_steps) AND dt.metadata_for IS NULL
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datatypes' => $top_level_datatypes,
                    'is_master_type' => false,
                    'setup_steps' => DataType::STATE_VIEWABLE
                )
            );
            $results = $query->getArrayResult();

            // Flatten the returned array slightly
            $datatypes = array();
            foreach ($results as $result) {
                $dt_id = $result['id'];

                $datatypes[$dt_id] = $result;
                $datatypes[$dt_id]['dataTypeMeta'] = $result['dataTypeMeta'][0];
                $datatypes[$dt_id]['createdBy'] = UserUtility::cleanUserData($result['createdBy']);
            }

            // Sort the datatypes by name so they're easier to locate...
            uasort($datatypes, function ($a, $b) {
                return strnatcasecmp($a['dataTypeMeta']['shortName'], $b['dataTypeMeta']['shortName']);
            });


            // ----------------------------------------
            // Generate a CSRF token from the combined data
            $csrf_token = $token_generator->getToken('CopyDatatypeForm_'.$admin->getId())->getValue();

            // Render and return the html for the datatype list
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Datatype:type_list_copy_databases.html.twig',
                    array(
                        'admin' => $admin,

                        'user_list' => $user_list,
                        'datatypes' => $datatypes,
                        'csrf_token' => $csrf_token,
                    )
                )
            );

        }
        catch (\Exception $e) {
            $source = 0x5f3c0ce1;
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
     * Copies a non-template database.  The new database does not reference the original...datatypes
     * and datafields don't reference the original database via template_uuids, and radio/tags have
     * brand-new uuids.
     *
     * As such, copying a database that is itself derived from a template is probably not a good idea.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function copynormaldatabaseAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            $post = $request->request->all();

            // Need to have 'odr_tab_id', 'datatype_id', and either 'datafields' or 'public_status'
            // Otherwise, throw an exception
            if ( !isset($post['_token']) || !isset($post['datatype_id']) || !isset($post['user_id']) )
                throw new ODRBadRequestException();

            $datatype_id = $post['datatype_id'];
            $user_id = $post['user_id'];
            $token = $post['_token'];


            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CloneMasterDatatypeService $cmd_service */
            $cmd_service = $this->container->get('odr.clone_master_datatype_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var CsrfTokenManager $token_generator */
            $token_generator = $this->get('security.csrf.token_manager');
            /** @var UserManager $user_manager */
            $user_manager = $this->container->get('fos_user.user_manager');


            /** @var DataType $source_datatype */
            $source_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( is_null($source_datatype) )
                throw new ODRNotFoundException('Datatype');

            if ( !is_null($source_datatype->getMetadataFor()) )
                throw new ODRBadRequestException('Not allowed to copy Metadata datatypes with this');
            if ( $source_datatype->getIsMasterType() )
                throw new ODRBadRequestException('Not allowed to copy master templates with this');
            if ( $source_datatype->getGrandparent()->getId() !== $source_datatype->getId() )
                throw new ODRBadRequestException('Not allowed to copy child datatypes with this');
            if ( $source_datatype->getSetupStep() !== DataType::STATE_OPERATIONAL )
                throw new ODRBadRequestException('Not allowed to copy a non-operational datatype');

            /** @var ODRUser $user */
            $user = $user_manager->findUserBy( array('id' => $user_id) );
            if ( is_null($user) )
                throw new ODRNotFoundException('User');


            // ----------------------------------------
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // Only allow super admins to do this...
            if ( !$admin->hasRole('ROLE_SUPER_ADMIN') )
                throw new ODRForbiddenException();
            // ----------------------------------------

            $expected_token = $token_generator->getToken('CopyDatatypeForm_'.$admin->getId())->getValue();
            if ( $token !== $expected_token )
                throw new ODRBadRequestException();


            // ----------------------------------------
            // Nothing created during the copy (datatypes, datafields, options/tags, etc) should
            //  retain any connection to the "master template" datatype afterwards
            $clone_from_template = false;


            // Create the skeletal datatype that will be copied into
            $new_dt = $ec_service->createDatatype($user, 'Copy of '.$source_datatype->getShortName(), true);    // don't flush immediately...
            // The new datatype needs to treat the source datatype as its "master template" in order
            //  for the copying process to work...
            $new_dt->setMasterDataType($source_datatype);
            $em->persist($new_dt);

            // If the datatype being copied has a metadata datatype...
            $new_metadata_dt = null;
            if ( !is_null($source_datatype->getMetadataDatatype()) ) {
                // ...then might as well copy that too
                $new_metadata_dt = $ec_service->createDatatype($user, $source_datatype->getMetadataDatatype()->getShortName(), true);    // don't flush immediately...
                $new_metadata_dt->setMasterDataType($source_datatype->getMetadataDatatype());

                // Need to ensure the skeletal datatype knows about its metadata datatype
                $new_dt->setMetadataDatatype($new_metadata_dt);
                $em->persist($new_dt);
                $new_metadata_dt->setMetadataFor($new_dt);
                $em->persist($new_metadata_dt);
            }

            // Need to flush before copying takes place
            $em->flush();

            // If a metadata datatype needs to be copied...
            if ( !is_null($new_metadata_dt) ) {
                // ...then the original datatype needs to be flushed first so it has a uuid, so
                //  the new metadata datatype can be added to the correct template group
                $new_metadata_dt->setTemplateGroup($new_dt->getUniqueId());
                $em->persist($new_metadata_dt);
                $em->flush();
            }


            // ----------------------------------------
            // Copying takes long enough that a background job is needed for this...
//            $cmd_service->createDatatypeFromMaster(
//                $new_dt->getId(),
//                $admin->getId(),
//                $new_dt->getUniqueId(),
//                $clone_from_template
//            );

            $pheanstalk = $this->get('pheanstalk');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');
            $priority = 1024;   // should be roughly default priority
            $delay = 0;

            // If the datatype being copied has a metadata datatype...
            if ( !is_null($source_datatype->getMetadataDatatype()) ) {
                // ...then ensure it gets copied first to avoid it get cached incorrectly
                $payload = json_encode(
                    array(
                        "user_id" => $user->getId(),
                        "datatype_id" => $new_metadata_dt->getId(),
                        "template_group" => $new_dt->getUniqueId(),
                        "preserve_uuids" => $clone_from_template,

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "api_key" => $api_key,
                    )
                );
                $pheanstalk->useTube('create_datatype_from_master')->put($payload, $priority, $delay);
            }

            // Copy the desired datatype
            $payload = json_encode(
                array(
                    "user_id" => $user->getId(),
                    "datatype_id" => $new_dt->getId(),
                    "template_group" => $new_dt->getUniqueId(),
                    "preserve_uuids" => $clone_from_template,

                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "api_key" => $api_key,
                )
            );
            $pheanstalk->useTube('create_datatype_from_master')->put($payload, $priority, $delay);


            // ----------------------------------------
            // Redirect the user to what will be the new datatype's landing page
            $url = $this->generateUrl('odr_datatype_landing', array('datatype_id' => $new_dt->getId()), false);
            $return['d'] = array('redirect_url' => $url);

        }
        catch (\Exception $e) {
            $source = 0xb16e5336;
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
