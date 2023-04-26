<?php

/**
 * Open Data Repository Data Publisher
 * ODRCustom Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This controller attempts to store a single copy of mostly
 * utility and rendering functions that would otherwise be
 * effectively duplicated across multiple controllers.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Templating\EngineInterface;


class ODRCustomController extends Controller
{

    /**
     * Utility function that renders a list of datarecords inside a wrapper template (shortresutlslist.html.twig or textresultslist.html.twig).
     * This is to allow various functions to only worry about what needs to be rendered, instead of having to do it all themselves.
     *
     * @param array $datarecords  The unfiltered list of datarecord ids that need rendered...this should contain EVERYTHING
     * @param DataType $datatype  Which datatype the datarecords belong to
     * @param Theme $theme        Which theme to use for rendering this datatype
     * @param ODRUser $user       Which user is requesting this list
     * @param string $path_str
     *
     * @param string $intent      "searching" if searching from frontpage, or "linking" if searching for datarecords to link
     * @param string $search_key  Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset     Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     *
     * @param Request $request
     *
     * @return string
     */
    public function renderList($datarecords, $datatype, $theme, $user, $path_str, $intent, $search_key, $offset, Request $request)
    {
        // -----------------------------------
        // Grab necessary objects
        $session = $this->get('session');

        $use_jupyterhub = false;
        $jupyterhub_config = $this->getParameter('jupyterhub_config');
        if ( isset($jupyterhub_config['use_jupyterhub']) && $jupyterhub_config['use_jupyterhub'] == true )
            $use_jupyterhub = true;


        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var CloneThemeService $clone_theme_service */
        $clone_theme_service = $this->container->get('odr.clone_theme_service');
        /** @var DatabaseInfoService $dbi_service */
        $dbi_service = $this->container->get('odr.database_info_service');
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');
        /** @var ODRTabHelperService $odr_tab_service */
        $odr_tab_service = $this->container->get('odr.tab_helper_service');
        /** @var EngineInterface $templating */
        $templating = $this->get('templating');


        $logged_in = false;
        if ($user !== 'anon.')
            $logged_in = true;

        $user_permissions = $pm_service->getUserPermissionsArray($user);
        $datatype_permissions = $user_permissions['datatypes'];
//        $datafield_permissions = $user_permissions['datafields'];

        // Store whether the user is permitted to edit at least one datarecord for this datatype
        $can_edit_datatype = $pm_service->canEditDatatype($user, $datatype);


        // ----------------------------------------
        // Determine whether the user is allowed to use the $theme that was passed into this
        $display_theme_warning = false;

        // Ensure the theme is valid for this datatype
        if ($theme->getDataType()->getId() !== $datatype->getId())
            throw new ODRBadRequestException('The specified Theme does not belong to this Datatype');

        // If the theme isn't usable by everybody...
        if (!$theme->isShared()) {
            // ...and the user didn't create this theme...
            if ($user === 'anon.' || $theme->getCreatedBy()->getId() !== $user->getId()) {
                // ...then this user can't use this theme

                // Find a theme they can use
                $theme_id = $theme_service->getPreferredTheme($user, $datatype->getId(), 'search_results');
                $theme = $em->getRepository('ODRAdminBundle:Theme')->find($theme_id);

                $display_theme_warning = true;
            }
        }

        // Might as well set the session default theme here
        $theme_service->setSessionTheme($datatype->getId(), $theme);

        // Determine whether the currently preferred theme needs to be synchronized with its source
        //  and the user notified of it
        $notify_of_sync = self::notifyOfThemeSync($theme, $user);


        // ----------------------------------------
        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) ) {
            // If the tab id exists, use that
            $odr_tab_id = $params['odr_tab_id'];
        }
        else {
            // ...otherwise, generate a random key to identify this tab
            $odr_tab_id = $odr_tab_service->createTabId();
        }

        // Grab the page length for this tab from the session, if possible
        $page_length = $odr_tab_service->getPageLength($odr_tab_id);


        // -----------------------------------
        // Determine whether the user has a restriction on which datarecords they can edit
        $restricted_datarecord_list = $pm_service->getDatarecordRestrictionList($user, $datatype);
        $has_search_restriction = false;
        if ( !is_null($restricted_datarecord_list) )
            $has_search_restriction = true;

        // Determine whether the user wants to only display datarecords they can edit
        $cookies = $request->cookies;
        $only_display_editable_datarecords = true;
        if ( $cookies->has('datatype_'.$datatype->getId().'_editable_only') )
            $only_display_editable_datarecords = $cookies->get('datatype_'.$datatype->getId().'_editable_only');


        // If a datarecord restriction exists, and the user only wants to display editable datarecords...
        $editable_only = false;
        if ( $can_edit_datatype && !is_null($restricted_datarecord_list) && $only_display_editable_datarecords )
            $editable_only = true;


        // Determine the correct lists of datarecords to use for rendering...
        $original_datarecord_list = array();
        // The editable list needs to be in ($dr_id => $num) format for twig
        $editable_datarecord_list = array();
        if ($can_edit_datatype) {
            if (!$has_search_restriction) {
                // ...user doesn't have a restriction list, so the editable list is the same as the
                //  viewable list
                $original_datarecord_list = $datarecords;
                $editable_datarecord_list = array_flip($datarecords);
            }
            else if (!$editable_only) {
                // ...user has a restriction list, but wants to see all datarecords that match the
                //  search
                $original_datarecord_list = $datarecords;

                // Doesn't matter if the editable list of datarecords has more than the
                //  viewable list of datarecords
                $editable_datarecord_list = array_flip($restricted_datarecord_list);
            }
            else {
                // ...user has a restriction list, and only wants to see the datarecords they are
                //  allowed to edit

                // array_flip() + isset() is orders of magnitude faster than repeated calls to in_array()
                $editable_datarecord_list = array_flip($restricted_datarecord_list);
                foreach ($datarecords as $num => $dr_id) {
                    if (!isset($editable_datarecord_list[$dr_id]))
                        unset($datarecords[$num]);
                }

                // Both the viewable and the editable lists are based off the intersection of the
                //  search results and the restriction list
                $original_datarecord_list = array_values($datarecords);
                $editable_datarecord_list = array_flip($original_datarecord_list);
            }
        }
        else {
            // ...otherwise, just use the list of datarecords that was passed in
            $original_datarecord_list = $datarecords;

            // User can't edit anything in the datatype, leave the editable datarecord list empty
        }


        // -----------------------------------
        // Ensure offset exists for shortresults list
        $offset = intval($offset);
        if ( (($offset-1) * $page_length) > count($original_datarecord_list) )
            $offset = 1;

        // Reduce datarecord_list to just the list that will get rendered
        $start = ($offset-1) * $page_length;
        $datarecord_list = array_slice($original_datarecord_list, $start, $page_length);

        //
        $has_datarecords = true;
        if ( empty($datarecord_list) )
            $has_datarecords = false;


        // -----------------------------------
        $final_html = '';
        // All theme types other than table
        if ( $theme->getThemeType() != 'table' ) {
            // -----------------------------------
            /** @var DatatreeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatree_info_service');
            /** @var SearchService $search_service */
            $search_service = $this->container->get('odr.search_service');

            // -----------------------------------
            // Load and stack the cached theme data...this can happen now since it's not filtered
            $theme_array = $theme_service->getThemeArray($theme->getId());
            $stacked_theme_array[ $theme->getId() ] =
                $theme_service->stackThemeArray($theme_array, $theme->getId());

            // Determine which datatypes are going to actually be visible to the user
            $rendered_dt_ids = self::getRenderedDatatypes($stacked_theme_array);
            // Ensure the current datatype id is going to be displayed
            $rendered_dt_ids[ $datatype->getId() ] = '';

            // Locate all datarecords that could potentially be visible on a search results page
            //  as a result of using this specific theme
            $acceptable_dr_ids = array();
            foreach ($rendered_dt_ids as $dt_id => $empty_str) {
                $dr_ids = $search_service->getCachedSearchDatarecordList($dt_id);
                foreach ($dr_ids as $dr_id => $num)
                    $acceptable_dr_ids[$dr_id] = '';
            }

            // Only want to load datarecord data if it's going to be displayed
            $related_datarecord_array = array();
            // So, for each datarecord on this page of the search results...
            foreach ($datarecord_list as $num => $dr_id) {
                // ...load the list of any datarecords it links to (this always includes $dr_id)...
                $associated_dr_ids = $dti_service->getAssociatedDatarecords($dr_id);

                foreach ($associated_dr_ids as $num => $a_dr_id) {
                    // If this record is going to be displayed, and it hasn't already been loaded...
                    if ( isset($acceptable_dr_ids[$a_dr_id]) && !isset($related_datarecord_array[$a_dr_id]) ) {
                        // ...then load just this record
                        $dr_data = $dri_service->getDatarecordArray($a_dr_id, false);
                        // ...then save this record and all its children so they can get stacked
                        foreach ($dr_data as $local_dr_id => $data)
                            $related_datarecord_array[$local_dr_id] = $data;
                    }
                }
            }

            // Filter everything that the user isn't allowed to see from the datatype/datarecord arrays
            $datatype_array = $dbi_service->getDatatypeArray($datatype->getId(), true);
            $pm_service->filterByGroupPermissions($datatype_array, $related_datarecord_array, $user_permissions);

            // Stack what remains of the datatype and datarecord arrays
            $stacked_datatype_array[ $datatype->getId() ] =
                $dbi_service->stackDatatypeArray($datatype_array, $datatype->getId());

            $datarecord_array = array();
            foreach ($related_datarecord_array as $dr_id => $dr) {
                // Only stack the top-level datarecords of this datatype
                if ( $dr['dataType']['id'] == $datatype->getId() )
                    $datarecord_array[$dr_id] = $dri_service->stackDatarecordArray($related_datarecord_array, $dr_id);
            }


            // -----------------------------------
            // Determine where on the page to scroll to if possible
            $scroll_target = '';
            if ($session->has('scroll_target')) {
                $scroll_target = $session->get('scroll_target');
                if ($scroll_target !== '') {
                    // Don't scroll to someplace on the page if the datarecord isn't displayed
                    if ( !in_array($scroll_target, $datarecords) )
                        $scroll_target = '';

                    // Null out the scroll target in the session so it only works once
                    $session->set('scroll_target', '');
                }
            }


            // -----------------------------------
            // Build the html required for the pagination header
            $pagination_values = $odr_tab_service->getPaginationHeaderValues($odr_tab_id, $offset, $original_datarecord_list);

            $pagination_html = '';
            if ( !is_null($pagination_values) ) {
                $pagination_html = $templating->render(
                    'ODRAdminBundle:Default:pagination_header.html.twig',
                    array(
                        'path_str' => $path_str,

                        'num_pages' => $pagination_values['num_pages'],
                        'num_datarecords' => $pagination_values['num_datarecords'],
                        'offset' => $pagination_values['offset'],
                        'page_length' => $pagination_values['page_length'],
                        'user_permissions' => $datatype_permissions,
                        'datatype' => $datatype,
                        'theme' => $theme,
                        'intent' => $intent,
                        'search_theme_id' => $theme->getId(),
                        'search_key' => $search_key,
                        'user' => $user,
                        'has_datarecords' => $has_datarecords,
                        'has_search_restriction' => $has_search_restriction,
                        'editable_only' => $only_display_editable_datarecords,
                        'can_edit_datatype' => $can_edit_datatype,
                        'use_jupyterhub' => $use_jupyterhub,
                    )
                );
            }
            // var_dump($stacked_datatype_array); exit();

            // -----------------------------------
            // Finally, render the list
            $template = 'ODRAdminBundle:ShortResults:shortresultslist.html.twig';
            $final_html = $templating->render(
                $template,
                array(
                    'datatype_array' => $stacked_datatype_array,
                    'datarecord_array' => $datarecord_array,
                    'theme_array' => $stacked_theme_array,

                    'initial_datatype_id' => $datatype->getId(),
                    'initial_theme_id' => $theme->getId(),

                    'has_datarecords' => $has_datarecords,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'odr_tab_id' => $odr_tab_id,

                    'logged_in' => $logged_in,
                    'display_theme_warning' => $display_theme_warning,
                    'notify_of_sync' => $notify_of_sync,
                    'intent' => $intent,

                    'pagination_html' => $pagination_html,
                    'editable_datarecord_list' => $editable_datarecord_list,
                    'can_edit_datatype' => $can_edit_datatype,
                    'editable_only' => $only_display_editable_datarecords,
                    'has_search_restriction' => $has_search_restriction,

                    // required for load_datarecord_js.html.twig
                    'search_theme_id' => $theme->getId(),
                    'search_key' => $search_key,
                    'offset' => $offset,
                    'page_length' => $page_length,

                    // Provide the list of all possible datarecord ids to twig just incase...though not strictly used by the datatables ajax, the rows returned will always end up being some subset of this list
                    'all_datarecords' => $datarecords,    // this is used by datarecord linking
                    'use_jupyterhub' => $use_jupyterhub,
                )
            );
        }
        else if ( $theme->getThemeType() == 'table' ) {
            // -----------------------------------
            $theme_array = $theme_service->getThemeArray($theme->getId());

            // Determine the columns to use for the table
            /** @var TableThemeHelperService $tth_service */
            $tth_service = $this->container->get('odr.table_theme_helper_service');
            $column_data = $tth_service->getColumnNames($user, $datatype->getId(), $theme->getId());
//exit( '<pre>'.print_r($column_data, true).'</pre>' );

            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];

            // Don't render the starting textresults list here, it'll always be loaded via ajax later
            // TODO - this doubles the initial workload for a table page...is there a way to get the table plugin to not run the first load via ajax?

            // -----------------------------------
            //
            $template = 'ODRAdminBundle:TextResults:textresultslist.html.twig';
            if ($intent == 'linking')
                $template = 'ODRAdminBundle:Link:link_datarecord_form_search.html.twig';

            $final_html = $templating->render(
                $template,
                array(
                    'datatype' => $datatype,
                    'has_datarecords' => $has_datarecords,
                    'column_names' => $column_names,
                    'num_columns' => $num_columns,
                    'odr_tab_id' => $odr_tab_id,
                    'page_length' => $page_length,
//                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'theme_array' => $theme_array,

                    'initial_theme_id' => $theme->getId(),

                    'logged_in' => $logged_in,
                    'display_theme_warning' => $display_theme_warning,
                    'notify_of_sync' => $notify_of_sync,
                    'intent' => $intent,

                    'can_edit_datatype' => $can_edit_datatype,
                    'editable_only' => $only_display_editable_datarecords,
                    'has_search_restriction' => $has_search_restriction,

                    // required for load_datarecord_js.html.twig
                    'search_theme_id' => $theme->getId(),
                    'search_key' => $search_key,
                    'offset' => $offset,

                    // Provide the list of all possible datarecord ids to twig just incase...though not strictly used by the datatables ajax, the rows returned will always end up being some subset of this list
                    'all_datarecords' => $datarecords,    // This is used by the datarecord linking
                    'use_jupyterhub' => $use_jupyterhub,
                )
            );
        }

        return $final_html;
    }


    /**
     * Recursively crawls through a stacked theme array to determine which datatypes the user could
     * see on a SearchResults page.  This intentionally doesn't care about permissions.
     *
     * @param array $stacked_theme_array
     * @return array
     */
    private function getRenderedDatatypes($stacked_theme_array)
    {
        $rendered_dt_ids = array();

        foreach ($stacked_theme_array as $t_id => $t) {
            // For each datatype in this theme that has layout data...
            $dt_id = $t['dataType']['id'];

            // ...if this datatype has at least one themeElement...
            if ( isset($t['themeElements']) ) {
                foreach ($t['themeElements'] as $te_num => $te) {
                    // ...and the themeElement isn't hidden...
                    if ( $te['themeElementMeta']['hidden'] === 0 ) {
                        if ( isset($te['themeDataFields']) ) {
                            // ...and the themeElement contains at least one datafield...
                            foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                                // ...then the user will be able to see the datatype when at least
                                //  one of its datafields is not hidden
                                if ( $tdf['hidden'] === 0 ) {
                                    $rendered_dt_ids[$dt_id] = '';

                                    // No point checking the other datafields in this themeElement
                                    break;

                                    // ...don't want to do a  break 2;  however, because the datatype
                                    //  could have child/linked descendants that need to be checked
                                }
                            }
                        }
                        else if ( isset($te['themeDataType']) ) {
                            // ...and the theme_element contains a child/linked descendant...
                            $tdt = $te['themeDataType'][0];

                            // ...then check whether the child/linked descendant should be rendered
                            $tmp = self::getRenderedDatatypes($tdt['childTheme']['theme']);

                            // If the recursion returned something...
                            if ( !empty($tmp) ) {
                                // ...then the user needs to be able to see this datatype
                                $rendered_dt_ids[$dt_id] = '';

                                // Need to save each of the child/linked descendants that the user
                                //  can view
                                foreach ($tmp as $descendant_dt_id => $val)
                                    $rendered_dt_ids[$descendant_dt_id] = '';
                            }
                        }
                    }
                }
            }
        }

        // Done checking this theme
        return $rendered_dt_ids;
    }


    /**
     * @deprecated
     *
     * Gets or creates a TrackedJob entity in the database for use by background processes
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param ODRUser $user           The user to use if a new TrackedJob is to be created
     * @param string $job_type        A label used to indicate which type of job this is  e.g. 'recache', 'import', etc.
     * @param string $target_entity   Which entity this job is operating on
     * @param array $additional_data  Additional data related to the TrackedJob
     * @param string $restrictions    TODO - ...additional info/restrictions attached to the job
     * @param integer $total          ...how many pieces the job is broken up into?
     * @param boolean $reuse_existing TODO - multi-user concerns
     *
     * @return TrackedJob
     */
    protected function ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing = false)
    {
        // TODO - this way this is called technically allows one user to overwrite another job
        // TODO - ...at least, if the job is stalled for some reason

        $tracked_job = null;

        // TODO - more flexible way of doing this?
        if ($reuse_existing)
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity) );
        else
            $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->findOneBy( array('job_type' => $job_type, 'target_entity' => $target_entity, 'completed' => null) );

        if ($tracked_job == null) {
            $tracked_job = new TrackedJob();
            $tracked_job->setJobType($job_type);
            $tracked_job->setTargetEntity($target_entity);
            $tracked_job->setCreatedBy($user);
        }
        else {
            $tracked_job->setCreated( new \DateTime() );
        }

        $tracked_job->setStarted(null);

        $tracked_job->setAdditionalData($additional_data);
        $tracked_job->setRestrictions($restrictions);

        $tracked_job->setCompleted(null);
        $tracked_job->setCurrent(0);                // TODO - possible desynch, though haven't spotted one yet
        $tracked_job->setTotal($total);
        $em->persist($tracked_job);
        $em->flush();

