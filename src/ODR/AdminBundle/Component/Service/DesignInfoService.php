<?php

/**
 * Open Data Repository Data Publisher
 * Datarecord Info Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This service stores the code to get and rebuild the cached version of the datatype array, as
 * well as several other utility functions related to lists of datatypes.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Other
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
// Utility
use ODR\AdminBundle\Component\Utility\UserUtility;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class DesignInfoService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var PermissionsManagementService
     */
    private $pm_service;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DatatypeInfoService
     */
    private $dti_service;

    /**
     * @var ThemeInfoService
     */
    private $theme_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var TokenStorage
     */
    private $token_storage;

    /**
     * @var TwigEngine
     */
    private $templating;

    /**
     * DesignInfoService constructor.
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param PermissionsManagementService $permissions_service
     * @param DatatypeInfoService $datatype_info_service
     * @param ThemeInfoService $theme_info_service
     * @param TwigEngine $templating
     * @param CsrfTokenManager $token_manager
     * @param TokenStorage $token_storage
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        PermissionsManagementService $permissions_service,
        DatatypeInfoService $datatype_info_service,
        ThemeInfoService $theme_info_service,
        TwigEngine $templating,
        CsrfTokenManager $token_manager,
        TokenStorage $token_storage,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->pm_service = $permissions_service;
        $this->dti_service = $datatype_info_service;
        $this->theme_service = $theme_info_service;
        $this->templating = $templating;
        $this->token_manager = $token_manager;
        $this->token_storage = $token_storage;
        $this->logger = $logger;
    }

    public function GetDisplayData($source_datatype_id, $template_name = 'default', $target_id)
    {
        // ----------------------------------------
        // Don't need to check permissions

        // Required objects
        $repo_datatype = $this->em->getRepository('ODRAdminBundle:DataType');
        $repo_theme = $this->em->getRepository('ODRAdminBundle:Theme');

        // Going to need these...
        $datatree_array = $this->dti_service->getDatatreeArray();

        /** @var DataType $grandparent_datatype */
        $grandparent_datatype = $repo_datatype->find($source_datatype_id);
        $master_theme = $this->theme_service->getDatatypeMasterTheme($source_datatype_id);


        // ----------------------------------------
        // Load required objects based on parameters...don't need to check whether they're deleted
        /** @var DataType $datatype */
        $datatype = null;
        /** @var Theme $theme */
        $theme = null;

        /** @var ThemeElement|null $theme_element */
        $theme_element = null;
        /** @var DataFields|null $datafield */
        $datafield = null;



        if ($template_name == 'default') {
            $datatype = $grandparent_datatype;
            $theme = $master_theme;
        } else if ($template_name == 'child_datatype') {
            $datatype = $repo_datatype->find($target_id);
            $theme = $repo_theme->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));      // TODO - this likely isn't going to work where linked datatypes are involved

            // Check whether this was actually a re-render request for a top-level datatype...
            if (!isset($datatree_array['descendant_of'][$datatype->getId()]) || $datatree_array['descendant_of'][$datatype->getId()] == '') {
                // ...it is, re-rendering should still work properly if various flags are set right
                $datatype = $grandparent_datatype;
            }
        } else if ($template_name == 'theme_element') {
            $theme_element = $this->em->getRepository('ODRAdminBundle:ThemeElement')->find($target_id);
            $theme = $theme_element->getTheme();

            $datatype = $theme->getDataType();
        } else if ($template_name == 'datafield') {
            $datafield = $this->em->getRepository('ODRAdminBundle:DataFields')->find($target_id);
            $datatype = $datafield->getDataType();
            $theme = $repo_theme->findOneBy(array('dataType' => $datatype->getId(), 'themeType' => 'master'));      // TODO - this likely isn't going to work where linked datatypes are involved

            $datatype = $datafield->getDataType();
        }


        // ----------------------------------------
        /** @var User $user */
        $user = $this->token_storage->getToken()->getUser();
        $user_permissions = $this->pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $this->pm_service->getDatatypePermissions($user);

        // Store whether the user is an admin of this datatype...this usually is true, but the user
        //  may not have the permission if this function is reloading stuff for a linked datatype
        $is_datatype_admin = $this->pm_service->isDatatypeAdmin($user, $grandparent_datatype);


        // ----------------------------------------
        // Grab the cached version of the grandparent datatype
        $include_links = true;
        $datatype_array = $this->dti_service->getDatatypeArray($grandparent_datatype->getId(), $include_links);

        // Also grab the cached version of the theme
        $theme_array = $this->theme_service->getThemeArray($master_theme->getId());

        // Due to the possibility of linked datatypes the user may not have permissions for, the
        //  datatype array needs to be filtered.
        $datarecord_array = array();
        $this->pm_service->filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);

        // "Inflate" the currently flattened datatype and theme arrays
        $stacked_datatype_array[$datatype->getId()] =
            $this->dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
        $stacked_theme_array[$theme->getId()] =
            $this->theme_service->stackThemeArray($theme_array, $theme->getId());


        // ----------------------------------------
        // Need an array of fieldtype ids and typenames for notifications when changing fieldtypes
        $fieldtype_array = array();
        /** @var FieldType[] $fieldtypes */
        $fieldtypes = $this->em->getRepository('ODRAdminBundle:FieldType')->findAll();
        foreach ($fieldtypes as $fieldtype)
            $fieldtype_array[$fieldtype->getId()] = $fieldtype->getTypeName();

        // Store whether this datatype has datarecords..affects warnings when changing fieldtypes
        $query = $this->em->createQuery(
            'SELECT COUNT(dr) AS dr_count
            FROM ODRAdminBundle:DataRecord AS dr
            WHERE dr.dataType = :datatype_id'
        )->setParameters(array('datatype_id' => $datatype->getId()));
        $results = $query->getArrayResult();

        $has_datarecords = false;
        if ($results[0]['dr_count'] > 0)
            $has_datarecords = true;


        // ----------------------------------------
        // Render the required version of the page
        $html = '';
        if ($template_name == 'default') {
            $html = $this->templating->render(
                'ODRAdminBundle:Displaytemplate:design_ajax.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'theme_array' => $stacked_theme_array,

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,

                    'fieldtype_array' => $fieldtype_array,
                    'has_datarecords' => $has_datarecords,
                )
            );
        } else if ($template_name == 'child_datatype') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 0;
            if ($datatype->getId() == $grandparent_datatype->getId()) {
                $target_datatype_id = $grandparent_datatype->getId();
                $is_top_level = 1;
            }

            // If the top-level datatype id found doesn't match the original datatype id of the
            //  design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $grandparent_datatype->getId())
                $is_link = 1;


            $html = $this->templating->render(
                'ODRAdminBundle:Displaytemplate:design_childtype.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'theme_array' => $stacked_theme_array,

                    'target_datatype_id' => $target_datatype_id,
                    'target_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'is_datatype_admin' => $is_datatype_admin,

                    'is_link' => $is_link,
                    'is_top_level' => $is_top_level,
                )
            );
        } else if ($template_name == 'theme_element') {

            // Set variables properly incase this was a theme_element for a child/linked datatype
            $target_datatype_id = $datatype->getId();
            $is_top_level = 0;
            if ($datatype->getId() == $grandparent_datatype->getId()) {
                $target_datatype_id = $grandparent_datatype->getId();
                $is_top_level = 1;
            }

            // If the top-level datatype id found doesn't match the original datatype id of the
            //  design page, then this is a request for a linked datatype
            $is_link = 0;
            if ($source_datatype_id != $grandparent_datatype->getId())
                $is_link = 1;

            // design_fieldarea.html.twig attempts to render all theme_elements in the given theme,
            //  but this request is to only re-render one of them...unset all theme_elements except
            //  the one that's being re-rendered
            foreach ($stacked_theme_array[$theme->getId()]['themeElements'] as $te_num => $te) {
                if ($te['id'] != $target_id)
                    unset($stacked_theme_array[$theme->getId()]['themeElements'][$te_num]);
            }

            $html = $this->templating->render(
                'ODRAdminBundle:Displaytemplate:design_fieldarea.html.twig',
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'theme_array' => $stacked_theme_array,

                    'target_datatype_id' => $target_datatype_id,
                    'target_theme_id' => $theme->getId(),

                    'datatype_permissions' => $datatype_permissions,
                    'is_datatype_admin' => $is_datatype_admin,

                    'is_top_level' => $is_top_level,
                    'is_link' => $is_link,
                )
            );
        } else if ($template_name == 'datafield') {

            // Locate the array versions of the requested datafield and its associated theme_datafield entry
            $datafield_array = null;
            $theme_datafield_array = null;

            if (isset($datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()]))
                $datafield_array = $datatype_array[$datatype->getId()]['dataFields'][$datafield->getId()];

            foreach ($theme_array[$theme->getId()]['themeElements'] as $te_num => $te) {
                if (isset($te['themeDataFields'])) {
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                        if (isset($tdf['dataField']) && $tdf['dataField']['id'] == $datafield->getId()) {
                            $theme_datafield_array = $tdf;
                            break;
                        }
                    }
                }

                if ($theme_datafield_array !== null)
                    break;
            }

            if ($datafield_array == null)
                throw new ODRException('Unable to locate array entry for datafield ' . $datafield->getId());
            if ($theme_datafield_array == null)
                throw new ODRException('Unable to locate theme array entry for datafield ' . $datafield->getId());

            $html = $this->templating->render(
                'ODRAdminBundle:Displaytemplate:design_datafield.html.twig',
                array(
                    'theme_datafield' => $theme_datafield_array,
                    'datafield' => $datafield_array,

                    'is_datatype_admin' => $is_datatype_admin,
                )
            );
        }

        return $html;
    }


}
