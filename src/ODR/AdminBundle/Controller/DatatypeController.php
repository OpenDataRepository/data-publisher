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
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\UUIDService;
// Symfony
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;


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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO -
     *
     * @param $datatype_unique_id
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

            $use_search_slug = false;
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(array('unique_id' => $datatype_unique_id));
            if ($datatype == null) {
                // Attempt find by slug
                /** @var DataTypeMeta $datatype_meta */
                $datatype_meta = $em->getRepository('ODRAdminBundle:DataTypeMeta')
                    ->findOneBy(array('searchSlug' => $datatype_unique_id));

                $use_search_slug = true;
                $datatype = $datatype_meta->getDataType();

                if($datatype->getTemplateGroup() == null) {
                    // Not a valid database for dashboard access.
                    throw new ODRNotFoundException('Datatype');
                }
            }

            if ($datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataType $landing_datatype */
            $landing_datatype = $em->getRepository('ODRAdminBundle:DataType')->findOneBy(array('unique_id' => $datatype->getTemplateGroup()));

            if($use_search_slug) {
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

            $url = $this->generateUrl('odr_datatype_landing', array('datatype_id' => $landing_datatype->getId()), false);

            $return['d'] = $url_prefix . "#" . $url;
        }
        catch (\Exception $e) {
            $source = 0x22b8dae6;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * TODO -
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

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
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
            if ($datatype->getSetupStep() == DataType::STATE_INITIAL && $datatype->getMasterDataType() != null) {
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
                if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                    throw new ODRForbiddenException();

                $datatype_permissions = $pm_service->getDatatypePermissions($user);


                // ----------------------------------------
                // TODO - should this also be pulling linked datatypes?  cause right now that'll only work when they're created via templates...
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
                    WHERE dt.template_group LIKE :template_group AND dt.setup_step IN (:setup_steps)
                    AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND gp.deletedAt IS NULL'
                )->setParameters(
                    array(
                        'template_group' => $datatype->getTemplateGroup(),
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

                    $dt['associated_datatypes'] = $dti_service->getAssociatedDatatypes(array($dt_id));
                    $datatypes[$dt_id] = $dt;
                }


                // ----------------------------------------
                // Determine how many datarecords the user has the ability to view for each datatype
                $datatype_ids = array_keys($datatypes);
                $related_metadata = self::getDatarecordCounts($em, $datatype_ids, $datatype_permissions);


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
                        'related_metadata' => $related_metadata
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Builds and returns a list of the actions a user can perform to each top-level DataType.
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
                AND dtm.deletedAt IS NULL';

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

                $dt['associated_datatypes'] = $dti_service->getAssociatedDatatypes(array($dt_id));
                $datatypes[$dt_id] = $dt;
            }


            // ----------------------------------------
            // Determine how many datarecords the user has the ability to view for each datatype
            $datatype_ids = array_keys($datatypes);
            $metadata = self::getDatarecordCounts($em, $datatype_ids, $datatype_permissions);

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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
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

            // TODO - relax this restriction?
            if ( !$user->hasRole('ROLE_ADMIN') )
                throw new ODRForbiddenException();
            // --------------------


            // Grab a list of top top-level datatypes
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();

            // TODO - won't display templates unless they have a metadata datatype...intentional?
            // TODO - Yes.  This is intentional.  All new databases must have rudimentary metadata going forward.
            // TODO - The "generic" database master template will be updated to include a name/person default metadata template.
            // Master Templates must have Dataset Properties/metadata
            $query = $em->createQuery(
/*
                // TODO - disabled until users can create metadata for datatypes/templates without any
               'SELECT dt, dtm, md, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.metadata_datatype AS md
                LEFT JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                AND md.id IS NOT NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
*/
               'SELECT dt, dtm, dt_cb, dt_ub
                FROM ODRAdminBundle:DataType AS dt
                LEFT JOIN dt.dataTypeMeta AS dtm
                LEFT JOIN dt.createdBy AS dt_cb
                LEFT JOIN dt.updatedBy AS dt_ub
                WHERE dt.id IN (:datatypes) AND dt.is_master_type = 1
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            if ( !is_null($datatype->getMetadataDatatype()) )
                throw new ODRBadRequestException('This database already has a metadata datatype');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = $pm_service->getDatatypePermissions($user);

            // Check if user is datatype admin
            if (!$pm_service->isDatatypeAdmin($user, $datatype))
                throw new ODRForbiddenException();
            // --------------------


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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            if ( !$user->hasRole('ROLE_ADMIN') )
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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

            if ( !is_null($datatype->getMetadataDatatype()) )
                throw new ODRBadRequestException('This database already has a metadata datatype');


            // --------------------
            // Grab user privileges to determine what they can do
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Check if user is datatype admin
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            return self::direct_add_datatype($template_choice, $datatype_id);

        }
        catch (\Exception $e) {
            $source = 0x7123adfe;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
     *
     * @return RedirectResponse
     */
    public function direct_add_datatype($master_datatype_id, $datatype_id = 0)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = array();

        try {
            // TODO - modify ODRAdminBundle:Datatype:create_type_choose_template.html.twig so a "blank" metadata datatype can be created?
            // TODO - currently, any creation of a metadata datatype *MUST* come from a template...

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var UUIDService $uuid_service */
            $uuid_service = $this->container->get('odr.uuid_service');


            // Don't need to verify permissions, firewall won't let this action be called unless user is admin
            /** @var ODRUser $admin */
            $admin = $this->container->get('security.token_storage')->getToken()->getUser();

            // A master datatype is required
            // ...locate the master template datatype and store that it's the "source" for this new datatype
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            /** @var DataType $master_datatype */
            $master_datatype = $repo_datatype->find($master_datatype_id);
            if ($master_datatype == null)
                throw new ODRNotFoundException('Master Datatype');

            // Create new DataType form
            $datatypes_to_process = array();
            $datatype = null;
            $unique_id = null;
            $clone_and_link = false;
            if ($datatype_id > 0) {
                /** @var DataType $datatype */
                $datatype = $repo_datatype->find($datatype_id);
                $master_metadata = $master_datatype->getMetadataDatatype();

                $unique_id = $datatype->getUniqueId();
                $clone_and_link = true;
            }
            else {
                // Create a new datatype
                $datatype = $ec_service->createDatatype($admin, 'New Dataset', true);    // delay flush to change a few properties

                // This datatype is derived from a master datatype
                $datatype->setMasterDataType($master_datatype);
                $em->persist($datatype);

                // Also need to change the search slug from the default value
                $datatype_meta = $datatype->getDataTypeMeta();
                $datatype_meta->setSearchSlug( $datatype->getUniqueId() );
                $em->persist($datatype_meta);

                $em->flush();

                array_push($datatypes_to_process, $datatype);

                // If is non-master datatype, clone master-related metadata type
                $master_datatype = $datatype->getMasterDataType();
                $master_metadata = $master_datatype->getMetadataDatatype();
            }

            /*
             * Create Datatype Metadata Object (a second datatype to store one record with the properties
             * for the parent datatype).
             */

            if ($master_metadata != null) {
                $metadata_datatype = clone $master_metadata;
                // Unset is master type
                $metadata_datatype->setIsMasterType(0);
                $metadata_datatype->setGrandparent($metadata_datatype);
                $metadata_datatype->setParent($metadata_datatype);
                $metadata_datatype->setMasterDataType($master_metadata);
                // Set template group to that of datatype
                $metadata_datatype->setTemplateGroup($unique_id);
                // Clone has wrong state - set to initial
                $metadata_datatype->setSetupStep(DataType::STATE_INITIAL);

                // Need to always set a unique id
                $metadata_unique_id = $uuid_service->generateDatatypeUniqueId();
                $metadata_datatype->setUniqueId($metadata_unique_id);

                // Set new datatype meta
                $metadata_datatype_meta = clone $datatype->getDataTypeMeta();
                $metadata_datatype_meta->setShortName("Properties");
                $metadata_datatype_meta->setLongName($datatype->getDataTypeMeta()->getLongName() . " - Properties");
                $metadata_datatype_meta->setDataType($metadata_datatype);

                // Associate the metadata
                $metadata_datatype->addDataTypeMetum($metadata_datatype_meta);
                $metadata_datatype->setMetadataFor($datatype);

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
            /*
             * END Create Datatype Metadata Object
             */



            /*
             * Clone theme or create theme as needed for new datatype(s)
             */
            // Determine which is parent
            /** @var DataType $datatype */
            foreach ($datatypes_to_process as $datatype) {
                // ----------------------------------------
                // If the datatype is being created from a master template...
                // Start the job to create the datatype from the template
                $pheanstalk = $this->get('pheanstalk');
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');
                $api_key = $this->container->getParameter('beanstalk_api_key');

                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $params = array(
                    "user_id" => $admin->getId(),
                    "datatype_id" => $datatype->getId(),
                    "template_group" => $unique_id,
                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "api_key" => $api_key,
                );

                $params["clone_and_link"] = false;
                if ($clone_and_link)
                    $params["clone_and_link"] = true;

                $payload = json_encode($params);

                $pheanstalk->useTube('create_datatype_from_master')->put($payload, $priority, 0);

            }
            /*
             * END Clone theme or create theme as needed for new datatype(s)
             */


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
            $source = 0xa54e875c;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
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
                    $submitted_data->setSearchSlug($datatype->getId());
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
                        $metadata_datatype_meta->setSearchSlug($metadata_datatype->getId());
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
                throw new ODRException($e->getMessage(), $e->getStatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