//        $tracked_job->resetCurrent($em);          // TODO - potential fix for possible desynch mentioned earlier
        $em->refresh($tracked_job);
        return $tracked_job;
    }


    /**
     * @deprecated
     *
     * Gets an array of TrackedError entities for a specified TrackedJob
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     *
     * @return array
     */
    protected function ODR_getTrackedErrorArray($em, $tracked_job_id)
    {
        $job_errors = array();

        $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);
        if ($tracked_job == null)
            throw new ODRNotFoundException('TrackedJob');

        /** @var TrackedError[] $tracked_errors */
        $tracked_errors = $em->getRepository('ODRAdminBundle:TrackedError')->findBy( array('trackedJob' => $tracked_job_id) );
        foreach ($tracked_errors as $error)
            $job_errors[ $error->getId() ] = array('error_level' => $error->getErrorLevel(), 'error_body' => json_decode( $error->getErrorBody(), true ));

        return $job_errors;
    }


    /**
     * @deprecated
     *
     * Deletes all TrackedError entities associated with a specified TrackedJob
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     */
    protected function ODR_deleteTrackedErrorsByJob($em, $tracked_job_id)
    {
        // Because there could potentially be thousands of errors for this TrackedJob, do a mass DQL deletion
        $query = $em->createQuery(
           'DELETE FROM ODRAdminBundle:TrackedError AS te
            WHERE te.trackedJob = :tracked_job'
        )->setParameters( array('tracked_job' => $tracked_job_id) );
        $rows = $query->execute();
    }


    /**
     * Returns errors encounted while processing a Symfony Form object as a string.
     *
     * @param \Symfony\Component\Form\FormInterface $form
     *
     * @return string
     */
    protected function ODR_getErrorMessages(\Symfony\Component\Form\FormInterface $form)
    {
        // Get all errors in this form, including those from the form's children
        $errors = $form->getErrors(true);

        $error_str = '';
        while( $errors->valid() ) {
            $error_str .= 'ERROR: '.$errors->current()->getMessage()."</br>";
            $errors->next();
        }

        return $error_str;
    }


    /**
     * @deprecated Want to replace with ODRRenderService...
     *
     * Synchronizes the given theme with its source theme if needed, and returns whether to notify
     *  the user it did so.  At the moment, a notification isn't needed when the synchronization adds
     *  a datafield/datatype that the user can't view due to permissions.
     *
     * @param Theme $theme
     * @param ODRUser $user
     *
     * @return bool
     */
    protected function notifyOfThemeSync($theme, $user)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var CloneThemeService $clone_theme_service */
        $clone_theme_service = $this->container->get('odr.clone_theme_service');
        /** @var EntityMetaModifyService $emm_service */
        $emm_service = $this->container->get('odr.entity_meta_modify_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');


        // If the theme can't be synched, then there's no sense notifying the user of anything...
        if ( !$clone_theme_service->canSyncTheme($theme, $user) )
            return false;


        // ----------------------------------------
        // Otherwise, save the diff from before the impending synchronization...
        $theme_diff_array = $clone_theme_service->getThemeSourceDiff($theme);

        // Then synchronize the theme...
        $synched = $clone_theme_service->syncThemeWithSource($user, $theme);
        if (!$synched) {
            // If the synchronization didn't actually do anything, then don't update the version
            //  numbers in the database or notify the user of anything
            return false;
        }


        // Since this theme got synched, also synch the version numbers of all themes with this
        //  this theme as their parent...
        $query = $em->createQuery(
           'SELECT t
            FROM ODRAdminBundle:Theme AS t
            WHERE t.parentTheme = :theme_id
            AND t.deletedAt IS NULL'
        )->setParameters( array('theme_id' => $theme->getId()) );
        $results = $query->getResult();

        /** @var Theme[] $results */
        $changes_made = false;
        foreach ($results as $t) {
            $current_theme_version = $t->getSourceSyncVersion();
            $source_theme_version = $t->getSourceTheme()->getSourceSyncVersion();

            if ( $current_theme_version !== $source_theme_version ) {
                $properties = array(
                    'sourceSyncVersion' => $source_theme_version
                );
                $emm_service->updateThemeMeta($user, $t, $properties, true);    // don't flush immediately
                $changes_made = true;
            }
        }

        // Flush now that all the changes have been made
        if ($changes_made)
            $em->flush();


        // ----------------------------------------
        // Go through the previously saved theme diff and determine whether the user can view at
        //  least one of the added datafields/datatypes...
        $added_datafields = array();
        $added_datatypes = array();
        $user_permissions = $pm_service->getUserPermissionsArray($user);

        foreach ($theme_diff_array as $theme_id => $diff_array) {
            if ( isset($diff_array['new_datafields']) )
                $added_datafields = array_merge($added_datafields, array_keys($diff_array['new_datafields']));
            if ( isset($diff_array['new_datatypes']) )
                $added_datatypes = array_merge($added_datatypes, array_keys($diff_array['new_datatypes']));
        }

        if ( count($added_datafields) > 0 ) {
            // Check if any of the added datafields are public...
            $query = $em->createQuery(
               'SELECT df.id, dfm.publicDate
                FROM ODRAdminBundle:DataFields AS df
                JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
                WHERE df.id IN (:datafield_ids)
                AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL'
            )->setParameters( array('datafield_ids' => $added_datafields) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                if ( $result['publicDate']->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00' )
                    // At least one datafield is public, notify the user
                    return true;
            }

            // All the added datafields are non-public, but the user could still see them if they
            //  have permissions...
            $datafield_permissions = $user_permissions['datafields'];
            foreach ($added_datafields as $num => $df_id) {
                if ( isset($datafield_permissions[$df_id])
                    && isset($datafield_permissions[$df_id]['view'])
                ) {
                    // User has permission to see this datafield, notify them of the synchronization
                    return true;
                }
            }
        }


        if ( count($added_datatypes) > 0 ) {
            // Check if any of the added datafields are public...
            $query = $em->createQuery(
               'SELECT dt.id, dtm.publicDate
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:DataTypeMeta AS dtm WITH dtm.dataType = dt
                WHERE dt.id IN (:datatype_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL'
            )->setParameters( array('datatype_ids' => $added_datatypes) );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                if ( $result['publicDate']->format('Y-m-d H:i:s') !== '2200-01-01 00:00:00' )
                    // At least one datatype is public, notify the user
                    return true;
            }

            // All the added datatypes are non-public, but the user could still see them if they
            //  have permissions...
            $datatype_permissions = $user_permissions['datatypes'];
            foreach ($added_datatypes as $num => $dt_id) {
                if ( isset($datatype_permissions[$dt_id])
                    && isset($datatype_permissions[$dt_id]['dt_view'])
                ) {
                    // User has permission to see this datatype, notify them of the synchronization
                    return true;
                }
            }
        }

        // User isn't able to view anything that was added...do not notify
        return false;
    }

}
