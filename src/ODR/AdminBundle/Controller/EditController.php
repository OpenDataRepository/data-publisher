<?php

/**
 * Open Data Repository Data Publisher
 * Edit Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller handles everything required to edit any kind of
 * data stored in a DataRecord.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\OpenRepository\GraphBundle\Plugins\GraphPluginInterface;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRConflictException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Forms
use ODR\AdminBundle\Form\BooleanForm;
use ODR\AdminBundle\Form\DatetimeValueForm;
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
use ODR\AdminBundle\Form\LongTextForm;
use ODR\AdminBundle\Form\LongVarcharForm;
use ODR\AdminBundle\Form\MediumVarcharForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\CryptoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRRenderService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchRedirectService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\FormError;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;


class EditController extends ODRCustomController
{

    /**
     * Creates a new top-level DataRecord.
     *
     * @param integer $datatype_id The database id of the DataType this DataRecord will belong to.
     * @param Request $request
     *
     * @return Response
     */
    public function adddatarecordAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            // Adding a top-level datarecord is different than adding a child datarecord, and the
            //  database could get messed up if the wrong controller action is used
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( !in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::adddatarecordAction() called for child datatype');


            // If this datatype is a "master template"...
            if ($datatype->getIsMasterType()) {
                // ...then don't create another datarecord if the datatype already has one
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id
                    AND dr.deletedAt IS NULL'
                )->setParameters( array('datatype_id' => $datatype->getId()) );
                $results = $query->getArrayResult();

                if ( count($results) !== 0 )
                    throw new ODRBadRequestException('This Master Template already has a sample datarecord');
            }

            // Create a new top-level datarecord
            $datarecord = $entity_create_service->createDatarecord($user, $datatype, true);    // don't flush immediately...

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);

            $em->persist($datarecord);
            $em->flush();


            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'datarecord_id' => $datarecord->getId()
            );


            // ----------------------------------------
            // Delete the cached string containing the ordered list of datarecords for this datatype
            $dti_service->resetDatatypeSortOrder($datatype->getId());
            // Delete all search results that can change
            $search_cache_service->onDatarecordCreate($datatype);

            // Since this is a new top-level datarecord, there's nothing to mark as updated
        }
        catch (\Exception $e) {
            $source = 0x2d4d92e6;
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
     * Creates a new DataRecord and sets it as a child of the given DataRecord.
     *
     * @param integer $datatype_id    The database id of the child DataType this new child DataRecord will belong to.
     * @param integer $parent_id      The database id of the DataRecord...
     * @param Request $request
     *
     * @return Response
     */
    public function addchildrecordAction($datatype_id, $parent_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $entity_create_service */
            $entity_create_service = $this->container->get('odr.entity_creation_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            // Grab needed Entities from the repository
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ( $datatype == null )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('DataRecord');

            $grandparent_datarecord = $parent_datarecord->getGrandparent();
            if ($grandparent_datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Grandparent DataRecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canAddDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine whether this is a request to add a datarecord for a top-level datatype or not
            // Adding a child datarecord is different than adding a top-level datarecord, and the
            //  database could get messed up if the wrong controller action is used
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            if ( in_array($datatype_id, $top_level_datatypes) )
                throw new ODRBadRequestException('EditController::addchildrecordAction() called for top-level datatype');

            // Create a new top-level datarecord...
            $datarecord = $entity_create_service->createDatarecord($user, $datatype, true);    // don't flush immediately...

            // Set parent/grandparent properties so this becomes a child datarecord
            $datarecord->setGrandparent($grandparent_datarecord);
            $datarecord->setParent($parent_datarecord);

            // Datarecord is ready, remove provisioned flag
            $datarecord->setProvisioned(false);

            $em->persist($datarecord);
            $em->flush();

            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'new_datarecord_id' => $datarecord->getId(),    // TODO - this isn't used due to asynch call to reloadChild() in edit_ajax.html.twig
                'datatype_id' => $datatype_id,
                'parent_id' => $parent_datarecord->getId(),
            );

            // Delete all search results that can change
            $search_cache_service->onDatarecordCreate($datatype);

            // Delete the cached string containing the ordered list of datarecords for this datatype
            $dti_service->resetDatatypeSortOrder($datatype->getId());

            // Refresh the cache entries for the new datarecord's parent
            $dri_service->updateDatarecordCacheEntry($parent_datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x3d2835d5;
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
     * Deletes a Datarecord and all the related entities that also need to be deleted.
     *
     * If top-level, a URL to the search results list for the datatype is built and returned.
     *
     * If a child datarecord, or a linked datarecord being viewed from a linked ancestor, then the
     * information to reload the theme_element is returned instead.
     *
     * @param integer $datarecord_id The database id of the datarecord being deleted
     * @param bool $is_link
     * @param string $search_key
     * @param Request $request
     *
     * @return Response
     */
    public function deletedatarecordAction($datarecord_id, $is_link, $search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $conn = null;

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var ThemeInfoService $theme_info_service */
            $theme_info_service = $this->container->get('odr.theme_info_service');


            // Grab the necessary entities
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('DataRecord');

            $parent_datarecord = $datarecord->getParent();
            if ($parent_datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Parent Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canDeleteDatarecord($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------

            // Store whether this was a deletion for a top-level datarecord or not
            $is_top_level = true;
            if ( $datatype->getId() !== $parent_datarecord->getDataType()->getId() )
                $is_top_level = false;

            // Also store whether this was for a linked datarecord or not
            if ( $is_link === '0' )
                $is_link = false;
            else
                $is_link = true;


            // ----------------------------------------
            // Recursively locate all children of this datarecord
            $parent_ids = array();
            $parent_ids[] = $datarecord->getId();

            $datarecords_to_delete = array();
            $datarecords_to_delete[] = $datarecord->getId();

            while ( count($parent_ids) > 0 ) {
                // Can't use the grandparent datarecord property, because this deletion request
                //  could be for a datarecord that isn't top-level
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS parent
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr.parent = parent
                    WHERE dr.id != parent.id AND parent.id IN (:parent_ids)
                    AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
                )->setParameters( array('parent_ids' => $parent_ids) );
                $results = $query->getArrayResult();

                $parent_ids = array();
                foreach ($results as $result) {
                    $dr_id = $result['dr_id'];

                    $parent_ids[] = $dr_id;
                    $datarecords_to_delete[] = $dr_id;
                }
            }
//print '<pre>'.print_r($datarecords_to_delete, true).'</pre>';  exit();

            // Locate all datarecords that link to any of the datarecords that will be deleted...
            //  they will need to have their cache entries rebuilt
            $query = $em->createQuery(
               'SELECT DISTINCT(gp.id) AS ancestor_id
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                JOIN ODRAdminBundle:DataRecord AS gp WITH ancestor.grandparent = gp
                WHERE ldt.descendant IN (:datarecord_ids)
                AND ldt.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND gp.deletedAt IS NULL'
            )->setParameters( array('datarecord_ids' => $datarecords_to_delete) );
            $results = $query->getArrayResult();

            $ancestor_datarecord_ids = array();
            foreach ($results as $result)
                $ancestor_datarecord_ids[] = $result['ancestor_id'];
//print '<pre>'.print_r($ancestor_datarecord_ids, true).'</pre>';  exit();

            // ----------------------------------------
            // Since this needs to make updates to multiple tables, use a transaction
            $conn = $em->getConnection();
            $conn->beginTransaction();

            // TODO - delete datarecordfield entries as well?

            // ...delete all linked_datatree entries that reference these datarecords
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:LinkedDataTree AS ldt
                SET ldt.deletedAt = :now, ldt.deletedBy = :deleted_by
                WHERE (ldt.ancestor IN (:datarecord_ids) OR ldt.descendant IN (:datarecord_ids))
                AND ldt.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete each meta entry for the datarecords to be deleted
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecordMeta AS drm
                SET drm.deletedAt = :now
                WHERE drm.dataRecord IN (:datarecord_ids)
                AND drm.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // ...delete all of the datarecords
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:DataRecord AS dr
                SET dr.deletedAt = :now, dr.deletedBy = :deleted_by
                WHERE dr.id IN (:datarecord_ids)
                AND dr.deletedAt IS NULL'
            )->setParameters(
                array(
                    'now' => new \DateTime(),
                    'deleted_by' => $user->getId(),
                    'datarecord_ids' => $datarecords_to_delete
                )
            );
            $rows = $query->execute();

            // No error encountered, commit changes
//$conn->rollBack();
            $conn->commit();

            // -----------------------------------
            // Mark this now-deleted datarecord's parent (and all its parents) as updated unless
            //  it was already a top-level datarecord
            if ( !$is_top_level )
                $dri_service->updateDatarecordCacheEntry($parent_datarecord, $user);

            // Delete other search cache entries affected by this
            $search_cache_service->onDatarecordDelete($datatype);


            // ----------------------------------------
            // The proper return value depends on whether this was a top-level datarecord or not
            if ($is_top_level && !$is_link) {
                // Determine whether any datarecords of this datatype remain
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
                )->setParameters(array('datatype' => $datatype->getId()));
                $remaining = $query->getArrayResult();

                // Determine where to redirect since the current datareccord is now deleted
                $url = '';
                if ($search_key == '') {
                    $search_key = $search_key_service->encodeSearchKey(
                        array(
                            'dt_id' => $datatype->getId()
                        )
                    );
                }

                if ( count($remaining) > 0 ) {
                    // Return to the list of datarecords since at least one datarecord of this datatype still exists
                    $preferred_theme_id = $theme_info_service->getPreferredTheme($user, $datatype->getId(), 'search_results');
                    $url = $this->generateUrl(
                        'odr_search_render',
                        array(
                            'search_theme_id' => $preferred_theme_id,
                            'search_key' => $search_key
                        )
                    );
                }
                else {
                    // ...otherwise, return to the list of datatypes
                    $url = $this->generateUrl('odr_list_types', array('section' => 'databases'));
                }

                $return['d'] = $url;
            }
            else {
                // This is either a child datarecord, or a request to delete a datarecord from a
                //  parent datarecord that links to it

                // Get record_ajax.html.twig to re-render the datarecord
                $return['d'] = array(
                    'datatype_id' => $datatype->getId(),
                    'parent_id' => $parent_datarecord->getId(),
                );
            }
        }
        catch (\Exception $e) {
            // Don't commit changes if any error was encountered...
            if ( !is_null($conn) && $conn->isTransactionActive() )
                $conn->rollBack();

            $source = 0x82bb1bb6;
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
     * Deletes a user-uploaded file from the database.
     *
     * @param integer $file_id The database id of the File to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deletefileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------

            // Delete the decrypted version of this file from the server, if it exists
            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
            $filename = 'File_'.$file_id.'.'.$file->getExt();
            $absolute_path = realpath($file_upload_path).'/'.$filename;

            if ( file_exists($absolute_path) )
                unlink($absolute_path);

            // Save who deleted the file
            $file->setDeletedBy($user);
            $em->persist($file);
            $em->flush($file);

            // Delete the file and its current metadata entry
            $file_meta = $file->getFileMeta();
            $em->remove($file);
            $em->remove($file_meta);
            $em->flush();


            // -----------------------------------
            // Mark this file's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);


            // -----------------------------------
            // Need to locate and load any render plugins affecting this datafield to determine
            //  whether one of them is a graph-type plugin...
            $query = $em->createQuery(
               'SELECT rp.pluginClassName
                FROM ODRAdminBundle:RenderPluginMap rpm
                JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpm.renderPluginInstance = rpi
                JOIN ODRAdminBundle:RenderPlugin rp WITH rpi.renderPlugin = rp
                WHERE rpm.dataField = :datafield_id
                AND rpm.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rp.deletedAt IS NULL'
            )->setParameters(
                array(
                    'datafield_id' => $datafield->getId(),
//                    'plugin_type' => RenderPlugin::DATATYPE_PLUGIN    // TODO - should this be required?
                )
            );
            $results = $query->getArrayResult();

            // Currently, there's going to be at most 2 results in here...one of them being a
            //  datatype render plugin that uses this datafield, the other being a datafield render
            //  plugin
            foreach ($results as $result) {
                $plugin_classname = $result['pluginClassName'];
                $plugin = $plugin = $this->get($plugin_classname);

                // If the datafield is being used by a graph-type plugin...
                if ( $plugin instanceof GraphPluginInterface ) {
                    // ...then that graph plugin needs to be notified that a file got deleted, so it
                    //  can delete any cached entries/files it has created based off this file

                    /** @var GraphPluginInterface $plugin */
                    $plugin->onFileChange($datafield, $file_id);
                }
            }


            // -----------------------------------
            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);
        }
        catch (\Exception $e) {
            $source = 0x08e2fe10;
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
     * Toggles the public status of a file.
     *
     * @param integer $file_id The database id of the File to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function publicfileAction($file_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service*/
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab the necessary entities
            /** @var File $file */
            $file = $em->getRepository('ODRAdminBundle:File')->find($file_id);
            if ($file == null)
                throw new ODRNotFoundException('File');

            $datafield = $file->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $file->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Files that aren't done encrypting shouldn't be modified
            if ($file->getProvisioned() == true)
                throw new ODRNotFoundException('File');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - should a new permission be added to control this...potentially one related to changing public status of datarecords?
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle public status of specified file...
            $public_date = null;
            if ( $file->isPublic() ) {
                // Make the file non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                $emm_service->updateFileMeta($user, $file, $properties);

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

                if ( file_exists($absolute_path) )
                    unlink($absolute_path);
            }
            else {
                // Make the file public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                $emm_service->updateFileMeta($user, $file, $properties);


                // ----------------------------------------
                // Need to decrypt the file...generate the url for cURL to use
                $redis_prefix = $this->container->getParameter('memcached_key_prefix');    // debug purposes only

                $pheanstalk = $this->get('pheanstalk');
                $router = $this->container->get('router');
                $url = $this->container->getParameter('site_baseurl');
                $url .= $router->generate('odr_crypto_request');

                $api_key = $this->container->getParameter('beanstalk_api_key');
                $file_decryptions = $cache_service->get('file_decryptions');

                // Determine the filename after decryption
                $target_filename = 'File_'.$file_id.'.'.$file->getExt();
                if ( !isset($file_decryptions[$target_filename]) ) {
                    // File is not scheduled to get decrypted at the moment, store that it will be decrypted
                    $file_decryptions[$target_filename] = 1;
                    $cache_service->set('file_decryptions', $file_decryptions);

                    // Schedule a beanstalk job to start decrypting the file
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "object_type" => 'File',
                            "object_id" => $file_id,
                            "target_filename" => $target_filename,
                            "crypto_type" => 'decrypt',

                            "archive_filepath" => '',
                            "desired_filename" => '',

                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 0;
                    $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
                }

                /* otherwise, decryption already in progress, do nothing */
            }

            // Reload the file entity so its associated meta entry gets updated in the EntityManager
            $em->refresh($file);

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $file->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // ----------------------------------------
            // Mark this file's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // TODO - execute graph plugin?  currently not needed because the graph plugin auto-decrypts files it needs to render a graph...
        }
        catch (\Exception $e) {
            $source = 0x5201b0cd;
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
     * Toggles the public status of an image.
     *
     * @param integer $image_id The database id of the Image to modify
     * @param Request $request
     *
     * @return Response
     */
    public function publicimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be downloaded
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');

            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // TODO - should a new permission be added to control this...potentially one related to changing public status of datarecords?
            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Grab all children of the original image (resizes, i believe)
            /** @var Image[] $all_images */
            $all_images = $repo_image->findBy( array('parent' => $image->getId()) );
            $all_images[] = $image;

            // Toggle public status of specified image...
            $public_date = null;

            if ( $image->isPublic() ) {
                // Make the original image non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date );
                $emm_service->updateImageMeta($user, $image, $properties);

                // Delete the decrypted version of the image and all of its children, if any of them exist
                foreach ($all_images as $img) {
                    $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                    $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                    $absolute_path = realpath($image_upload_path).'/'.$filename;

                    if ( file_exists($absolute_path) )
                        unlink($absolute_path);
                }
            }
            else {
                // Make the original image public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                $emm_service->updateImageMeta($user, $image, $properties);

                // Immediately decrypt the image and all of its children...don't need to specify
                //  a filename because the images are guaranteed to be public
                foreach ($all_images as $img)
                    $crypto_service->decryptImage($img->getId());
            }


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'is_public' => $image->isPublic(),
                'public_date' => $public_date->format('Y-m-d'),
            );


            // ----------------------------------------
            // Mark this image's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // TODO - cached search results
        }
        catch (\Exception $e) {
            $source = 0xf051d2f4;
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
     * Deletes a user-uploaded image from the repository.
     *
     * @param integer $image_id The database id of the Image to delete.
     * @param Request $request
     *
     * @return Response
     */
    public function deleteimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');
            $datafield_id = $datafield->getId();

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Grab all alternate sizes of the original image (thumbnail is only current one) and remove them
            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the alternate sized image from the database
                $em->remove($img);
            }

            // Ensure no decrypted version of the original image exists on the server
            $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
            if ( file_exists($local_filepath) )
                unlink($local_filepath);

            // Save who deleted the image
            $image->setDeletedBy($user);
            $em->persist($image);
            $em->flush($image);

            // Delete the original image and its associated meta entry as well
            $image_meta = $image->getImageMeta();
            $em->remove($image);
            $em->remove($image_meta);
            $em->flush();


            // If this datafield only allows a single upload, tell record_ajax.html.twig to refresh that datafield so the upload button shows up
            if ($datafield->getAllowMultipleUploads() == "0")
                $return['d'] = array('need_reload' => true);


            // ----------------------------------------
            // Mark this image's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);
        }
        catch (\Exception $e) {
            $source = 0xee8e8649;
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
     * Rotates a given image by 90 degrees (counter)clockwise, and saves it
     *
     * @param integer $image_id The database id of the Image to delete.
     * @param integer $direction -1 for 90 degrees counter-clockwise rotation, 1 for 90 degrees clockwise rotation
     * @param Request $request
     *
     * @return Response
     */
    public function rotateimageAction($image_id, $direction, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            /** @var CryptoService $crypto_service */
            $crypto_service = $this->container->get('odr.crypto_service');
            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab the necessary entities
            /** @var Image $image */
            $image = $repo_image->find($image_id);
            if ($image == null)
                throw new ODRNotFoundException('Image');

            $datafield = $image->getDataField();
            if ($datafield->getDeletedAt() != null)
                throw new ODRNotFoundException('Datafield');

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            // Images that aren't done encrypting shouldn't be modified
            if ($image->getOriginalChecksum() == '')
                throw new ODRNotFoundException('Image');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Determine how long it's been since the creation of this image...
            $create_date = $image->getCreated();
            $current_date = new \DateTime();
            $interval = $create_date->diff($current_date);

            // TODO - duration in which image can be rotated without creating new entry?
            // TODO - change to use parent::createNewMetaEntry() ?
            // Replace existing image if it has existed on the server for less than 30 minutes
            $replace_existing = false;
            if ($interval->days == 0 && $interval->h == 0 && $interval->i <= 30)
                $replace_existing = true;


            // ----------------------------------------
            // Image is going to be rotated, so its contents will change...clear the original
            //  checksum for the original image and its thumbnails
            $image_path = $crypto_service->decryptImage($image_id);
            if ($replace_existing) {
                $image->setOriginalChecksum('');    // checksum will be updated after rotation
                $em->persist($image);
            }

            /** @var Image[] $images */
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img) {
                // Ensure no decrypted version of any of the thumbnails exist on the server
                $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$img->getId().'.'.$img->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                if ($replace_existing) {
                    $img->setOriginalChecksum('');    // checksum will be replaced after rotation
                    $em->persist($img);
                }
            }

            if ($replace_existing)
                $em->flush();


            // ----------------------------------------
            // If not replacing existing image, have image rotation write back to the same file
            $dest_path = $image_path;
            if (!$replace_existing) {
                // ...otherwise, determine the path to the user's upload folder

                // The image rotation function will save the rotated image there so it can be
                //  "uploaded again"...this is the easiest way to ensure everything neccessary exists
                $dest_path = $this->getParameter('odr_web_directory').'/uploads/files';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/chunks';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/user_'.$user->getId();
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );
                $dest_path .= '/completed';
                if ( !file_exists($dest_path) )
                    mkdir( $dest_path );

                $dest_path.= '/'.$image->getOriginalFileName();
            }

            // Rotate and save image back to server...apparently a positive number means
            //  counter-clockwise rotation with imagerotate()
            $degrees = 90;
            if ($direction == 1)
                $degrees = -90;

            $im = null;
            switch ( strtolower($image->getExt()) ) {
                case 'gif':
                    $im = imagecreatefromgif($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagegif($im, $dest_path);
                    break;
                case 'png':
                    $im = imagecreatefrompng($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagepng($im, $dest_path);
                    break;
                case 'jpg':
                case 'jpeg':
                    $im = imagecreatefromjpeg($image_path);
                    $im = imagerotate($im, $degrees, 0);
                    imagejpeg($im, $dest_path);
                    break;
            }
            imagedestroy($im);


            // ----------------------------------------
            if ($replace_existing) {
                // Update the image's height/width as stored in the database
                $sizes = getimagesize($image_path);
                $image->setImageWidth($sizes[0]);
                $image->setImageHeight($sizes[1]);
                // Create thumbnails and other sizes/versions of the uploaded image
                self::resizeImages($image, $user);

                // Encrypt parent image AFTER thumbnails are created
                self::encryptObject($image_id, 'image');

                // Set original checksum for original image
                $filepath = $crypto_service->decryptImage($image_id);
                $original_checksum = md5_file($filepath);
                $image->setOriginalChecksum($original_checksum);

                // A decrypted version of the Image still exists on the server...delete it
                unlink($filepath);

                // Save changes again
                $em->persist($image);
                $em->flush();
            }
            else {
                // "Upload" the "new" rotated image
                $filepath = 'uploads/files/chunks/user_'.$user->getId().'/completed';
                $original_filename = $image->getOriginalFileName();

                $new_image = parent::finishUpload($em, $filepath, $original_filename, $user->getId(), $image->getDataRecordFields()->getId());

                // Copy any metadata from the old image over to the new image
                $old_image_meta = $image->getImageMeta();
                $properties = array(
                    'caption' => $old_image_meta->getCaption(),
                    'original_filename' => $old_image_meta->getOriginalFileName(),
                    'external_id' => $old_image_meta->getExternalId(),
                    'publicDate' => $old_image_meta->getPublicDate(),
                    'display_order' => $old_image_meta->getDisplayorder()
                );
                $emm_service->updateImageMeta($user, $new_image, $properties);


                // Ensure no decrypted version of the original image exists on the server
                $local_filepath = $this->getParameter('odr_web_directory').'/uploads/images/Image_'.$image->getId().'.'.$image->getExt();
                if ( file_exists($local_filepath) )
                    unlink($local_filepath);

                // Delete the original image and its metadata entry
                $em->remove($image);
                $em->remove($old_image_meta);

                // Delete any thumbnails of the original image
                foreach ($images as $img)
                    $em->remove($img);

                $em->flush();
            }


            // Mark this image's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x4093b173;
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
     * Modifies the display order of the images in an Image control.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function saveimageorderAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $request->request->all();
//print_r($post);  exit();

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            // Grab the first image just to check permissions
            $image = null;
            foreach ($post as $index => $image_id) {
                $image = $em->getRepository('ODRAdminBundle:Image')->find($image_id);
                break;
            }
            /** @var Image $image */

            $datafield = $image->getDataField();
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datarecord = $image->getDataRecord();
            if ($datarecord->getDeletedAt() != null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Ensure that the provided image ids are all from the same datarecordfield, and that
            //  all images from that datarecordfield are listed in the post
            $query = $em->createQuery(
               'SELECT e
                FROM ODRAdminBundle:Image AS e
                WHERE e.dataRecordFields = :drf AND e.original = 1
                AND e.deletedAt IS NULL'
            )->setParameters( array('drf' => $image->getDataRecordFields()->getId()) );
            $results = $query->getResult();

            $all_images = array();
            foreach ($results as $image)
                $all_images[ $image->getId() ] = $image;
            /** @var Image[] $all_images */

            // Throw exceptions if the post request doesn't match the expected image list
            if ( count($post) !== count($all_images) ) {
                throw new ODRBadRequestException('wrong number of images');
            }
            else {
                foreach ($post as $index => $image_id) {
                    if ( !isset($all_images[$image_id]) )
                        throw new ODRBadRequestException('Invalid Image Id');
                }
            }


            // Update the image order based on the post request if required
            $changes_made = false;
            foreach ($post as $index => $image_id) {
                $image = $all_images[$image_id];

                if ( $image->getDisplayorder() != $index ) {
                    $properties = array('display_order' => $index);
                    $emm_service->updateImageMeta($user, $image, $properties, true);    // don't flush immediately...
                    $changes_made = true;
                }
            }

            if ($changes_made)
                $em->flush();


            // Mark the image's datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);
        }
        catch (\Exception $e) {
            $source = 0x8b01c7e4;
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
     * Toggles the public status of a DataRecord.
     *
     * @param integer $datarecord_id The database id of the DataRecord to modify.
     * @param Request $request
     *
     * @return Response
     */
    public function publicdatarecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            // Ensure user has permissions to be doing this
            // TODO - create a new permission specifically for changing public status of datarecords?
            if ( !$pm_service->isDatatypeAdmin($user, $datatype) )
                throw new ODRForbiddenException();
            // --------------------


            // Toggle the public status of the datarecord
            if ( $datarecord->isPublic() ) {
                // Make the datarecord non-public
                $public_date = new \DateTime('2200-01-01 00:00:00');

                $properties = array('publicDate' => $public_date);
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);
            }
            else {
                // Make the datarecord non-public
                $public_date = new \DateTime();

                $properties = array('publicDate' => $public_date);
                $emm_service->updateDatarecordMeta($user, $datarecord, $properties);
            }


            // ----------------------------------------
            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete cached search results involving this datarecord
            $search_cache_service->onDatarecordPublicStatusChange($datarecord);


            // ----------------------------------------
            $return['d'] = array(
                'public' => $datarecord->isPublic(),
                'datarecord_id' => $datarecord_id,
            );
        }
        catch (\Exception $e) {
            $source = 0x3df683c4;
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
     * Handles selection changes made to SingleRadio, MultipleRadio, SingleSelect, and MultipleSelect DataFields
     *
     * @param integer $datarecord_id    The database id of the Datarecord being modified
     * @param integer $datafield_id     The database id of the Datafield being modified
     * @param integer $radio_option_id  The database id of the RadioOption entity being (de)selected.  If 0, then no RadioOption should be selected.
     * @param Request $request
     *
     * @return Response
     */
    public function radioselectionAction($datarecord_id, $datafield_id, $radio_option_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();

            /** @var RadioOptions $radio_option */
            $radio_option = null;
            if ($radio_option_id != 0) {
                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->find($radio_option_id);
                if ($radio_option == null)
                    throw new ODRNotFoundException('RadioOption');
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Locate the existing datarecordfield entry, or create one if it doesn't exist
            $drf = $ec_service->createDatarecordField($user, $datarecord, $datafield);

            // Course of action differs based on whether multiple selections are allowed
            $typename = $datafield->getFieldType()->getTypeName();

            // A RadioOption id of 0 has no effect on a Multiple Radio/Select datafield
            if ( $radio_option_id != 0 && ($typename == 'Multiple Radio' || $typename == 'Multiple Select') ) {
                // Don't care about selected status of other RadioSelection entities...
                $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                // Default to a value of 'selected' if an older RadioSelection entity does not exist
                $new_value = 1;
                if ($radio_selection !== null) {
                    // An older version does exist...toggle the existing value for the new value
                    if ($radio_selection->getSelected() == 1)
                        $new_value = 0;
                }

                // Update the RadioSelection entity to match $new_value
                $properties = array('selected' => $new_value);
                $emm_service->updateRadioSelection($user, $radio_selection, $properties);
            }
            else if ($typename == 'Single Radio' || $typename == 'Single Select') {
                // Probably need to change selected status of at least one other RadioSelection entity...
                /** @var RadioSelection[] $radio_selections */
                $radio_selections = $repo_radio_selection->findBy(
                    array(
                        'dataRecordFields' => $drf->getId()
                    )
                );

                foreach ($radio_selections as $rs) {
                    if ( $radio_option_id != $rs->getRadioOption()->getId() ) {
                        if ($rs->getSelected() == 1) {
                            // Deselect all RadioOptions that are selected and are not the one the user wants to be selected
                            $properties = array('selected' => 0);
                            $emm_service->updateRadioSelection($user, $rs, $properties, true);    // should only be one, technically...
                        }
                    }
                }

                // If the user selected something other than "<no option selected>"...
                if ($radio_option_id != 0) {
                    // ...locate the RadioSelection entity the user wanted to set to selected
                    $radio_selection = $ec_service->createRadioSelection($user, $radio_option, $drf);

                    // ...ensure it's selected
                    $properties = array('selected' => 1);
                    $emm_service->updateRadioSelection($user, $radio_selection, $properties, true);    // flushing doesn't help...
                }

                // Flush now that all the changes have been made
                $em->flush();
            }
            else {
                // No point doing anything if not a radio fieldtype
                throw new ODRBadRequestException('RecordController::radioselectionAction() called on Datafield that is not a Radio FieldType');
            }


            // ----------------------------------------
            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // Delete any cached search results involving this datafield
            $search_cache_service->onDatafieldModify($datafield);

        }
        catch (\Exception $e) {
            $source = 0x01019cfb;
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
     * Parses a $_POST request to update the contents of a datafield.
     * File and Image uploads are handled by @see FlowController
     * Changes to RadioSelections are handled by RecordController::radioselectionAction()
     *
     * @param integer $datarecord_id  The datarecord of the storage entity being modified
     * @param integer $datafield_id   The datafield of the storage entity being modified
     * @param Request $request
     *
     * @return Response
     */
    public function updateAction($datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get the Entity Manager
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var EntityCreationService $ec_service */
            $ec_service = $this->container->get('odr.entity_creation_service');
            /** @var EntityMetaModifyService $emm_service */
            $emm_service = $this->container->get('odr.entity_meta_modify_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var SearchCacheService $search_cache_service */
            $search_cache_service = $this->container->get('odr.search_cache_service');


            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Determine class of form needed
            $typeclass = $datafield->getFieldType()->getTypeClass();
            $form_object = null;
            $form_class = null;
            switch ($typeclass) {
                case 'Boolean':
                    $form_class = BooleanForm::class;
                    $form_object = new Boolean();
                    break;
                case 'DatetimeValue':
                    $form_class = DatetimeValueForm::class;
                    $form_object = new DatetimeValue();
                    break;
                case 'DecimalValue':
                    $form_class = DecimalValueForm::class;
                    $form_object = new DecimalValue();
                    break;
                case 'IntegerValue':
                    $form_class = IntegerValueForm::class;
                    $form_object = new IntegerValue();
                    break;
                case 'LongText':    // paragraph text
                    $form_class = LongTextForm::class;
                    $form_object = new LongText();
                    break;
                case 'LongVarchar':
                    $form_class = LongVarcharForm::class;
                    $form_object = new LongVarchar();
                    break;
                case 'MediumVarchar':
                    $form_class = MediumVarcharForm::class;
                    $form_object = new MediumVarchar();
                    break;
                case 'ShortVarchar':
                    $form_class = ShortVarcharForm::class;
                    $form_object = new ShortVarchar();
                    break;

                default:
                    // Radio and Tag fieldtypes aren't supposed to be updated here ever
                    // Files/Images might be permissible in the future
                    throw new ODRBadRequestException('RecordController::updateAction() called for a Datafield using the '.$typeclass.' FieldType');
                    break;
            }

            // Load the existing storage entity if it exists, or create a new one if it doesn't
            $storage_entity = $ec_service->createStorageEntity($user, $datarecord, $datafield);
            $old_value = $storage_entity->getValue();


            // ----------------------------------------
            // Create a new form for this storage entity and bind it to the request
            $form = $this->createForm($form_class, $form_object, array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()));
            $form->handleRequest($request);

            if ($form->isSubmitted()) {

                if ($form->isValid()) {
                    $new_value = $form_object->getValue();

                    if ($old_value !== $new_value) {

                        // If the datafield is marked as unique...
                        if ($datafield->getIsUnique() == true) {
                            // ...determine whether the new value is a duplicate of a value that already exists
                            $found_existing_value = self::findExistingValue($em, $datafield, $datarecord->getParent()->getId(), $new_value);
                            if ($found_existing_value)
                                throw new ODRConflictException('Another Datarecord already has the value "'.$new_value.'" stored in this Datafield...reverting back to old value.');
                        }

                        // ----------------------------------------
                        // If saving to a datetime field, ensure it's a datetime object?
                        if ($typeclass == 'DatetimeValue') {
                            if ($new_value == '')
                                $new_value = new \DateTime('9999-12-31 00:00:00');
                            else
                                $new_value = new \DateTime($new_value);
                        }
                        else if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue') {
                            // DecimalValue::setValue() already does its own thing, and parent::ODR_copyStorageEntity() will set $new_value back to NULL for an IntegerValue
                            $new_value = strval($new_value);
                        }
                        else if ($typeclass == 'ShortVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'LongVarchar' || $typeclass == 'LongText') {
                            // if array($key => NULL), then isset($property[$key]) returns false...change $new_value to the empty string instead
                            // The text fields should store the empty string instead of NULL anyways
                            if ( is_null($new_value) )
                                $new_value = '';
                        }


                        // Save the value
                        $emm_service->updateStorageEntity($user, $storage_entity, array('value' => $new_value));


                        // ----------------------------------------
                        // Mark this datarecord as updated
                        $dri_service->updateDatarecordCacheEntry($datarecord, $user);

                        // If the datafield that got changed was the datatype's sort datafield, delete the cached datarecord order
                        if ( $datatype->getSortField() != null && $datatype->getSortField()->getId() == $datafield->getId() )
                            $dti_service->resetDatatypeSortOrder($datatype->getId());

                        // Delete any cached search results involving this datafield
                        $search_cache_service->onDatafieldModify($datafield);
                    }
                }
                else {
                    // Form validation failed
                    $error_str = parent::ODR_getErrorMessages($form);
                    throw new ODRException($error_str);
                }
            }
        }
        catch (\Exception $e) {
            $source = 0x294a59c5;
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
     * Returns whether the provided value would violate uniqueness constraints for the given datafield.
     * TODO - does this make more sense in a searching service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Datafields $datafield
     * @param integer $parent_datarecord_id
     * @param mixed $new_value
     *
     * @return bool
     */
    private function findExistingValue($em, $datafield, $parent_datarecord_id, $new_value)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        // Going to need these...
        $datatype_id = $datafield->getDataType()->getId();
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Determine if this datafield belongs to a top-level datatype or not
        $is_child_datatype = false;
        $datatree_array = $dti_service->getDatatreeArray();
        if ( isset($datatree_array['descendant_of'][$datatype_id]) && $datatree_array['descendant_of'][$datatype_id] !== '' )
            $is_child_datatype = true;

        // Mysql requires a different comparision if checking for duplicates of a null value...
        $comparision = $parameters = null;
        if ($new_value != null) {
            $comparision = 'e.value = :value';
            $parameters = array('datafield' => $datafield->getId(), 'value' => $new_value);
        }
        else {
            $comparision = '(e.value IS NULL OR e.value = :value)';
            $parameters = array('datafield' => $datafield->getId(), 'value' => '');
        }

        // Also search on parent datarecord id if it was passed in
        if ($is_child_datatype)
            $parameters['parent_datarecord_id'] = $parent_datarecord_id;

        if (!$is_child_datatype) {
            $query = $em->createQuery(
               'SELECT e.value
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                WHERE e.dataField = :datafield AND '.$comparision.'
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // See if the given value already exists in this datafield...mysql comparisions ignore
            //  case, so have to do it this way
            foreach ($results as $result) {
                $value = $result['value'];
                if ($value === $new_value)
                    return true;
            }
        }
        else {
            $query = $em->createQuery(
               'SELECT e.value
                FROM ODRAdminBundle:'.$typeclass.' AS e
                JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
                JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
                JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
                WHERE e.dataField = :datafield AND '.$comparision.' AND parent.id = :parent_datarecord_id
                AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL'
            )->setParameters($parameters);
            $results = $query->getArrayResult();

            // See if the given value already exists in this datafield...mysql comparisions ignore
            //  case, so have to do it this way
            foreach ($results as $result) {
                $value = $result['value'];
                if ($value === $new_value)
                    return true;
            }
        }

        // The given value does not exist in this datafield
        return false;
    }


    /**
     * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
     *
     * @param integer $child_datatype_id     The database id of the child DataType to re-render
     * @param integer $parent_datarecord_id  The database id of the parent DataRecord
     * @param Request $request
     *
     * @return Response
     */
    public function reloadchildAction($child_datatype_id, $parent_datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Don't actually need these for a child reload, but the parameters are expected
            $search_theme_id = '';
            $search_key = '';

            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataType $child_datatype */
            $child_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($child_datatype_id);
            if ($child_datatype == null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $parent_datarecord */
            $parent_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($parent_datarecord_id);
            if ($parent_datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            $parent_datatype = $parent_datarecord->getDataType();
            if ($parent_datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Parent Datatype');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatarecord($user, $parent_datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            $return['d'] = array(
                'html' => self::GetDisplayData($search_theme_id, $search_key, $parent_datarecord_id, 'child', $child_datatype_id, $request),
            );
        }
        catch (\Exception $e) {
            $source = 0xb61ecefa;
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
     * Given a datarecord and datafield, re-render and return the html for that datafield.
     *
     * @param integer $source_datarecord_id The id of the top-level Datarecord the edit page is currently displaying
     * @param integer $datarecord_id The id of the Datarecord being re-rendered
     * @param integer $datafield_id  The id of the Datafield inside $datarecord_id to re-render
     * @param Request $request
     *
     * @return Response
     */
    public function reloaddatafieldAction($source_datarecord_id, $datarecord_id, $datafield_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ThemeInfoService $ti_service */
            $ti_service = $this->container->get('odr.theme_info_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ( !is_null($datatype->getDeletedAt()) )
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            if ($datarecord->getDataType()->getId() !== $datatype->getId())
                throw new ODRBadRequestException();


            /** @var Datarecord $source_datarecord */
            $source_datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($source_datarecord_id);
            if ($source_datarecord == null)
                throw new ODRNotFoundException('Source Datarecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // Need to locate the theme element being reloaded...
            $master_theme = $ti_service->getDatatypeMasterTheme($datatype->getId());
            $query = $em->createQuery(
               'SELECT te
                FROM ODRAdminBundle:Theme AS t
                JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
                JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
                WHERE t.id = :theme_id AND tdf.dataField = :datafield_id
                AND t.deletedAt IS NULL AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL'
            )->setParameters(
                array(
                    'theme_id' => $master_theme->getId(),
                    'datafield_id' => $datafield->getId()
                )
            );
            $result = $query->getResult();
            /** @var ThemeElement $theme_element */
            $theme_element = $result[0];

            $return['d'] = array(
                'html' => $odr_render_service->reloadEditDatafield(
                    $user,
                    $source_datarecord->getDataType(),
                    $theme_element,
                    $datafield,
                    $datarecord
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xc28be446;
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
     * Renders the HTML required to edit datafield values for a given record.
     *
     * @param integer $search_theme_id
     * @param string $search_key
     * @param integer $initial_datarecord_id  The datarecord that originally requested this Edit mode render
     * @param string $template_name           One of 'default', 'child_datarecord', or 'datafield'
     * @param integer $target_id              If 'default', then $target_id should be a...TODO
     *                                        If 'child', then $target_id should be a child/linked datatype id
     *                                        if 'datafield', then $target_id should be a datafield id
     * @param Request $request
     *
     * @throws ODRException
     *
     * @return string
     */
    private function GetDisplayData($search_theme_id, $search_key, $initial_datarecord_id, $template_name, $target_id, Request $request)
    {
        // Required objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $em->getRepository('ODRAdminBundle:Theme');


        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');


        // Load all permissions for this user
        /** @var ODRUser $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        $user_permissions = $pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $user_permissions['datatypes'];
        $datafield_permissions = $user_permissions['datafields'];


        // ----------------------------------------
        // Load required objects based on parameters
        $is_top_level = 1;

        /** @var DataRecord $datarecord */
        $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($initial_datarecord_id);
        $grandparent_datarecord = $datarecord->getGrandparent();
        $grandparent_datatype = $grandparent_datarecord->getDataType();

        /** @var Theme $top_level_theme */
        $top_level_theme_id = $theme_service->getPreferredTheme($user, $grandparent_datatype->getId(), 'master');
        $top_level_theme = $repo_theme->find($top_level_theme_id);

        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var DataFields|null $datafield */
        $datafield = null;
        $datafield_id = null;


        // Don't allow a child reload request for a top-level datatype
        if ($template_name == 'child' && $datarecord->getDataType()->getId() == $target_id)
            $template_name = 'default';


        if ($template_name == 'default') {
            $datatype = $grandparent_datatype;
            $theme = $top_level_theme;

//            // TODO - May not necessarily be a render request for a top-level datarecord...
//            $datatype = $datarecord->getDataType();
//            if ( $grandparent_datarecord->getId() !== $datarecord->getId() )
//                $is_top_level = 0;
        }
        else if ($template_name == 'child') {
            $is_top_level = 0;

            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'parentTheme' => $top_level_theme->getId()) );      // TODO - this likely isn't going to work where linked datatypes are involved

            // TODO - ...need to have either the theme_datatype or theme_element this child is in to be able to reload the right one where multiple linked datatypes are involved...
        }
        else if ($template_name == 'datafield') {
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $datafield_id = $target_id;

            $datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy( array('dataType' => $datatype->getId(), 'parentTheme' => $top_level_theme->getId()) );      // TODO - this likely isn't going to work where linked datatypes are involved

            // TODO - ...need to have either the theme_datatype or theme_element this child is in to be able to reload the right one where multiple linked datatypes are involved...
        }


        // ----------------------------------------
        // Grab all datarecords "associated" with the desired datarecord...
        $include_links = true;
        $datarecord_array = $dri_service->getDatarecordArray($grandparent_datarecord->getId(), $include_links);

        // Grab all datatypes associated with the desired datarecord
        $datatype_array = $dti_service->getDatatypeArray($grandparent_datatype->getId(), $include_links);

        // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
        $pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // Also need the theme array...
        $theme_array = $theme_service->getThemeArray($top_level_theme->getId());


        // ----------------------------------------
        // "Inflate" the currently flattened $datarecord_array and $datatype_array...needed so that render plugins for a datatype can also correctly render that datatype's child/linked datatypes
        $stacked_datarecord_array[ $datarecord->getId() ] = $dri_service->stackDatarecordArray($datarecord_array, $datarecord->getId());
        $stacked_datatype_array[ $datatype->getId() ] = $dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
        $stacked_theme_array[ $theme->getId() ] = $theme_service->stackThemeArray($theme_array, $theme->getId());


        // ----------------------------------------
        // Render the requested version of this page
        $templating = $this->get('templating');

        $html = '';
        if ($template_name == 'default') {

            // ----------------------------------------
            // Need to determine ids and names of datatypes this datarecord can link to
            $query = $em->createQuery(
               'SELECT
                  dt, dtm,
                  ancestor, ancestor_meta,
                  descendant, descendant_meta

                FROM ODRAdminBundle:DataTree AS dt
                JOIN dt.dataTreeMeta AS dtm

                JOIN dt.ancestor AS ancestor
                JOIN ancestor.dataTypeMeta AS ancestor_meta
                JOIN dt.descendant AS descendant
                JOIN descendant.dataTypeMeta AS descendant_meta

                WHERE dtm.is_link = 1 AND (ancestor.id = :datatype_id OR descendant.id = :datatype_id)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL
                AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL
                AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL'
            )->setParameters( array('datatype_id' => $datatype->getId()) );
            $results = $query->getArrayResult();
//exit( '<pre>'.print_r($results, true).'</pre>' );


            // Organize the linked datatypes into arrays
            $linked_datatype_ancestors = array();
            $linked_datatype_descendants = array();
            // Also store which datatype can't be linked to because they lack a search_results theme
            $disabled_datatype_links = array();

            foreach ($results as $num => $dt) {
                $ancestor_id = $dt['ancestor']['id'];
                $descendant_id = $dt['descendant']['id'];

                if ($ancestor_id == $datatype->getId() ) {
                    $descendant = $dt['descendant'];
                    $descendant['dataTypeMeta'] = $dt['descendant']['dataTypeMeta'][0];

                    // TODO Fix it so searches always work
                    /*
                    if ( $descendant['setup_step'] == DataType::STATE_OPERATIONAL )
                        $linked_datatype_descendants[$descendant_id] = $descendant;
                    else
                        $disabled_datatype_links[$descendant_id] = $descendant;
                    */

                    $linked_datatype_descendants[$descendant_id] = $descendant;
                }
                else if ($descendant_id == $datatype->getId() ) {
                    $ancestor = $dt['ancestor'];
                    $ancestor['dataTypeMeta'] = $dt['ancestor']['dataTypeMeta'][0];

                    /*
                    if ( $ancestor['setup_step'] == DataType::STATE_OPERATIONAL )
                        $linked_datatype_ancestors[$ancestor_id] = $ancestor;
                    else
                        $disabled_datatype_links[$ancestor_id] = $ancestor;
                    */

                    // TODO Fix it so searches always work
                    $linked_datatype_ancestors[$ancestor_id] = $ancestor;
                }
            }

            // ----------------------------------------
            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = $dri_service->generateCSRFTokens($datatype_array, $datarecord_array);


            // ----------------------------------------
            // Determine whether the currently preferred theme needs to be synchronized with its source
            //  and the user notified of it
            $notify_of_sync = self::notifyOfThemeSync($theme, $user);


            // ----------------------------------------
            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_ajax.html.twig',
                array(
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,

                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_array' => $stacked_theme_array,

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_datarecord_id' => $datarecord->getId(),
                    'initial_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'linked_datatype_ancestors' => $linked_datatype_ancestors,
                    'linked_datatype_descendants' => $linked_datatype_descendants,
                    'disabled_datatype_links' => $disabled_datatype_links,

                    'is_top_level' => $is_top_level,
                    'token_list' => $token_list,

                    'notify_of_sync' => $notify_of_sync,
                )
            );
        }
        else if ($template_name == 'child') {

            // Find the ThemeDatatype entry that contains the child datatype getting reloaded
            $theme_datatype = null;
            foreach ($theme_array as $t_id => $t) {
                foreach ($t['themeElements'] as $te_num => $te) {
                    if ( isset($te['themeDataType']) ) {
                        foreach ($te['themeDataType'] as $tdt_num => $tdt) {
                            if ( $tdt['dataType']['id'] == $datatype->getId() && $tdt['childTheme']['id'] == $theme->getId() )
                                $theme_datatype = $tdt;
                        }
                    }
                }
            }

            if ($theme_datatype == null)
                throw new ODRException('Unable to locate theme_datatype entry for child datatype '.$datatype->getId());

            $is_link = $theme_datatype['is_link'];
            $display_type = $theme_datatype['display_type'];
            $multiple_allowed = $theme_datatype['multiple_allowed'];

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = $dri_service->generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_childtype_reload.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $stacked_datarecord_array,
                    'theme_array' => $stacked_theme_array,

                    'target_datatype_id' => $datatype->getId(),
                    'parent_datarecord_id' => $datarecord->getId(),
                    'target_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'is_top_level' => 0,
                    'is_link' => $is_link,
                    'display_type' => $display_type,
                    'multiple_allowed' => $multiple_allowed,

                    'token_list' => $token_list,
                )
            );
        }
        else if ($template_name == 'datafield') {

            // Extract all needed arrays from $datatype_array and $datarecord_array
            $datatype = $datatype_array[ $datatype->getId() ];
            $datarecord = $datarecord_array[ $initial_datarecord_id ];

            $datafield = null;
            if ( isset($datatype['dataFields'][$datafield_id]) )
                $datafield = $datatype['dataFields'][$datafield_id];

            if ( $datafield == null )
                throw new ODRException('Unable to locate array entry for datafield '.$datafield_id);

            // Generate a csrf token for each of the datarecord/datafield pairs
            $token_list = $dri_service->generateCSRFTokens($datatype_array, $datarecord_array);

            $html = $templating->render(
                'ODRAdminBundle:Edit:edit_datafield.html.twig',
                array(
                    'datatype' => $datatype,
                    'datarecord' => $datarecord,
                    'datafield' => $datafield,

                    'is_link' => $is_link,
                    'force_image_reload' => true,

                    'token_list' => $token_list,
                )
            );
        }

        return $html;
    }


    /**
     * Given a datarecord and datafield, re-render and return the html for files uploaded to that datafield.
     *
     * @param integer $datafield_id  The database id of the DataField inside the DataRecord to re-render.
     * @param integer $datarecord_id The database id of the DataRecord to re-render
     * @param Request $request
     *
     * @return Response
     */
    public function reloadfiledatafieldAction($datafield_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');


            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------

            // Don't run if the datafield isn't a file datafield
            if ( $datafield->getFieldType()->getTypeClass() !== 'File' )
                throw new ODRBadRequestException('Datafield is not of a File Typeclass');


            // Load all files uploaded to this datafield
            $query = $em->createQuery(
               'SELECT f, fm, f_cb
                FROM ODRAdminBundle:File AS f
                JOIN f.fileMeta AS fm
                JOIN f.createdBy AS f_cb
                WHERE f.dataRecord = :datarecord_id AND f.dataField = :datafield_id
                AND f.deletedAt IS NULL AND fm.deletedAt IS NULL'
            )->setParameters( array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $file_list = array();
            foreach ($results as $num => $result) {
                $file = $result;
                $file['fileMeta'] = $result['fileMeta'][0];
                $file['createdBy'] = UserUtility::cleanUserData($result['createdBy']);

                $file_list[$num] = $file;
            }

            // Render and return the HTML for the list of files
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:edit_file_datafield.html.twig',
                    array(
                        'datafield' => $datafield,
                        'datarecord' => $datarecord,

                        'files' => $file_list,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xe33cd134;
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
     * Renders the edit form for a DataRecord if the user has the requisite permissions.
     *
     * @param integer $datarecord_id The database id of the DataRecord the user wants to edit
     * @param integer $search_theme_id
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     *
     * @return Response
     */
    public function editAction($datarecord_id, $search_theme_id, $search_key, $offset, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $session = $request->getSession();

            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');
            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');
            /** @var ODRTabHelperService $odr_tab_service */
            $odr_tab_service = $this->container->get('odr.tab_helper_service');
            /** @var SearchAPIService $search_api_service */
            $search_api_service = $this->container->get('odr.search_api_service');
            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            /** @var SearchRedirectService $search_redirect_service */
            $search_redirect_service = $this->container->get('odr.search_redirect_service');

            $router = $this->get('router');
            $templating = $this->get('templating');


            // ----------------------------------------
            // Get Record In Question
            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                throw new ODRNotFoundException('Datarecord');

            // TODO - not accurate, technically...
            if ($datarecord->getProvisioned() == true)
                throw new ODRNotFoundException('Datarecord');

            $datatype = $datarecord->getDataType();
            if ($datatype->getDeletedAt() != null)
                throw new ODRNotFoundException('Datatype');
            $datatype_id = $datatype->getId();


            // Grab the "master" theme for this datatype, going to use it to render the datarecord
            // TODO - alternate themes?
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');

            /** @var Theme $theme */
            $theme = $repo_theme->findBy( array('dataType' => $datatype->getId(), 'themeType' => 'master') );
            if ($theme == null)
                throw new ODRNotFoundException('Theme');

            // If $search_theme_id is set...
            if ($search_theme_id != 0) {
                // ...require a search key to also be set
                if ($search_key == '')
                    throw new ODRBadRequestException();

                // ...require the referenced theme to exist
                /** @var Theme $search_theme */
                $search_theme = $repo_theme->find($search_theme_id);
                if ($search_theme == null)
                    throw new ODRNotFoundException('Search Theme');

                // ...require it to match the datatype being rendered
                if ($search_theme->getDataType()->getId() !== $datatype->getId())
                    throw new ODRBadRequestException();
            }


            // --------------------
            // Determine user privileges
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $user_permissions = $pm_service->getUserPermissionsArray($user);
            $datatype_permissions = $user_permissions['datatypes'];

            // Ensure user has permissions to be doing this
            if ( !$pm_service->canViewDatatype($user, $datatype) )
                throw new ODRForbiddenException();
            if ( !$pm_service->canEditDatarecord($user, $datarecord) )
                throw new ODRForbiddenException();
            // --------------------


            // ----------------------------------------
            // Grab the tab's id, if it exists
            $params = $request->query->all();
            $odr_tab_id = '';
            if ( isset($params['odr_tab_id']) )
                $odr_tab_id = $params['odr_tab_id'];
            else
                $odr_tab_id = $odr_tab_service->createTabId();

            // Determine whether the user has a restriction on which datarecords they can edit
            $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);
            $has_search_restriction = false;
            if ( !is_null($restricted_datarecord_list) )
                $has_search_restriction = true;

            // Determine which list of datarecords to pull from the user's session
            $cookies = $request->cookies;
            $only_display_editable_datarecords = true;
            if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
                $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');


            // If this datarecord is being viewed from a search result list...
            $datarecord_list = '';
            if ($search_key !== '') {
                // Ensure the search key is valid first
                $search_key_service->validateSearchKey($search_key);
                // Determine whether the user is allowed to view this search key
                $filtered_search_key = $search_api_service->filterSearchKeyForUser($datatype, $search_key, $user_permissions);
                if ($filtered_search_key !== $search_key) {
                    // User can't view the results of this search key, redirect to the one they can view
                    return $search_redirect_service->redirectToEditPage($datarecord_id, $search_theme_id, $filtered_search_key, $offset);
                }

                // Need to ensure a sort criteria is set for this tab, otherwise the table plugin
                //  will display stuff in a different order
                $sort_df_id = 0;
                $sort_ascending = true;

                $sort_criteria = $odr_tab_service->getSortCriteria($odr_tab_id);
                if ( is_null($sort_criteria) ) {
                    if (is_null($datatype->getSortField())) {
                        // ...this datarecord list is currently ordered by id
                        $odr_tab_service->setSortCriteria($odr_tab_id, 0, 'asc');
                    }
                    else {
                        // ...this datarecord list is ordered by whatever the sort datafield for this datatype is
                        $sort_df_id = $datatype->getSortField()->getId();
                        $odr_tab_service->setSortCriteria($odr_tab_id, $sort_df_id, 'asc');
                    }
                }
                else {
                    // Load the criteria from the user's session
                    $sort_df_id = $sort_criteria['datafield_id'];
                    if ($sort_criteria['sort_direction'] === 'desc')
                        $sort_ascending = false;
                }

                // No problems, so get the datarecords that match the search
                $search_results = $search_api_service->performSearch($datatype, $search_key, $user_permissions, $sort_df_id, $sort_ascending);
                $original_datarecord_list = $search_results['grandparent_datarecord_list'];


                // ----------------------------------------
                // Determine the correct list of datarecords to use for rendering...
                $datarecord_list = array();
                if ($has_search_restriction) {
                    // ...user has a restriction list, so the search header should only cycle through
                    //  the datarecords they're allowed to edit...even if there's technically more
                    //  in the search results

                    // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                    $datarecord_list = $original_datarecord_list;
                    $editable_datarecord_list = array_flip($restricted_datarecord_list);
                    foreach ($datarecord_list as $num => $dr_id) {
                        if ( !isset($editable_datarecord_list[$dr_id]) )
                            unset( $datarecord_list[$num] );
                    }

                    // Search header will use keys of $original_datarecord_list to determine
                    //  offset...so the array needs to be start from 0 again
                    $datarecord_list = array_values($datarecord_list);
                }
                else {
                    // ...user doesn't have a restriction list, so they can view all datarecords
                    //  in the search result
                    $datarecord_list = $original_datarecord_list;
                }


                $key = 0;
                if (!$only_display_editable_datarecords) {
                    // NOTE - intentionally using $original_datarecord_list instead of $datarecord_list
                    // The current page of editable datarecords may be completely different than the
                    //  current page of viewable datarecords
                    $key = array_search($datarecord->getId(), $original_datarecord_list);
                }
                else {
                    $key = array_search($datarecord->getId(), $datarecord_list);
                }

                // Compute which page of the search results this datarecord is on
                $page_length = $odr_tab_service->getPageLength($odr_tab_id);
                $offset = floor($key / $page_length) + 1;

                // Ensure the session has the correct offset stored
                $odr_tab_service->updateDatatablesOffset($odr_tab_id, $offset);
            }


            // ----------------------------------------
            // Determine whether this is a top-level datatype...if not, then the "Add new Datarecord" button in edit_header.html.twig needs to be disabled
            $top_level_datatypes = $dti_service->getTopLevelDatatypes();
            $is_top_level = 1;
            if ( !in_array($datatype_id, $top_level_datatypes) )
                $is_top_level = 0;


            // Build an array of values to use for navigating the search result list, if it exists
            $search_header = null;
            if ($search_key !== '')
                $search_header = $odr_tab_service->getSearchHeaderValues($odr_tab_id, $datarecord->getId(), $datarecord_list);

            // Need this array to exist right now so the part that's not the search header will display
            if ( is_null($search_header) ) {
                $search_header = array(
                    'page_length' => 0,
                    'next_datarecord_id' => 0,
                    'prev_datarecord_id' => 0,
                    'search_result_current' => 0,
                    'search_result_count' => 0
                );
            }

            $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));
            $record_header_html = $templating->render(
                'ODRAdminBundle:Edit:edit_header.html.twig',
                array(
                    'datatype_permissions' => $datatype_permissions,
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,

                    'is_top_level' => $is_top_level,
                    'odr_tab_id' => $odr_tab_id,

                    // values used by search_header.html.twig
                    'search_theme_id' => $search_theme_id,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord_id'],
                    'prev_datarecord' => $search_header['prev_datarecord_id'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );


            // ----------------------------------------
            // Render the edit page for this datarecord
            /** @var ODRRenderService $odr_render_service */
            $odr_render_service = $this->container->get('odr.render_service');
            $page_html = $odr_render_service->getEditHTML($user, $datarecord, $search_key, $search_theme_id);

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $record_header_html.$page_html,
            );

            // Store which datarecord to scroll to if returning to the search results list
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $source = 0x409f64ee;
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
     * Builds an array of all prior values of the given datafield, to serve as a both display of field history and a reversion dialog.
     *
     * @param integer $datarecord_id
     * @param integer $datafield_id
     * @param Request $request
     *
     * @return Response
     */
    public function getfieldhistoryAction($datarecord_id, $datafield_id, Request $request)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            throw new ODRNotImplementedException();

            // ----------------------------------------
            // Get Entity Manager and setup repositories
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var PermissionsManagementService $pm_service */
            $pm_service = $this->container->get('odr.permissions_management_service');

            /** @var DataRecord $datarecord */
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ($datarecord == null)
                throw new ODRNotFoundException('Datarecord');

            /** @var DataFields $datafield */
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ($datafield == null)
                throw new ODRNotFoundException('Datafield');

            $datatype = $datafield->getDataType();
            if ($datatype->getDeletedAt() !== null)
                throw new ODRNotFoundException('Datatype');


            // ----------------------------------------
            // Ensure user has permissions to be doing this
            /** @var ODRUser $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            if ( !$pm_service->canEditDatafield($user, $datafield, $datarecord) )
                throw new ODRForbiddenException();
            // ----------------------------------------


            // ----------------------------------------
            // Don't check field history of certain fieldtypes
            $typeclass = $datafield->getFieldType()->getTypeClass();
            if ($typeclass == 'File' || $typeclass == 'Image' || $typeclass == 'Markdown' || $typeclass == 'Radio')
                throw new \Exception('Unable to view history of a '.$typeclass.' datafield, for now');


            // Grab all fieldtypes that the datafield has been
            $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted rows
            $query = $em->createQuery(
               'SELECT DISTINCT(ft.typeClass) AS type_class
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
                WHERE df = :df_id'
            )->setParameters( array('df_id' => $datafield->getId()) );
            $results = $query->getArrayResult();

            $all_typeclasses = array();
            foreach ($results as $result) {
                $typeclass = $result['type_class'];

                if ( $typeclass !== 'File' && $typeclass !== 'Image' && $typeclass !== 'Markdown' && $typeclass !== 'Radio' )
                    $all_typeclasses[] = $typeclass;
            }


            // Grab all values that the datafield has had across all fieldtypes
            $historical_values = array();
            foreach ($all_typeclasses as $num => $typeclass) {
                $query = $em->createQuery(
                   'SELECT e.value AS value, ft.typeName AS typename, e.created AS created, created_by.firstName, created_by.lastName, created_by.username
                    FROM ODRAdminBundle:'.$typeclass.' AS e
                    JOIN ODRAdminBundle:FieldType AS ft WITH e.fieldType = ft
                    JOIN ODROpenRepositoryUserBundle:User AS created_by WITH e.createdBy = created_by
                    WHERE e.dataRecord = :datarecord_id AND e.dataField = :datafield_id'
                )->setParameters( array('datarecord_id' => $datarecord->getId(), 'datafield_id' => $datafield->getId()) );
                $results = $query->getArrayResult();

                foreach ($results as $result) {
                    $value = $result['value'];
                    $created = $result['created'];
                    $typename = $result['typename'];

                    $user_string = $result['username'];
                    if ( $result['firstName'] !== '' && $result['lastName'] !== '' )
                        $user_string = $result['firstName'].' '.$result['lastName'];

                    $historical_values[] = array('value' => $value, 'user' => $user_string, 'created' => $created, 'typeclass' => $typeclass, 'typename' => $typename);
                }
            }

            $em->getFilters()->enable('softdeleteable');    // Re-enable the softdeleteable filter


            // ----------------------------------------
            // Sort array from earliest date to latest date
            usort($historical_values, function ($a, $b) {
                $interval = date_diff($a['created'], $b['created']);
                if ( $interval->invert == 0 )
                    return -1;
                else
                    return 1;
            });

//print '<pre>'.print_r($historical_values, true).'</pre>';

            // Filter the array so it doesn't list the same value multiple times in a row
            $previous_value = null;
            foreach ($historical_values as $num => $data) {
                $current_value = $data['value'];

                if ( $previous_value !== $current_value )
                    $previous_value = $current_value;
                else
                    unset( $historical_values[$num] );
            }
            // Make the array indices contiguous again
            $historical_values = array_values($historical_values);


            // ----------------------------------------
            // Use the resulting keys of the array after the sort as version numbers
            foreach ($historical_values as $num => $data)
                $historical_values[$num]['version'] = ($num+1);

//print '<pre>'.print_r($historical_values, true).'</pre>';
//exit();

            // Generate a csrf token to use if the user wants to revert back to an earlier value
            $current_typeclass = $datafield->getFieldType()->getTypeClass();

            /** @var \Symfony\Component\Security\Csrf\CsrfTokenManager $token_generator */
            $token_generator = $this->get('security.csrf.token_manager');

            $token_id = $current_typeclass.'Form_'.$datarecord->getId().'_'.$datafield->getId();
            $csrf_token = $token_generator->getToken($token_id)->getValue();


            // Render the dialog box for this request
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Edit:field_history_dialog_form.html.twig',
                    array(
                        'historical_values' => $historical_values,

                        'datarecord' => $datarecord,
                        'datafield' => $datafield,
                        'current_typeclass' => $current_typeclass,

                        'csrf_token' => $csrf_token,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $source = 0xb2073584;
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
