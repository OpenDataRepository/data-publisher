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
use ODR\AdminBundle\Entity\Boolean AS ODRBoolean;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataFieldsMeta;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataRecordMeta;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\DataTreeMeta;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Group;
use ODR\AdminBundle\Entity\GroupDatafieldPermissions;
use ODR\AdminBundle\Entity\GroupDatatypePermissions;
use ODR\AdminBundle\Entity\GroupMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\IntegerValue;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\RadioOptions;
use ODR\AdminBundle\Entity\RadioOptionsMeta;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\RenderPlugin;
use ODR\AdminBundle\Entity\RenderPluginFields;
use ODR\AdminBundle\Entity\RenderPluginInstance;
use ODR\AdminBundle\Entity\RenderPluginMap;
use ODR\AdminBundle\Entity\RenderPluginOptions;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\ThemeElement;
use ODR\AdminBundle\Entity\ThemeElementMeta;
use ODR\AdminBundle\Entity\ThemeMeta;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\ODRTabHelperService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\TableThemeHelperService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;


class ODRCustomController extends Controller
{

    /**
     * Returns true if caller should create a new meta entry, or false otherwise.
     * Currently, this decision is based on when the last change was made, and who made the change
     * ...if change was made by a different person, or within the past hour, don't create a new entry
     *
     * @param User $user
     * @param mixed $meta_entry
     *
     * @return boolean
     */
    private function createNewMetaEntry($user, $meta_entry)
    {
        $current_datetime = new \DateTime();

        /** @var \DateTime $last_updated */
        /** @var User $last_updated_by */
        $last_updated = $meta_entry->getUpdated();
        $last_updated_by = $meta_entry->getUpdatedBy();

        // If this change is being made by a different user, create a new meta entry
        if ( $last_updated == null || $last_updated_by == null || $last_updated_by->getId() !== $user->getId() )
            return true;

        // If change was made over an hour ago, create a new meta entry
        $interval = $last_updated->diff($current_datetime);
        if ( $interval->y > 0 || $interval->m > 0 || $interval->d > 0 || $interval->h > 1 )
            return true;

        // Otherwise, update the existing meta entry
        return false;
    }


    /**
     * Utility function that renders a list of datarecords inside a wrapper template (shortresutlslist.html.twig or textresultslist.html.twig).
     * This is to allow various functions to only worry about what needs to be rendered, instead of having to do it all themselves.
     *
     * @param array $datarecords  The unfiltered list of datarecord ids that need rendered...this should contain EVERYTHING
     * @param DataType $datatype  Which datatype the datarecords belong to
     * @param Theme $theme        Which theme to use for rendering this datatype
     * @param User $user          Which user is requesting this list
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
        $templating = $this->get('templating');
        $session = $this->get('session');

        $use_jupyterhub = false;
        $jupyterhub_config = $this->getParameter('jupyterhub_config');
        if ( isset($jupyterhub_config['use_jupyterhub']) && $jupyterhub_config['use_jupyterhub'] == true )
            $use_jupyterhub = true;


        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

        /** @var CloneThemeService $clone_theme_service */
        $clone_theme_service = $this->container->get('odr.clone_theme_service');
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        /** @var ThemeInfoService $theme_service */
        $theme_service = $this->container->get('odr.theme_info_service');
        /** @var ODRTabHelperService $odr_tab_service */
        $odr_tab_service = $this->container->get('odr.tab_helper_service');


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


        // -----------------------------------
        // Determine where on the page to scroll to if possible
        $scroll_target = '';
        if ($session->has('scroll_target')) {
            $scroll_target = $session->get('scroll_target');
            if ($scroll_target !== '') {
                // Don't scroll to someplace on the page if the datarecord doesn't match the datatype
                /** @var DataRecord $datarecord */
                $datarecord = $repo_datarecord->find($scroll_target);
                if ( is_null($datarecord)
                    || $datarecord->getDataType()->getId() != $datatype->getId()
                    || !in_array($scroll_target, $datarecords)
                ) {
                    $scroll_target = '';
                }

                // Null out the scroll target
                $session->set('scroll_target', '');
            }
        }


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
        if ($intent === "linking_ajax") {
            // TODO Build field order array for view....
            // ----------------------------------------
            // Grab the cached versions of all of the datarecords, and store them all at the same level in a single array
            $include_links = true;
            $related_datarecord_array = array();
            foreach ($datarecord_list as $num => $dr_id) {
                $datarecord_info = $dri_service->getDatarecordArray($dr_id, $include_links);

                foreach ($datarecord_info as $local_dr_id => $data)
                    $related_datarecord_array[$local_dr_id] = $data;
            }

            $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);
            $theme_array = $theme_service->getThemeArray($theme->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $related_datarecord_array, $user_permissions);

            // Stack the datatype and all of its children
            $stacked_datatype_array[ $datatype->getId() ] =
                $dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
            $stacked_theme_array[ $theme->getId() ] =
                $theme_service->stackThemeArray($theme_array, $theme->getId());

            // Stack each individual datarecord in the array
            // TODO - is there a faster way of doing this?  Loading/stacking datarecords is likely the slowest part of rendering a search results list now
            $datarecord_array = array();
            foreach ($related_datarecord_array as $dr_id => $dr) {
                if ( $dr['dataType']['id'] == $datatype->getId() )
                    $datarecord_array[$dr_id] = $dri_service->stackDatarecordArray($related_datarecord_array, $dr_id);
            }


            $final_html = $datarecord_array;

        }
        else if ( $theme->getThemeType() != 'table' ) {
            // -----------------------------------
            // Build the pagination header from the correct list of datarecords
            $pagination_values = $odr_tab_service->getPaginationHeaderValues($odr_tab_id, $offset, $original_datarecord_list);

            // Build the html required for the pagination header
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
                    )
                );
            }


            // ----------------------------------------
            // Grab the cached versions of all of the datarecords, and store them all at the same level in a single array
            $include_links = true;
            $related_datarecord_array = array();
            foreach ($datarecord_list as $num => $dr_id) {
                $datarecord_info = $dri_service->getDatarecordArray($dr_id, $include_links);

                foreach ($datarecord_info as $local_dr_id => $data)
                    $related_datarecord_array[$local_dr_id] = $data;
            }

            $datatype_array = $dti_service->getDatatypeArray($datatype->getId(), $include_links);
            $theme_array = $theme_service->getThemeArray($theme->getId());

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            $pm_service->filterByGroupPermissions($datatype_array, $related_datarecord_array, $user_permissions);

            // Stack the datatype and all of its children
            $stacked_datatype_array[ $datatype->getId() ] =
                $dti_service->stackDatatypeArray($datatype_array, $datatype->getId());
            $stacked_theme_array[ $theme->getId() ] =
                $theme_service->stackThemeArray($theme_array, $theme->getId());

            // Stack each individual datarecord in the array
            // TODO - is there a faster way of doing this?  Loading/stacking datarecords is likely the slowest part of rendering a search results list now
            $datarecord_array = array();
            foreach ($related_datarecord_array as $dr_id => $dr) {
                if ( $dr['dataType']['id'] == $datatype->getId() )
                    $datarecord_array[$dr_id] = $dri_service->stackDatarecordArray($related_datarecord_array, $dr_id);
            }


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
                    'scroll_target' => $scroll_target,
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
     * Since calling mkdir() when a directory already exists apparently causes a warning, and because the
     * dterranova Crypto bundle doesn't automatically handle it...this function deletes the specified directory
     * and all its contents off the server
     *
     * @param string $basedir
     */
    private function deleteEncryptionDir($basedir)
    {
        if ( !file_exists($basedir) )
            return;

        $filelist = scandir($basedir);
        foreach ($filelist as $file) {
            if ($file != '.' && $file !== '..')
                unlink($basedir.$file);
        }

        rmdir($basedir);
    }


    /**
     * Utility function that does the work of encrypting a given File/Image entity.
     *
     * @throws \Exception
     *
     * @param integer $object_id The id of the File/Image to encrypt
     * @param string $object_type "File" or "Image"
     *
     */
    protected function encryptObject($object_id, $object_type)
    {
        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $generator = $this->container->get('security.secure_random');
            $crypto = $this->get("dterranova_crypto.crypto_adapter");

            $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
            $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


            $absolute_path = '';
            $base_obj = null;
            $object_type = strtolower($object_type);
            if ($object_type == 'file') {
                // Grab the file and associated information
                /** @var File $base_obj */
                $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
                $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
                $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($file_upload_path.$filename) )
                    throw new \Exception("File does not exist");

                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($file_upload_path.$filename);
            }
            else if ($object_type == 'image') {
                // Grab the image and associated information
                /** @var Image $base_obj */
                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
                $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
                $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

                if ( !file_exists($image_upload_path.$imagename) )
                    throw new \Exception("Image does not exist");

                // crypto bundle requires an absolute path to the file to encrypt/decrypt
                $absolute_path = realpath($image_upload_path.$imagename);
            }
            /** @var File|Image $base_obj */

            // Generate a random number for encryption purposes
            $bytes = $generator->nextBytes(16); // 128-bit random number
//print 'bytes ('.gettype($bytes).'): '.$bytes."\n";

            // Convert the binary key into a hex string for db storage
            $hexEncoded_num = bin2hex($bytes);

            // Save the encryption key
            $base_obj->setEncryptKey($hexEncoded_num);
            $em->persist($base_obj);


            // Locate the directory where the encrypted files exist
            $encrypted_basedir = $this->container->getParameter('dterranova_crypto.temp_folder');
            if ($object_type == 'file')
                $encrypted_basedir .= '/File_'.$object_id.'/';
            else if ($object_type == 'image')
                $encrypted_basedir .= '/Image_'.$object_id.'/';

            // Remove all previously encrypted chunks of this object if the directory exists
            if ( file_exists($encrypted_basedir) )
                self::deleteEncryptionDir($encrypted_basedir);


            // Encrypt the file
            $crypto->encryptFile($absolute_path, $bytes);

            // Create an md5 checksum of all the pieces of that encrypted file
            $chunk_id = 0;
            while ( file_exists($encrypted_basedir.'enc.'.$chunk_id) ) {
                $checksum = md5_file($encrypted_basedir.'enc.'.$chunk_id);

                // Attempt to load a checksum object
                $obj = null;
                if ($object_type == 'file')
                    $obj = $repo_filechecksum->findOneBy( array('file' => $object_id, 'chunk_id' => $chunk_id) );
                else if ($object_type == 'image')
                    $obj = $repo_imagechecksum->findOneBy( array('image' => $object_id, 'chunk_id' => $chunk_id) );
                /** @var FileChecksum|ImageChecksum $obj */

                // Create a checksum entry if it doesn't exist
                if ($obj == null) {
                    if ($object_type == 'file') {
                        $obj = new FileChecksum();
                        $obj->setFile($base_obj);
                    }
                    else if ($object_type == 'image') {
                        $obj = new ImageChecksum();
                        $obj->setImage($base_obj);
                    }
                }

                // Save the checksum entry
                $obj->setChunkId($chunk_id);
                $obj->setChecksum($checksum);

                $em->persist($obj);

                // Look for any more encrypted chunks
                $chunk_id++;
            }

            // Save all changes
            $em->flush();
        }
        catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }


    /**
     * @deprecated
     *
     * Utility function that does the work of decrypting a given File/Image entity.
     * Note that the filename of the decrypted file/image is determined solely by $object_id and $object_type because of constraints in the $crypto->decryptFile() function
     *
     * @param integer $object_id  The id of the File/Image to decrypt
     * @param string $object_type "File" or "Image"
     *
     * @return string The absolute path to the newly decrypted file/image
     */
    protected function decryptObject($object_id, $object_type)
    {
        // Grab necessary objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $crypto = $this->get("dterranova_crypto.crypto_adapter");

        // TODO: auto-check the checksum?
//        $repo_filechecksum = $em->getRepository('ODRAdminBundle:FileChecksum');
//        $repo_imagechecksum = $em->getRepository('ODRAdminBundle:ImageChecksum');


        $absolute_path = '';
        $base_obj = null;
        $object_type = strtolower($object_type);
        if ($object_type == 'file') {
            // Grab the file and associated information
            /** @var File $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
            $file_upload_path = $this->getParameter('odr_web_directory').'/uploads/files/';
            $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($file_upload_path).'/'.$filename;
        }
        else if ($object_type == 'image') {
            // Grab the image and associated information
            /** @var Image $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            $image_upload_path = $this->getParameter('odr_web_directory').'/uploads/images/';
            $imagename = 'Image_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($image_upload_path).'/'.$imagename;
        }
        /** @var File|Image $base_obj */

        // Apparently files/images can decrypt to a zero length file sometimes...check for and deal with this
        if ( file_exists($absolute_path) && filesize($absolute_path) == 0 )
            unlink($absolute_path);

        // Since errors apparently don't cascade from the CryptoBundle through to here...
        if ( !file_exists($absolute_path) ) {
            // Grab the hex string representation that the file was encrypted with
            $key = $base_obj->getEncryptKey();
            // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
            $key = pack("H*", $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

            // Decrypt the file (do NOT delete the encrypted version)
            $crypto->decryptFile($absolute_path, $key, false);
        }

        return $absolute_path;
    }


    /**
     * Copies the contents of the given GroupMeta entity into a new GroupMeta entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'groupName', 'groupDescription', 'datarecord_restriction'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param Group $group
     * @param array $properties
     *
     * @return GroupMeta
     */
    protected function ODR_copyGroupMeta($em, $user, $group, $properties)
    {
        // Load the old meta entry
        /** @var GroupMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:GroupMeta')->findOneBy( array('group' => $group->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'groupName' => $old_meta_entry->getGroupName(),
            'groupDescription' => $old_meta_entry->getGroupDescription(),
            'datarecord_restriction' => $old_meta_entry->getDatarecordRestriction(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing GroupMeta entry
            $remove_old_entry = true;

            $new_group_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_group_meta->setCreated(new \DateTime());
            $new_group_meta->setUpdated(new \DateTime());
            $new_group_meta->setCreatedBy($user);
            $new_group_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_group_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['groupName']) )
            $new_group_meta->setGroupName( $properties['groupName'] );
        if ( isset($properties['groupDescription']) )
            $new_group_meta->setGroupDescription( $properties['groupDescription'] );
        if ( isset($properties['datarecord_restriction']) )
            $new_group_meta->setDatarecordRestriction( $properties['datarecord_restriction'] );

        $new_group_meta->setUpdatedBy($user);


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_group_meta);
        $em->flush();
        $em->refresh($group);

        // Return the new entry
        return $new_group_meta;
    }


    /**
     * Although it doesn't make sense to use previous GroupDatatypePermission entries, changes made are handled the
     * same as other soft-deleteable entities...delete the current one, and make a new one with the changes.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'can_view_datatype', 'can_view_datarecord', 'can_add_datarecord', 'can_delete_datarecord', 'can_design_datatype', 'is_datatype_admin'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param GroupDatatypePermissions $permission
     * @param User $user
     * @param array $properties
     *
     * @return GroupDatatypePermissions
     */
    protected function ODR_copyGroupDatatypePermission($em, $user, $permission, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_datatype' => $permission->getCanViewDatatype(),
            'can_view_datarecord' => $permission->getCanViewDatarecord(),
            'can_add_datarecord' => $permission->getCanAddDatarecord(),
            'can_delete_datarecord' => $permission->getCanDeleteDatarecord(),
            'can_design_datatype' => $permission->getCanDesignDatatype(),
            'is_datatype_admin' => $permission->getIsDatatypeAdmin(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission) ) {
            // Clone the existing GroupDatatypePermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_permission->setCreated(new \DateTime());
            $new_permission->setUpdated(new \DateTime());
            $new_permission->setCreatedBy($user);
            $new_permission->setUpdatedBy($user);
        }
        else {
            $new_permission = $permission;
        }

        // Set any new properties
        if ( isset( $properties['can_view_datatype']) )
            $new_permission->setCanViewDatatype( $properties['can_view_datatype'] );
        if ( isset( $properties['can_view_datarecord']) )
            $new_permission->setCanViewDatarecord( $properties['can_view_datarecord'] );
        if ( isset( $properties['can_add_datarecord']) )
            $new_permission->setCanAddDatarecord( $properties['can_add_datarecord'] );
        if ( isset( $properties['can_delete_datarecord']) )
            $new_permission->setCanDeleteDatarecord( $properties['can_delete_datarecord'] );
        if ( isset( $properties['can_design_datatype']) )
            $new_permission->setCanDesignDatatype( $properties['can_design_datatype'] );
        if ( isset( $properties['is_datatype_admin']) )
            $new_permission->setIsDatatypeAdmin( $properties['is_datatype_admin'] );

        $new_permission->setUpdatedBy($user);


        // Save the new meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($permission);

        $em->persist($new_permission);
        $em->flush();

        // Return the new entry
        return $new_permission;
    }


    /**
     * Although it doesn't make sense to use previous GroupDatafieldPermission entries, changes made are handled the
     * same as other soft-deleteable entities...delete the current one, and make a new one with the changes.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'can_view_datafield', 'can_edit_datafield'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param GroupDatafieldPermissions $permission
     * @param array $properties
     *
     * @return GroupDatafieldPermissions
     */
    protected function ODR_copyGroupDatafieldPermission($em, $user, $permission, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'can_view_datafield' => $permission->getCanViewDatafield(),
            'can_edit_datafield' => $permission->getCanEditDatafield(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $permission;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_permission = null;
        if ( self::createNewMetaEntry($user, $permission) ) {
            // Clone the existing GroupDatafieldPermissions entry
            $remove_old_entry = true;

            $new_permission = clone $permission;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_permission->setCreated(new \DateTime());
            $new_permission->setUpdated(new \DateTime());
            $new_permission->setCreatedBy($user);
            $new_permission->setUpdatedBy($user);
        }
        else {
            $new_permission = $permission;
        }

        // Set any new properties
        if ( isset( $properties['can_view_datafield']) )
            $new_permission->setCanViewDatafield( $properties['can_view_datafield'] );
        if ( isset( $properties['can_edit_datafield']) )
            $new_permission->setCanEditDatafield( $properties['can_edit_datafield'] );

        $new_permission->setUpdatedBy($user);


        // Save the new meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($permission);

        $em->persist($new_permission);
        $em->flush();

        // Return the new entry
        return $new_permission;
    }


    /**
     * @deprecated
     * Gets or creates a TrackedJob entity in the database for use by background processes
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user              The user to use if a new TrackedJob is to be created
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

        $tracked_job->setAdditionalData( json_encode($additional_data) );
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
     * Creates and persists a new DataRecordField entity, if one does not already exist for the given (DataRecord, DataField) pair.
     * TODO - do the work needed to allow this to use a  "INSERT IGNORE INTO"  query?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user requesting the creation of this entity
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     *
     * @return DataRecordFields
     */
    protected function ODR_addDataRecordField($em, $user, $datarecord, $datafield)
    {
        /** @var DataRecordFields $drf */
        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
        if ($drf == null) {
            $query =
               'INSERT INTO odr_data_record_fields (data_record_id, data_field_id, created, createdBy)
                SELECT * FROM (SELECT :datarecord AS data_record_id, :datafield AS data_field_id, NOW() AS created, :created_by AS createdBy) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_data_record_fields WHERE data_record_id = :datarecord AND data_field_id = :datafield AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId(),
                'created_by' => $user->getId()
            );
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
        }

        return $drf;
    }


    /**
     * @deprecated
     *
     * Creates and persists a new DataRecord and its associated Meta entity.  The caller needs to
     * flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user         The user requesting the creation of this entity
     * @param DataType $datatype
     *
     * @return DataRecord
     */
    protected function ODR_addDataRecord($em, $user, $datatype)
    {
        // Initial create
        $datarecord = new DataRecord();

        $datarecord->setDataType($datatype);
        $datarecord->setCreatedBy($user);
        $datarecord->setUpdatedBy($user);

        $datarecord->setProvisioned(true);  // Prevent most areas of the site from doing anything with this datarecord...whatever created this datarecord needs to eventually set this to false
        $datarecord->setUniqueId(null);

        $em->persist($datarecord);
        $em->flush();
        $em->refresh($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

        $datarecord->addDataRecordMetum($datarecord_meta);
        $em->persist($datarecord_meta);

        return $datarecord;
    }


    /**
     * Copies the given DatarecordMeta entry into a new DatarecordMeta entry for the purposes of soft-deletion.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'publicDate'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The User requesting the modification
     * @param DataRecord $datarecord           The DataRecord entry of the entity being modified
     * @param array $properties
     *
     * @return DataRecordMeta
     */
    protected function ODR_copyDatarecordMeta($em, $user, $datarecord, $properties)
    {
        // Load the old meta entry
        /** @var DataRecordMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataRecordMeta')->findOneBy( array('dataRecord' => $datarecord->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datarecord_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing DatarecordMeta entry
            $remove_old_entry = true;

            $new_datarecord_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datarecord_meta->setCreated(new \DateTime());
            $new_datarecord_meta->setUpdated(new \DateTime());
            $new_datarecord_meta->setCreatedBy($user);
            $new_datarecord_meta->setUpdatedBy($user);
        }
        else {
            $new_datarecord_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['publicDate']) )
            $new_datarecord_meta->setPublicDate( $properties['publicDate'] );

        $new_datarecord_meta->setUpdatedBy($user);


        // Save the new datarecord meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        $em->persist($new_datarecord_meta);
        $em->flush();
        $em->refresh($datarecord);

        // Return the new entry
        return $new_datarecord_meta;
    }


    /**
     * Creates and persists a new Datatree entry.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataType $ancestor
     * @param DataType $descendant
     * @param boolean $is_link
     * @param boolean $multiple_allowed
     *
     * @return DataTree
     */
    protected function ODR_addDatatree($em, $user, $ancestor, $descendant, $is_link, $multiple_allowed)
    {
        $datatree = new DataTree();
        $datatree->setAncestor($ancestor);
        $datatree->setDescendant($descendant);
        $datatree->setCreatedBy($user);

        $em->persist($datatree);
        $em->flush();
        $em->refresh($datatree);

        $datatree_meta = new DataTreeMeta();
        $datatree_meta->setDataTree($datatree);
        $datatree_meta->setIsLink($is_link);
        $datatree_meta->setMultipleAllowed($multiple_allowed);
        $datatree_meta->setCreatedBy($user);
        $datatree_meta->setUpdatedBy($user);

        $datatree->addDataTreeMetum($datatree_meta);
        $em->persist($datatree_meta);

        return $datatree;
    }


    /**
     * Copies the given DataTree entry into a new DataTree entry for the purposes of soft-deletion.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'multiple_allowed', 'is_link'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The User requesting the modification
     * @param DataTree $datatree               The DataTree entry of the entity being modified
     * @param array $properties
     *
     * @return DataTreeMeta
     */
    protected function ODR_copyDatatreeMeta($em, $user, $datatree, $properties)
    {
        // Load the old meta entry
        /** @var DataTreeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataTreeMeta')->findOneBy( array('dataTree' => $datatree->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'multiple_allowed' => $old_meta_entry->getMultipleAllowed(),
            'is_link' => $old_meta_entry->getIsLink(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datatree_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old DatatreeMeta entry
            $remove_old_entry = true;

            $new_datatree_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datatree_meta->setCreated(new \DateTime());
            $new_datatree_meta->setUpdated(new \DateTime());
            $new_datatree_meta->setCreatedBy($user);
            $new_datatree_meta->setUpdatedBy($user);
        }
        else {
            $new_datatree_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['multiple_allowed']) )
            $new_datatree_meta->setMultipleAllowed( $properties['multiple_allowed'] );
        if ( isset($properties['is_link']) )
            $new_datatree_meta->setIsLink( $properties['is_link'] );

        $new_datatree_meta->setUpdatedBy($user);


        // Save the new datatree meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        $em->persist($new_datatree_meta);
        $em->flush();
        $em->refresh($datatree);

        // Return the new entry
        return $new_datatree_meta;
    }


    /**
     * Create a datarecord link from $ancestor_datarecord to $descendant_datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user requesting the creation of this link
     * @param DataRecord $ancestor_datarecord   The DataRecord which will be the 'ancestor' side of this link
     * @param DataRecord $descendant_datarecord The DataRecord which will be the 'descendant' side of this link
     *
     * @return LinkedDataTree
     */
    protected function ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord)
    {
        // Check to see if the two datarecords are already linked
        $query = $em->createQuery(
           'SELECT ldt
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            WHERE ldt.ancestor = :ancestor AND ldt.descendant = :descendant
            AND ldt.deletedAt IS NULL'
        )->setParameters( array('ancestor' => $ancestor_datarecord, 'descendant' => $descendant_datarecord) );
        /** @var LinkedDataTree[] $results */
        $results = $query->getResult();

        $linked_datatree = null;
        if ( count($results) > 0 ) {
            // If an existing linked_datatree entry was found, return it and don't do anything else
            foreach ($results as $num => $ldt)
                return $ldt;
        }
        else {
            // ...otherwise, create a new linked_datatree entry
            $linked_datatree = new LinkedDataTree();
            $linked_datatree->setAncestor($ancestor_datarecord);
            $linked_datatree->setDescendant($descendant_datarecord);

            $linked_datatree->setCreatedBy($user);

            $em->persist($linked_datatree);
            $em->flush();
        }

        // Force a rebuild of the cached entry for the ancestor datarecord
        /** @var DatarecordInfoService $dri_service */
        $dri_service = $this->container->get('odr.datarecord_info_service');
        $dri_service->updateDatarecordCacheEntry($ancestor_datarecord, $user);

        // Also rebuild the cached list of which datarecords this ancestor datarecord now links to
        /** @var CacheService $cache_service */
        $cache_service = $this->container->get('odr.cache_service');
        $cache_service->delete('associated_datarecords_for_'.$ancestor_datarecord->getGrandparent()->getId());

        return $linked_datatree;
    }


    /**
     * Creates a new File/Image entity from the given file at the given filepath, and persists all required information to the database.
     * @todo - move all encryption/decryption stuff to a service of its own?
     *
     * NOTE: the newly uploaded file/image will have its decrypted version deleted off the server...if you need it immediately after calling this function, you'll have to use decryptObject() to re-create it
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param string $filepath                 The absolute path to the file
     * @param string $original_filename        The original name of the file
     * @param integer $user_id                 Which user is doing the uploading
     * @param integer $datarecordfield_id      Which DataRecordField entity to store the file under
     *
     * @return File|Image
     */
    protected function finishUpload($em, $filepath, $original_filename, $user_id, $datarecordfield_id)
    {
        // ----------------------------------------
        // Load required objects
        /** @var User $user */
        $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find($user_id);
        /** @var DataRecordFields $drf */
        $drf = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($datarecordfield_id);
        $typeclass = $drf->getDataField()->getFieldType()->getTypeClass();

        // Get Symfony to guess the extension of the file via mimetype...a potential wrong extension shouldn't matter since Results::filedownloadAction() renames the file during downloads anyways
        $path_prefix = $this->getParameter('odr_web_directory').'/';
        $uploaded_file = new SymfonyFile($path_prefix.$filepath.'/'.$original_filename);
        $extension = $uploaded_file->guessExtension();

        // ----------------------------------------
        // Determine where the file should ultimately be moved to
        $destination_path = $path_prefix.'uploads/';
        $my_obj = null;
        if ($typeclass == 'File') {
            $my_obj = new File();
            $destination_path .= 'files';

            // Ensure directory exists
            if ( !file_exists($destination_path) )
                mkdir( $destination_path );
        }
        else {
            $my_obj = new Image();
            $destination_path .= 'images';

            // Ensure directory exists
            if ( !file_exists($destination_path) )
                mkdir( $destination_path );
        }
        /** @var File|Image $my_obj */

        // ----------------------------------------
        // Set initial properties of the new File/Image
        $my_obj->setDataRecordFields($drf);
        $my_obj->setDataRecord ($drf->getDataRecord() );
        $my_obj->setDataField( $drf->getDataField() );
        $my_obj->setFieldType( $drf->getDataField()->getFieldType() );

        $my_obj->setExt($extension);
        $my_obj->setLocalFileName('temp');
        $my_obj->setCreatedBy($user);
        $my_obj->setOriginalChecksum('');
        // encrypt_key set by self::encryptObject() somewhat later

        if ($typeclass == 'Image') {
            /** @var Image $my_obj */
            $my_obj->setOriginal('1');
        }
        else if ($typeclass == 'File') {
            /** @var File $my_obj */
            $my_obj->setFilesize(0);
            $my_obj->setProvisioned(true);
        }

        // Save changes
        $em->persist($my_obj);
        $em->flush();
        $em->refresh($my_obj);

        // Also create the initial metadata entry for this new entity
        if ($typeclass == 'Image') {
            $new_image_meta = new ImageMeta();
            $new_image_meta->setImage($my_obj);

            $new_image_meta->setOriginalFileName($original_filename);
            $new_image_meta->setDisplayorder(0);    // TODO - actual display order?
            $new_image_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public    TODO - let user decide default status
            $new_image_meta->setCaption(null);
            $new_image_meta->setExternalId('');

            $new_image_meta->setCreatedBy($user);
            $new_image_meta->setUpdatedBy($user);
            $em->persist($new_image_meta);
        }
        else if ($typeclass == 'File') {
            $new_file_meta = new FileMeta();
            $new_file_meta->setFile($my_obj);

            $new_file_meta->setOriginalFileName($original_filename);
            $new_file_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public
            $new_file_meta->setDescription(null);
            $new_file_meta->setExternalId('');

            $new_file_meta->setCreatedBy($user);
            $new_file_meta->setUpdatedBy($user);
            $em->persist($new_file_meta);
        }

        $em->flush();


        // ----------------------------------------
        // Set the remaining properties of the new File/Image dependent on the new entities ID
        //$file_path = '';
        if ($typeclass == 'Image') {
            // Generate local filename
            /** @var Image $my_obj */
            $image_id = $my_obj->getId();

            // Move image to correct spot
            $filename = 'Image_'.$image_id.'.'.$my_obj->getExt();
            rename($path_prefix.$filepath.'/'.$original_filename, $destination_path.'/'.$filename);

            $local_filename = $my_obj->getUploadDir().'/'.$filename;
            $my_obj->setLocalFileName($local_filename);    // TODO - make this only store filepath like with file upload...inline encrypter will need to be changed...

            $sizes = getimagesize($local_filename);
            $my_obj->setImageWidth( $sizes[0] );
            $my_obj->setImageHeight( $sizes[1] );
            // Create thumbnails and other sizes/versions of the uploaded image
            self::resizeImages($my_obj, $user);

            // Encrypt parent image AFTER thumbnails are created
            self::encryptObject($image_id, 'image');

            // Set original checksum for original image
            $filepath = self::decryptObject($image_id, 'image');
            $original_checksum = md5_file($filepath);
            $my_obj->setOriginalChecksum($original_checksum);

            // A decrypted version of the Image still exists on the server...delete it
            unlink($filepath);

            // Save changes again
            $em->persist($my_obj);
            $em->flush();
        }
        else if ($typeclass == 'File') {
            // Due to filename length concerns, only store the file's path in localFileName for now
            // The filename itself is already stored in the file's meta entry
            $local_filename = realpath( $path_prefix.$filepath.'/'.$original_filename );
            $my_obj->setLocalFileName( realpath($path_prefix.$filepath).'/' );

            // localFileName will be changed after encryption to point to an actual file

            clearstatcache(true, $local_filename);
            $my_obj->setFilesize( filesize($local_filename) );

            // Save changes again before encryption process takes over
            $em->persist($my_obj);
            $em->flush();
            $em->refresh($my_obj);


            // ----------------------------------------
            // Use beanstalk to encrypt the file so the UI doesn't block on huge files
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "object_type" => $typeclass,
                    "object_id" => $my_obj->getId(),
                    "target_filename" => '',
                    "crypto_type" => 'encrypt',

                    "archive_filepath" => '',
                    "desired_filename" => '',

                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
        }

        return $my_obj;
    }


    /**
     * Creates, persists, and flushes a new storage entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                        The user requesting the creation of this entity
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param boolean|integer|string|\DateTime $initial_value
     *
     * @throws \Exception
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    protected function ODR_addStorageEntity($em, $user, $datarecord, $datafield, $initial_value = null)
    {
        // Locate the table name that will be inserted into if the storage entity doesn't exist
        $fieldtype = $datafield->getFieldType();
        $typeclass = $fieldtype->getTypeClass();

        $default_value = '';
        $table_name = null;
        switch ($typeclass) {
            case 'Boolean':
                $table_name = 'odr_boolean';
                $default_value = 0;
                break;
            case 'DatetimeValue':
                $table_name = 'odr_datetime_value';
                $default_value = '9999-12-31 00:00:00';
                break;
            case 'DecimalValue':
                $table_name = 'odr_decimal_value';
                $default_value = null;
                break;
            case 'IntegerValue':
                $table_name = 'odr_integer_value';
                $default_value = null;
                break;
            case 'LongText':    // paragraph text
                $table_name = 'odr_long_text';
                break;
            case 'LongVarchar':
                $table_name = 'odr_long_varchar';
                break;
            case 'MediumVarchar':
                $table_name = 'odr_medium_varchar';
                break;
            case 'ShortVarchar':
                $table_name = 'odr_short_varchar';
                break;

            case 'File':
            case 'Image':
            case 'Radio':
            case 'Markdown':
            default:
                throw new \Exception('ODR_addStorageEntity() called on invalid fieldtype "'.$typeclass.'"');
                break;
        }


        // Return the storage entity if it already exists
        /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $storage_entity */
        $storage_entity = $em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
        if ( !is_null($storage_entity) )
            return $storage_entity;

        // Otherwise, locate/create the datarecordfield entity for this datarecord/datafield pair
        $drf = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

        // Determine which value to use for the default value
        $insert_value = null;
        if ( !is_null($initial_value) )
            $insert_value = $initial_value;
        else
            $insert_value = $default_value;

        // Ensure the boolean value is an integer...native SQL query will complain if it's an actual boolean value...
        if ($typeclass == 'Boolean') {
            if ($insert_value == false)
                $insert_value = 0;
            else
                $insert_value = 1;
        }


        // Create a new storage entity
        $query =
           'INSERT INTO '.$table_name.' (`data_record_id`, `data_field_id`, `data_record_fields_id`, `field_type_id`, `value`, `created`, `createdBy`, `updated`, `updatedBy`)
            SELECT * FROM (
                SELECT :dr_id AS `data_record_id`, :df_id AS `data_field_id`, :drf_id AS `data_record_fields_id`, :ft_id AS `field_type_id`, :initial_value AS `value`,
                    NOW() AS `created`, :created_by AS `createdBy`, NOW() AS `updated`, :created_by AS `updated_by`
            ) AS tmp
            WHERE NOT EXISTS (
                SELECT id FROM '.$table_name.' WHERE data_record_id = :dr_id AND data_field_id = :df_id AND data_record_fields_id = :drf_id AND deletedAt IS NULL
            ) LIMIT 1;';
        $params = array(
            'dr_id' => $datarecord->getId(),
            'df_id' => $datafield->getId(),
            'drf_id' => $drf->getId(),
            'ft_id' => $datafield->getFieldType()->getId(),
            'initial_value' => $insert_value,

            'created_by' => $user->getId(),
        );
        $conn = $em->getConnection();
        $rowsAffected = $conn->executeUpdate($query, $params);

        // Reload the storage entity
        $storage_entity = $em->getRepository('ODRAdminBundle:'.$typeclass)->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $datafield->getId()) );
        $em->refresh($storage_entity);

        // Decimal values need to run setValue() because there's php logic involved
        if ( $typeclass == 'DecimalValue' && !is_null($initial_value) ) {
            $storage_entity->setValue($insert_value);

            $em->persist($storage_entity);
            $em->flush($storage_entity);
            $em->refresh($storage_entity);
        }

        return $storage_entity;
    }


    /**
     * Modifies a given storage entity by copying the old value into a new storage entity, then deleting the old entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $entity
     * @param array $properties
     *
     * @return ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar
     */
    protected function ODR_copyStorageEntity($em, $user, $entity, $properties)
    {
        // Determine which type of entity to create if needed
        $typeclass = $entity->getDataField()->getFieldType()->getTypeClass();
        $classname = "ODR\\AdminBundle\\Entity\\".$typeclass;

        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'value' => $entity->getValue()
        );

        // Change current values stored in IntegerValue or DecimalValue entities to strings...all values in $properties are already strings, and php does odd compares between strings and numbers
        if ($typeclass == 'IntegerValue' || $typeclass == 'DecimalValue')
            $existing_values['value'] = strval($existing_values['value']);

        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] !== $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $entity;


        // If this is an IntegerValue entity, set the value back to an integer or null so it gets saved correctly
        if ($typeclass == 'IntegerValue') {
            if ($properties['value'] === '')
                $properties['value'] = null;
            else
                $properties['value'] = intval($properties['value']);
        }


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity) ) {
            // Create a new entry and copy the previous one's data over
            $remove_old_entry = true;

            /** @var ODRBoolean|DatetimeValue|DecimalValue|IntegerValue|LongText|LongVarchar|MediumVarchar|ShortVarchar $new_entity */
            $new_entity = new $classname();
            $new_entity->setDataRecord( $entity->getDataRecord() );
            $new_entity->setDataField( $entity->getDataField() );
            $new_entity->setDataRecordFields( $entity->getDataRecordFields() );
            $new_entity->setFieldType( $entity->getFieldType() );

            $new_entity->setValue( $entity->getValue() );
            if ($typeclass == 'DecimalValue')
                $new_entity->setOriginalValue( $entity->getOriginalValue() );

            $new_entity->setCreatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties...not checking isset() because it couldn't reach this point without being isset()
        // Also,  isset( array[key] ) == false  when  array(key => null)
        $new_entity->setValue( $properties['value'] );

        $new_entity->setUpdatedBy($user);


        // Save the new entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($entity);

        $em->persist($new_entity);
        $em->flush();

        return $new_entity;
    }


    /**
     * Modifies a meta entry for a given File entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'description', 'original_filename', 'external_id', and/or 'publicDate' (MUST BE A DATETIME OBJECT).
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param File $file                       The File entity of the meta entry being modified
     * @param array $properties
     *
     * @return FileMeta
     */
    protected function ODR_copyFileMeta($em, $user, $file, $properties)
    {
        // Load the old meta entry
        /** @var FileMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:FileMeta')->findOneBy( array('file' => $file->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'description' => $old_meta_entry->getDescription(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_file_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old FileMeta entry
            $remove_old_entry = true;

            $new_file_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_file_meta->setCreated(new \DateTime());
            $new_file_meta->setUpdated(new \DateTime());
            $new_file_meta->setCreatedBy($user);
            $new_file_meta->setUpdatedBy($user);
        }
        else {
            $new_file_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['description']) )
            $new_file_meta->setDescription( $properties['description'] );
        if ( isset($properties['original_filename']) )
            $new_file_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $new_file_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_file_meta->setPublicDate( $properties['publicDate'] );

        $new_file_meta->setUpdatedBy($user);


        // Save the new meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        $em->persist($new_file_meta);
        $em->flush();
        $em->refresh($file);

        // Return the new entry
        return $new_file_meta;
    }


    /**
     * Modifies a meta entry for a given Image entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'caption', 'original_filename', 'external_id', 'publicDate' (MUST BE A DATETIME OBJECT), and/or 'display_order.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param Image $image                     The Image entity of the meta entry being modified
     * @param array $properties
     *
     * @return ImageMeta
     */
    protected function ODR_copyImageMeta($em, $user, $image, $properties)
    {
        // Load the old meta entry
        /** @var ImageMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ImageMeta')->findOneBy( array('image' => $image->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'caption' => $old_meta_entry->getCaption(),
            'original_filename' => $old_meta_entry->getOriginalFileName(),
            'external_id' => $old_meta_entry->getExternalId(),
            'publicDate' => $old_meta_entry->getPublicDate(),
            'display_order' => $old_meta_entry->getDisplayorder()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_image_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ImageMeta entry
            $remove_old_entry = true;

            $new_image_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_image_meta->setCreated(new \DateTime());
            $new_image_meta->setUpdated(new \DateTime());
            $new_image_meta->setCreatedBy($user);
            $new_image_meta->setUpdatedBy($user);
        }
        else {
            $new_image_meta = $old_meta_entry;
        }

        // Set any new properties
        if ( isset($properties['caption']) )
            $new_image_meta->setCaption( $properties['caption'] );
        if ( isset($properties['original_filename']) )
            $new_image_meta->setOriginalFileName( $properties['original_filename'] );
        if ( isset($properties['external_id']) )
            $new_image_meta->setExternalId( $properties['external_id'] );
        if ( isset($properties['publicDate']) )
            $new_image_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['display_order']) )
            $new_image_meta->setDisplayorder( $properties['display_order'] );

        $new_image_meta->setUpdatedBy($user);


        // Save the new meta entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        $em->persist($new_image_meta);
        $em->flush();
        $em->refresh($image);

        // Return the new entry
        return $new_image_meta;
    }


    /**
     * Creates a new RadioOption entity
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user            The user requesting the creation of this entity.
     * @param DataFields $datafield
     * @param boolean $force_create If true, always create a new RadioOption...otherwise find and return the existing RadioOption with $datafield and $option_name, or create one if it doesn't exist
     * @param string $option_name   An optional name to immediately assign to the RadioOption entity
     * @param boolean $update_master Automatically update the master template revision
     *
     * @return RadioOptions
     */
    protected function ODR_addRadioOption($em, $user, $datafield, $force_create, $option_name = "Option", $update_master = true)
    {
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');

        if ($force_create) {
            // Create a new RadioOption entity
            /** @var RadioOptions $radio_option */
            $radio_option = new RadioOptions();
            $radio_option->setDataField($datafield);
            $radio_option->setOptionName($option_name);     // exists to prevent potential concurrency issues, see below

            // All new fields require a radio option UUID
            $radio_option->setRadioOptionUuid( $dti_service->generateRadioOptionUniqueId() );
            $radio_option->setCreatedBy($user);
            $radio_option->setCreated(new \DateTime());

            // Ensure the "in-memory" version of the datafield knows about the new radio option
            $datafield->addRadioOption($radio_option);
            $em->persist($radio_option);

            // Create a new RadioOptionMeta entity
            /** @var RadioOptionsMeta $radio_option_meta */
            $radio_option_meta = new RadioOptionsMeta();
            $radio_option_meta->setRadioOption($radio_option);
            $radio_option_meta->setOptionName($option_name);
            $radio_option_meta->setXmlOptionName('');
            $radio_option_meta->setDisplayOrder(0);
            $radio_option_meta->setIsDefault(false);

            $radio_option_meta->setCreatedBy($user);
            $radio_option_meta->setCreated( new \DateTime() );

            // Ensure the "in-memory" version of the new radio option knows about its meta entry
            $radio_option->addRadioOptionMetum($radio_option_meta);
            $em->persist($radio_option_meta);

            // Master Template Data Fields must increment Master Revision on all change requests.
            if($datafield->getIsMasterField() && $update_master) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                self::ODR_copyDatafieldMeta($em, $user, $datafield, $dfm_properties);
            }

            return $radio_option;
        }
        else {
            // See if a RadioOption entity for this datafield with this name already exists
            $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy( array('optionName' => $option_name, 'dataField' => $datafield->getId()) );
            if ($radio_option == null) {
                // Define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                $query =
                    'INSERT INTO odr_radio_options (option_name, data_fields_id, created, createdBy)
                     SELECT * FROM (
                         SELECT :option_name AS option_name, :df_id AS data_fields_id, NOW() AS created, :created_by AS createdBy
                     ) AS tmp
                     WHERE NOT EXISTS (
                         SELECT option_name FROM odr_radio_options WHERE option_name = :option_name AND data_fields_id = :df_id AND deletedAt IS NULL
                     ) LIMIT 1;';
                $params = array(
                    'option_name' => $option_name,
                    'df_id' => $datafield->getId(),
                    'created_by' => $user->getId(),
                );
                $conn = $em->getConnection();
                $rowsAffected = $conn->executeUpdate($query, $params);

                // Now that it exists, fill out the properties of a RadioOption entity that were skipped during the manual creation...
                /** @var RadioOptions $radio_option */
                $radio_option = $em->getRepository('ODRAdminBundle:RadioOptions')->findOneBy(array('optionName' => $option_name, 'dataField' => $datafield->getId()));
                $radio_option->setRadioOptionUuid( $dti_service->generateRadioOptionUniqueId() );
                $em->persist($radio_option);


                // See if a RadioOptionMeta entity exists for this RadioOption...
                /** @var RadioOptionsMeta $radio_option_meta */
                $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOption' => $radio_option->getId()) );
                if ($radio_option_meta == null) {
                    // Define and execute a query to manually create the absolute minimum required for a RadioOption entity...
                    $query =
                       'INSERT INTO odr_radio_options_meta (radio_option_id, option_name, display_order, is_default, xml_option_name, created, createdBy, updated, updatedBy)
                        SELECT * FROM (
                            SELECT :ro_id AS radio_option_id, :option_name AS option_name, :display_order AS display_order, :is_default AS is_default, :xml_option_name AS xml_option_name,
                                NOW() AS created, :created_by AS createdBy, NOW() AS updated, :updated_by AS updatedBy
                        ) AS tmp
                        WHERE NOT EXISTS (
                            SELECT radio_option_id FROM odr_radio_options_meta WHERE radio_option_id = :ro_id AND deletedAt IS NULL
                        ) LIMIT 1;';
                    $params = array(
                        'ro_id' => $radio_option->getId(),
                        'option_name' => $option_name,

                        'display_order' => 0,
                        'is_default' => 0,
                        'xml_option_name' => '',

                        'created_by' => $user->getId(),
                        'updated_by' => $user->getId(),
                    );
                    $conn = $em->getConnection();
                    $rowsAffected = $conn->executeUpdate($query, $params);

                    // Now that it exists, fill out the properties of a RadioOptionMeta entity that were skipped during the manual creation...
                    $radio_option_meta = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOption' => $radio_option->getId()) );
                }
            }

            // Master Template Data Fields must increment Master Revision
            // on all change requests.
            if($datafield->getIsMasterField()) {
                $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
                self::ODR_copyDatafieldMeta($em, $user, $datafield, $dfm_properties);
            }

            return $radio_option;
        }
    }


    /**
     * Modifies a meta entry for a given RadioOptions entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'optionName', 'xml_optionName', 'displayOrder', and/or 'isDefault'.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param RadioOptions $radio_option       The RadioOption entity of the meta entry being modified
     * @param array $properties
     *
     * @return RadioOptionsMeta
     */
    protected function ODR_copyRadioOptionsMeta($em, $user, $radio_option, $properties)
    {
        // Load the old meta entry
        /** @var RadioOptionsMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:RadioOptionsMeta')->findOneBy( array('radioOption' => $radio_option->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'optionName' => $old_meta_entry->getOptionName(),
            'xml_optionName' => $old_meta_entry->getXmlOptionName(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'isDefault' => $old_meta_entry->getIsDefault(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_radio_option_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old RadioOptionsMeta entry
            $remove_old_entry = true;

            $new_radio_option_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_radio_option_meta->setCreated(new \DateTime());
            $new_radio_option_meta->setUpdated(new \DateTime());
            $new_radio_option_meta->setCreatedBy($user);
            $new_radio_option_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_radio_option_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['optionName']) )
            $new_radio_option_meta->setOptionName( $properties['optionName'] );
        if ( isset($properties['xml_optionName']) )
            $new_radio_option_meta->setXmlOptionName( $properties['xml_optionName'] );
        if ( isset($properties['displayOrder']) )
            $new_radio_option_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['isDefault']) )
            $new_radio_option_meta->setIsDefault( $properties['isDefault'] );

        $new_radio_option_meta->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_radio_option_meta);
        $em->flush();
        $em->refresh($radio_option);

        // Master Template Data Fields must increment Master Revision
        // on all change requests.
        if ($radio_option->getDataField()->getIsMasterField()) {
            $datafield = $radio_option->getDataField();
            $dfm_properties['master_revision'] = $datafield->getMasterRevision() + 1;
            self::ODR_copyDatafieldMeta($em, $user, $datafield, $dfm_properties);
        }

        // Return the new entry
        return $new_radio_option_meta;
    }


    /**
     * Creates a new RadioSelection entity for the specified RadioOption/Datarecordfield pair if one doesn't already exist
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                         The user requesting the creation of this entity.
     * @param RadioOptions $radio_option         The RadioOption entity receiving this RadioSelection
     * @param DataRecordFields $drf
     *
     * @return RadioSelection
     */
    protected function ODR_addRadioSelection($em, $user, $radio_option, $drf)
    {
        /** @var RadioSelection $radio_selection */
        $radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy( array('dataRecordFields' => $drf->getId(), 'radioOption' => $radio_option->getId()) );
        if ($radio_selection == null) {
            $query =
               'INSERT INTO odr_radio_selection (data_record_fields_id, radio_option_id, selected, created, createdBy, updated, updatedBy)
                SELECT * FROM (
                    SELECT :drf_id AS data_record_fields_id, :ro_id AS radio_option_id, :selected AS selected,
                        NOW() AS created, :created_by AS createdBy, NOW() AS updated, :created_by AS updatedBy
                ) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_radio_selection WHERE data_record_fields_id = :drf_id AND radio_option_id = :ro_id AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array(
                'drf_id' => $drf->getId(),
                'ro_id' => $radio_option->getId(),
                'selected' => 0,
                'created_by' => $user->getId(),
            );
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            // Reload the radio selection entity
            $radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection')->findOneBy( array('dataRecordFields' => $drf->getId(), 'radioOption' => $radio_option->getId()) );
        }

        return $radio_selection;
    }


    /**
     * Modifies a given radio selection entity by copying the old value into a new storage entity, then deleting the old entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param RadioSelection $entity
     * @param array $properties
     *
     * @return RadioSelection
     */
    protected function ODR_copyRadioSelection($em, $user, $entity, $properties)
    {
        // No point making new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'selected' => $entity->getSelected()
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $entity;


        // Determine whether to create a new entry or modify the previous one
        $remove_old_entry = false;
        $new_entity = null;
        if ( self::createNewMetaEntry($user, $entity) ) {
            // Clone the old RadioSelection entry
            $remove_old_entry = true;

            $new_entity = clone $entity;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_entity->setCreated(new \DateTime());
            $new_entity->setUpdated(new \DateTime());
            $new_entity->setCreatedBy($user);
            $new_entity->setUpdatedBy($user);
        }
        else {
            $new_entity = $entity;
        }

        // Set any new properties
        if ( isset($properties['selected']) )
            $new_entity->setSelected( $properties['selected'] );

        $new_entity->setUpdatedBy($user);


        // Save the new entry and delete the old one if needed
        if ($remove_old_entry)
            $em->remove($entity);

        $em->persist($new_entity);
        $em->flush();

        return $new_entity;
    }


    /**
     * Modifies a meta entry for a given Datatype entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     *
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param DataType $datatype               The DataField entity of the meta entry being modified
     * @param array $properties
     *
     * @return DataTypeMeta
     */
    protected function ODR_copyDatatypeMeta($em, $user, $datatype, $properties)
    {
        // Load the old meta entry
        /** @var DataTypeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('dataType' => $datatype->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // This entity can be set here since it's never null
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

            'searchSlug' => $old_meta_entry->getSearchSlug(),
            'shortName' => $old_meta_entry->getShortName(),
            'longName' => $old_meta_entry->getLongName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_shortName' => $old_meta_entry->getXmlShortName(),

            'searchNotesUpper' => $old_meta_entry->getSearchNotesUpper(),
            'searchNotesLower' => $old_meta_entry->getSearchNotesLower(),

            'publicDate' => $old_meta_entry->getPublicDate(),

            'master_published_revision' => $old_meta_entry->getMasterPublishedRevision(),
            'master_revision' => $old_meta_entry->getMasterRevision(),
            'tracking_master_revision' => $old_meta_entry->getTrackingMasterRevision(),
        );

        // These datafield entries could be null to begin with
        if ( $old_meta_entry->getExternalIdField() !== null )
            $existing_values['externalIdField'] = $old_meta_entry->getExternalIdField()->getId();
        if ( $old_meta_entry->getNameField() !== null )
            $existing_values['nameField'] = $old_meta_entry->getNameField()->getId();
        if ( $old_meta_entry->getSortField() !== null )
            $existing_values['sortField'] = $old_meta_entry->getSortField()->getId();
        if ( $old_meta_entry->getBackgroundImageField() !== null )
            $existing_values['backgroundImageField'] = $old_meta_entry->getBackgroundImageField()->getId();


        foreach ($existing_values as $key => $value) {
            // array_key_exists() is used because the datafield entries could legitimately be null
            if ( array_key_exists($key, $properties) && $properties[$key] != $value )
                $changes_made = true;
        }

        // Need to do an additional check incase the name/sort/etc datafields were originally null
        //  and changed to point to a datafield.  Can use isset() here because the value in
        //  $properties won't be null in this case
        if ( !isset($existing_values['externalIdField']) && isset($properties['externalIdField']) )
            $changes_made = true;
        if ( !isset($existing_values['nameField']) && isset($properties['nameField']) )
            $changes_made = true;
        if ( !isset($existing_values['sortField']) && isset($properties['sortField']) )
            $changes_made = true;
        if ( !isset($existing_values['backgroundImageField']) && isset($properties['backgroundImageField']) )
            $changes_made = true;

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datatype_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the existing DatatypeMeta entry
            $remove_old_entry = true;

            $new_datatype_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datatype_meta->setCreated(new \DateTime());
            $new_datatype_meta->setUpdated(new \DateTime());
            $new_datatype_meta->setCreatedBy($user);
            $new_datatype_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datatype_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['renderPlugin']) )
            $new_datatype_meta->setRenderPlugin( $em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        if ( array_key_exists('externalIdField', $properties) ) {
            if ( is_null($properties['externalIdField']) )
                $new_datatype_meta->setExternalIdField(null);
            else
                $new_datatype_meta->setExternalIdField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['externalIdField']) );
        }
        if ( array_key_exists('nameField', $properties) ) {
            if ( is_null($properties['nameField']) )
                $new_datatype_meta->setNameField(null);
            else
                $new_datatype_meta->setNameField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['nameField']) );
        }
        if ( array_key_exists('sortField', $properties) ) {
            if ( is_null($properties['sortField']) )
                $new_datatype_meta->setSortField(null);
            else
                $new_datatype_meta->setSortField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['sortField']) );
        }
        if ( array_key_exists('backgroundImageField', $properties) ) {
            if ( is_null($properties['backgroundImageField']) )
                $new_datatype_meta->setBackgroundImageField(null);
            else
                $new_datatype_meta->setBackgroundImageField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['backgroundImageField']) );
        }

        if ( isset($properties['searchSlug']) )
            $new_datatype_meta->setSearchSlug( $properties['searchSlug'] );
        if ( isset($properties['shortName']) )
            $new_datatype_meta->setShortName( $properties['shortName'] );
        if ( isset($properties['longName']) )
            $new_datatype_meta->setLongName( $properties['longName'] );
        if ( isset($properties['description']) )
            $new_datatype_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_shortName']) )
            $new_datatype_meta->setXmlShortName( $properties['xml_shortName'] );

        if ( isset($properties['searchNotesUpper']) )
            $new_datatype_meta->setSearchNotesUpper( $properties['searchNotesUpper'] );
        if ( isset($properties['searchNotesLower']) )
            $new_datatype_meta->setSearchNotesLower( $properties['searchNotesLower'] );

        if ( isset($properties['publicDate']) )
            $new_datatype_meta->setPublicDate( $properties['publicDate'] );

        if ( isset($properties['master_revision']) )
            $new_datatype_meta->setMasterRevision( $properties['master_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datatype_meta->setMasterPublishedRevision( $properties['master_published_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datatype_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );

        $new_datatype_meta->setUpdatedBy($user);

        if ($datatype->getIsMasterType()) {
            // Update grandparent master revision
            if ($datatype->getGrandparent()->getId() != $datatype->getId()) {
                $grandparent_datatype = $datatype->getGrandparent();

                $gp_properties['master_revision'] = $grandparent_datatype->getMasterRevision() + 1;
                self::ODR_copyDatatypeMeta($em, $user, $grandparent_datatype, $gp_properties);
            }
        }

        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_datatype_meta);
        $em->flush();
        $em->refresh($datatype);

        // Return the new entry
        return $new_datatype_meta;
    }


    /**
     * Creates and persists a new DataFields entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                 The user requesting the creation of this entity
     * @param DataType $datatype
     * @param FieldType $fieldtype
     * @param RenderPlugin $renderplugin The RenderPlugin for this new DataField to use...(almost?) always going to be the default RenderPlugin
     *
     * @return array
     */
    protected function ODR_addDataField($em, $user, $datatype, $fieldtype, $renderplugin)
    {
        // Poplulate new DataFields form
        $datafield = new DataFields();
        $datafield->setDataType($datatype);
        $datafield->setCreatedBy($user);

        // This will always be zero unless
        // created from a Master Template data field.
        // $datafield->setMasterDataField(0);

        // Set UUID
        /** @var DatatypeInfoService $dti_service */
        $dti_service = $this->container->get('odr.datatype_info_service');
        $datafield->setFieldUuid($dti_service->generateDataFieldUniqueId());

        // Add master flags
        $datafield->setIsMasterField(false);
        if ($datatype->getIsMasterType() == true)
            $datafield->setIsMasterField(true);

        $em->persist($datafield);
        $em->flush();
        $em->refresh($datafield);


        $datafield_meta = new DataFieldsMeta();
        $datafield_meta->setDataField($datafield);
        $datafield_meta->setFieldType($fieldtype);
        $datafield_meta->setRenderPlugin($renderplugin);

        // Master Revision defaults to zero.  When
        // created from a Master Template field, this will
        // track the data field Master Published Revision.
        $datafield_meta->setMasterRevision(0);
        // Will need to set the tracking revision if created
        // from master template field.
        $datafield_meta->setTrackingMasterRevision(0);
        $datafield_meta->setMasterPublishedRevision(0);

        $datafield_meta->setFieldName('New Field');
        $datafield_meta->setDescription('Field description.');
        $datafield_meta->setXmlFieldName('');
        $datafield_meta->setInternalReferenceName('');
        $datafield_meta->setRegexValidator('');
        $datafield_meta->setPhpValidator('');

        $datafield_meta->setMarkdownText('');
        $datafield_meta->setIsUnique(false);
        $datafield_meta->setRequired(false);
        $datafield_meta->setSearchable(0);
        $datafield_meta->setPublicDate( new \DateTime('2200-01-01 00:00:00') );

        $datafield_meta->setChildrenPerRow(1);
        $datafield_meta->setRadioOptionNameSort(0);
        $datafield_meta->setRadioOptionDisplayUnselected(0);
        if ( $fieldtype->getTypeClass() === 'File' || $fieldtype->getTypeClass() === 'Image' ) {
            $datafield_meta->setAllowMultipleUploads(1);
            $datafield_meta->setShortenFilename(1);
        }
        else {
            $datafield_meta->setAllowMultipleUploads(0);
            $datafield_meta->setShortenFilename(0);
        }
        $datafield_meta->setCreatedBy($user);
        $datafield_meta->setUpdatedBy($user);

        $em->persist($datafield_meta);
        $em->flush();
        $em->refresh($datafield_meta);

        if($datatype->getIsMasterType() > 0) {
            // A datafield publishes its own revision number.
            // This number will be incremented whenever a change is made
            // to the master data field.
            $dfm_properties['master_revision'] = $datafield_meta->getMasterRevision() + 1;
            self::ODR_copyDatafieldMeta($em, $user, $datafield, $dfm_properties);
        }

        // Add the datafield to all groups for this datatype
        /** @var PermissionsManagementService $pm_service */
        $pm_service = $this->container->get('odr.permissions_management_service');
        $pm_service->createGroupsForDatafield($user, $datafield);

        return array('datafield' => $datafield, 'datafield_meta' => $datafield_meta);
    }


    /**
     * Modifies a meta entry for a given DataField entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     *
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param DataFields $datafield            The DataField entity of the meta entry being modified
     * @param array $properties
     *
     * @return DataFieldsMeta
     */
    protected function ODR_copyDatafieldMeta($em, $user, $datafield, $properties)
    {
        // Load the old meta entry
        /** @var DataFieldsMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:DataFieldsMeta')->findOneBy( array('dataField' => $datafield->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // These entities can be set here since they're never null
            'fieldType' => $old_meta_entry->getFieldType()->getId(),
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

            'fieldName' => $old_meta_entry->getFieldName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_fieldName' => $old_meta_entry->getXmlFieldName(),
            'internal_reference_name' => $old_meta_entry->getInternalReferenceName(),
            'markdownText' => $old_meta_entry->getMarkdownText(),
            'regexValidator' => $old_meta_entry->getRegexValidator(),
            'phpValidator' => $old_meta_entry->getPhpValidator(),
            'required' => $old_meta_entry->getRequired(),
            'is_unique' => $old_meta_entry->getIsUnique(),
            'allow_multiple_uploads' => $old_meta_entry->getAllowMultipleUploads(),
            'shorten_filename' => $old_meta_entry->getShortenFilename(),
            'children_per_row' => $old_meta_entry->getChildrenPerRow(),
            'radio_option_name_sort' => $old_meta_entry->getRadioOptionNameSort(),
            'radio_option_display_unselected' => $old_meta_entry->getRadioOptionDisplayUnselected(),
            'searchable' => $old_meta_entry->getSearchable(),
            'publicDate' => $old_meta_entry->getPublicDate(),
            'master_revision' => $old_meta_entry->getMasterRevision(),
            'tracking_master_revision' => $old_meta_entry->getTrackingMasterRevision(),
            'master_published_revision' => $old_meta_entry->getMasterPublishedRevision(),
        );

        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value)
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datafield_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old DatafieldMeta entry
            $remove_old_entry = true;

            $new_datafield_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_datafield_meta->setCreated(new \DateTime());
            $new_datafield_meta->setUpdated(new \DateTime());
            $new_datafield_meta->setCreatedBy($user);
            $new_datafield_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datafield_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['fieldType']) )
            $new_datafield_meta->setFieldType( $em->getRepository('ODRAdminBundle:FieldType')->find( $properties['fieldType'] ) );
        if ( isset($properties['renderPlugin']) )
            $new_datafield_meta->setRenderPlugin( $em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        if ( isset($properties['fieldName']) )
            $new_datafield_meta->setFieldName( $properties['fieldName'] );
        if ( isset($properties['description']) )
            $new_datafield_meta->setDescription( $properties['description'] );
        if ( isset($properties['xml_fieldName']) )
            $new_datafield_meta->setXmlFieldName( $properties['xml_fieldName'] );
        if ( isset($properties['internal_reference_name']) )
            $new_datafield_meta->setInternalReferenceName( $properties['internal_reference_name'] );
        if ( isset($properties['markdownText']) )
            $new_datafield_meta->setMarkdownText( $properties['markdownText'] );
        if ( isset($properties['regexValidator']) )
            $new_datafield_meta->setRegexValidator( $properties['regexValidator'] );
        if ( isset($properties['phpValidator']) )
            $new_datafield_meta->setPhpValidator( $properties['phpValidator'] );
        if ( isset($properties['required']) )
            $new_datafield_meta->setRequired( $properties['required'] );
        if ( isset($properties['is_unique']) )
            $new_datafield_meta->setIsUnique( $properties['is_unique'] );
        if ( isset($properties['allow_multiple_uploads']) )
            $new_datafield_meta->setAllowMultipleUploads( $properties['allow_multiple_uploads'] );
        if ( isset($properties['shorten_filename']) )
            $new_datafield_meta->setShortenFilename( $properties['shorten_filename'] );
        if ( isset($properties['children_per_row']) )
            $new_datafield_meta->setChildrenPerRow( $properties['children_per_row'] );
        if ( isset($properties['radio_option_name_sort']) )
            $new_datafield_meta->setRadioOptionNameSort( $properties['radio_option_name_sort'] );
        if ( isset($properties['radio_option_display_unselected']) )
            $new_datafield_meta->setRadioOptionDisplayUnselected( $properties['radio_option_display_unselected'] );
        if ( isset($properties['searchable']) )
            $new_datafield_meta->setSearchable( $properties['searchable'] );
        if ( isset($properties['publicDate']) )
            $new_datafield_meta->setPublicDate( $properties['publicDate'] );
        if ( isset($properties['master_revision']) ) {
            $new_datafield_meta->setMasterRevision( $properties['master_revision'] );
        }
        // Check in case master revision needs to be updated.
        else if($datafield->getIsMasterField() > 0) {
            // We always increment the Master Revision for master data fields
            $new_datafield_meta->setMasterRevision($new_datafield_meta->getMasterRevision() + 1);
        }

        if ( isset($properties['tracking_master_revision']) )
            $new_datafield_meta->setTrackingMasterRevision( $properties['tracking_master_revision'] );
        if ( isset($properties['master_published_revision']) )
            $new_datafield_meta->setMasterPublishedRevision( $properties['master_published_revision'] );

        $new_datafield_meta->setUpdatedBy($user);

        //Save the new meta entry
        $em->persist($new_datafield_meta);
        $em->flush();
        $em->refresh($datafield);

        // Delete the old meta entry if necessary
        if ($remove_old_entry)
            $em->remove($old_meta_entry);


        // All metadata changes result in a new Data Field Master Published Revision.  Revision
        // changes are picked up by derivative data types when the parent data type revision is changed.
        if ($datafield->getIsMasterField() > 0) {
            $datatype = $datafield->getDataType();
            $properties['master_revision'] = $datatype->getDataTypeMeta()->getMasterRevision() + 1;
            self::ODR_copyDatatypeMeta($em, $user, $datatype, $properties);
        }

        // Return the new entry
        return $new_datafield_meta;
    }


    /**
     * Copies the contents of the given ThemeMeta entity into a new ThemeMeta entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'templateName', 'templateDescription', 'isDefault'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the modification of this meta entry.
     * @param Theme $theme                     The Theme entity being modified
     * @param array $properties
     *
     * @return ThemeMeta
     */
    protected function ODR_copyThemeMeta($em, $user, $theme, $properties)
    {
        // Load the old meta entry
        /** @var ThemeMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ThemeMeta')->findOneBy( array('theme' => $theme->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'templateName' => $old_meta_entry->getTemplateName(),
            'templateDescription' => $old_meta_entry->getTemplateDescription(),
            'isDefault' => $old_meta_entry->getIsDefault(),
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'shared' => $old_meta_entry->getShared(),
            'sourceSyncVersion' => $old_meta_entry->getSourceSyncVersion(),
            'isTableTheme' => $old_meta_entry->getIsTableTheme(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ThemeMeta entry
            $remove_old_entry = true;

            $new_theme_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_meta->setCreated(new \DateTime());
            $new_theme_meta->setUpdated(new \DateTime());
            $new_theme_meta->setCreatedBy($user);
            $new_theme_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['templateName']) )
            $new_theme_meta->setTemplateName( $properties['templateName'] );
        if ( isset($properties['templateDescription']) )
            $new_theme_meta->setTemplateDescription( $properties['templateDescription'] );
        if ( isset($properties['isDefault']) )
            $new_theme_meta->setIsDefault( $properties['isDefault'] );
        if ( isset($properties['displayOrder']) )
            $new_theme_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['shared']) )
            $new_theme_meta->setShared( $properties['shared'] );
        if ( isset($properties['sourceSyncVersion']) )
            $new_theme_meta->setSourceSyncVersion( $properties['sourceSyncVersion'] );

        if ( isset($properties['isTableTheme']) ) {
            $new_theme_meta->setIsTableTheme( $properties['isTableTheme'] );

            if ($theme->getThemeType() == 'search_results' && $new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('table');
                $em->persist($theme);
            }
            else if ($theme->getThemeType() == 'table' && !$new_theme_meta->getIsTableTheme()) {
                $theme->setThemeType('search_results');
                $em->persist($theme);
            }
        }

        $new_theme_meta->setUpdatedBy($user);


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_theme_meta);
        $em->flush();
        $em->refresh($theme);

        // Return the new entry
        return $new_theme_meta;
    }


    /**
     * Creates and persists a new ThemeElement entity.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the creation of this entity
     * @param Theme $theme
     *
     * @return array
     */
    protected function ODR_addThemeElement($em, $user, $theme)
    {
        $theme_element = new ThemeElement();
        $theme_element->setTheme($theme);

        $theme_element->setCreatedBy($user);

        $em->persist($theme_element);
        $em->flush();
        $em->refresh($theme_element);


        $theme_element_meta = new ThemeElementMeta();
        $theme_element_meta->setThemeElement($theme_element);

        $theme_element_meta->setDisplayOrder(-1);
        $theme_element_meta->setHidden(0);
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');

        $theme_element_meta->setCreatedBy($user);
        $theme_element_meta->setUpdatedBy($user);

        $theme_element->addThemeElementMetum($theme_element_meta);
        $em->persist($theme_element_meta);

        return array('theme_element' => $theme_element, 'theme_element_meta' => $theme_element_meta);
    }


    /**
     * Modifies a meta entry for a given ThemeElement entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'displayOrder', 'cssWidthMed', 'cssWidthXL'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeElement $theme_element     The ThemeElement entity of the meta entry being modified
     * @param array $properties
     *
     * @return ThemeElementMeta
     */
    protected function ODR_copyThemeElementMeta($em, $user, $theme_element, $properties)
    {
        // Load the old meta entry
        /** @var ThemeElementMeta $old_meta_entry */
        $old_meta_entry = $em->getRepository('ODRAdminBundle:ThemeElementMeta')->findOneBy( array('themeElement' => $theme_element->getId()) );

        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'displayOrder' => $old_meta_entry->getDisplayOrder(),
            'hidden' => $old_meta_entry->getHidden(),
            'cssWidthMed' => $old_meta_entry->getCssWidthMed(),
            'cssWidthXL' => $old_meta_entry->getCssWidthXL(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $theme_element_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Clone the old ThemeelementMeta entry
            $remove_old_entry = true;

            $theme_element_meta = clone $old_meta_entry;

            // These properties aren't automatically updated when persisting the cloned entity...
            $theme_element_meta->setCreated(new \DateTime());
            $theme_element_meta->setUpdated(new \DateTime());
            $theme_element_meta->setCreatedBy($user);
            $theme_element_meta->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $theme_element_meta = $old_meta_entry;
        }


        // Set any changed properties
        if ( isset($properties['displayOrder']) )
            $theme_element_meta->setDisplayOrder( $properties['displayOrder'] );
        if ( isset($properties['hidden']) )
            $theme_element_meta->setHidden( $properties['hidden'] );
        if ( isset($properties['cssWidthMed']) )
            $theme_element_meta->setCssWidthMed( $properties['cssWidthMed'] );
        if ( isset($properties['cssWidthXL']) )
            $theme_element_meta->setCssWidthXL( $properties['cssWidthXL'] );

        $theme_element_meta->setUpdatedBy($user);


        // Remove old meta entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($theme_element_meta);
        $em->flush();
        $em->refresh($theme_element);

        // Return the meta entry
        return $theme_element_meta;
    }


    /**
     * Creates and persists a new ThemeDataField entity.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                  The user requesting the creation of this entity.
     * @param DataFields $datafield       The datafield this entry is for
     * @param ThemeElement $theme_element The theme_element this entry is attached to
     *
     * @return ThemeDataField
     */
    protected function ODR_addThemeDataField($em, $user, $datafield, $theme_element)
    {
        // Create theme entry
        $theme_datafield = new ThemeDataField();
        $theme_datafield->setDataField($datafield);
        $theme_datafield->setThemeElement($theme_element);

        $theme_datafield->setDisplayOrder(999);
        $theme_datafield->setCssWidthMed('1-3');
        $theme_datafield->setCssWidthXL('1-3');
        $theme_datafield->setHidden(0);

        $theme_datafield->setCreatedBy($user);
        $theme_datafield->setUpdatedBy($user);

        $theme_element->addThemeDataField($theme_datafield);
        $em->persist($theme_datafield);

        return $theme_datafield;
    }


    /**
     * Copies the contents of the given ThemeDatafield entity into a new ThemeDatafield entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'themeElement', 'displayOrder', 'cssWidthMed', 'cssWidthXL'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeDatafield $theme_datafield The ThemeDatafield entity being modified
     * @param array $properties
     *
     * @return ThemeDataField
     */
    protected function ODR_copyThemeDatafield($em, $user, $theme_datafield, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            // This entity can be set here since it's never null
            'themeElement' => $theme_datafield->getThemeElement()->getId(),

            'displayOrder' => $theme_datafield->getDisplayOrder(),
            'cssWidthMed' => $theme_datafield->getCssWidthMed(),
            'cssWidthXL' => $theme_datafield->getCssWidthXL(),
            'hidden' => $theme_datafield->getHidden(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datafield;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_datafield = null;
        if ( self::createNewMetaEntry($user, $theme_datafield) ) {
            // Clone the old ThemeDatafield entry
            $remove_old_entry = true;

            $new_theme_datafield = clone $theme_datafield;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_datafield->setCreated(new \DateTime());
            $new_theme_datafield->setUpdated(new \DateTime());
            $new_theme_datafield->setCreatedBy($user);
            $new_theme_datafield->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datafield = $theme_datafield;
        }


        // Set any new properties
        if (isset($properties['themeElement']))
            $new_theme_datafield->setThemeElement( $em->getRepository('ODRAdminBundle:ThemeElement')->find($properties['themeElement']) );

        if (isset($properties['displayOrder']))
            $new_theme_datafield->setDisplayOrder( $properties['displayOrder'] );
        if (isset($properties['cssWidthMed']))
            $new_theme_datafield->setCssWidthMed( $properties['cssWidthMed'] );
        if (isset($properties['cssWidthXL']))
            $new_theme_datafield->setCssWidthXL( $properties['cssWidthXL'] );
        if (isset($properties['hidden']))
            $new_theme_datafield->setHidden( $properties['hidden'] );

        $new_theme_datafield->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($theme_datafield);

        // Save the new meta entry
        $em->persist($new_theme_datafield);
        $em->flush();

        // Return the new entry
        return $new_theme_datafield;
    }


    /**
     * Creates and persists a new ThemeDataType entity.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                  The user requesting the creation of this entity
     * @param DataType $datatype          The datatype this entry is for
     * @param ThemeElement $theme_element The theme_element this entry is attached to
     * @param Theme $child_theme
     *
     * @return ThemeDataType
     */
    protected function ODR_addThemeDatatype($em, $user, $datatype, $theme_element, $child_theme)
    {
        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);
        $theme_datatype->setChildTheme($child_theme);

        $theme_datatype->setDisplayType(0);     // 0 is accordion, 1 is tabbed, 2 is dropdown, 3 is list
        $theme_datatype->setHidden(0);

        $theme_datatype->setCreatedBy($user);
        $theme_datatype->setUpdatedBy($user);

        $theme_element->addThemeDataType($theme_datatype);
        $em->persist($theme_datatype);

        return $theme_datatype;
    }


    /**
     * Copies the contents of the given ThemeDatatype entity into a new ThemeDatatype entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'display_type'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                      The user requesting the modification of this meta entry.
     * @param ThemeDataType $theme_datatype   The ThemeDatafield entity being modified
     * @param array $properties
     *
     * @return ThemeDataType
     */
    protected function ODR_copyThemeDatatype($em, $user, $theme_datatype, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'display_type' => $theme_datatype->getDisplayType(),
            'hidden' => $theme_datatype->getHidden(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $theme_datatype;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_theme_datatype = null;
        if ( self::createNewMetaEntry($user, $theme_datatype) ) {
            // Clone the old ThemeDatatype entry
            $remove_old_entry = true;

            $new_theme_datatype = clone $theme_datatype;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_theme_datatype->setCreated(new \DateTime());
            $new_theme_datatype->setUpdated(new \DateTime());
            $new_theme_datatype->setCreatedBy($user);
            $new_theme_datatype->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datatype = $theme_datatype;
        }


        // Set any new properties
        if (isset($properties['display_type']))
            $new_theme_datatype->setDisplayType( $properties['display_type'] );
        if (isset($properties['hidden']))
            $new_theme_datatype->setHidden( $properties['hidden'] );

        $new_theme_datatype->setUpdatedBy($user);


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $em->remove($theme_datatype);

        // Save the new meta entry
        $em->persist($new_theme_datatype);
        $em->flush();

        // Return the new entry
        return $new_theme_datatype;
    }


    /**
     * Creates, persists, and flushes a new RenderPluginInstance entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param RenderPlugin $render_plugin
     * @param DataType|null $datatype
     * @param DataFields|null $datafield
     *
     * @throws \Exception
     *
     * @return RenderPluginInstance
     */
    protected function ODR_addRenderPluginInstance($em, $user, $render_plugin, $datatype, $datafield)
    {
        // Ensure a RenderPlugin for a Datatype plugin doesn't get assigned to a Datafield, or a RenderPlugin for a Datafield doesn't get assigned to a Datatype
        if ( $render_plugin->getPluginType() == RenderPlugin::DATATYPE_PLUGIN && is_null($datatype) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datatype');
        else if ( $render_plugin->getPluginType() == RenderPlugin::DATAFIELD_PLUGIN && is_null($datafield) )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datafield');

        // Create the new RenderPluginInstance
        $rpi = new RenderPluginInstance();
        $rpi->setRenderPlugin($render_plugin);
        $rpi->setDataType($datatype);
        $rpi->setDataField($datafield);

        $rpi->setActive(true);

        $rpi->setCreatedBy($user);
        $rpi->setUpdatedBy($user);

        $em->persist($rpi);
        $em->flush();
        $em->refresh($rpi);

        return $rpi;
    }


    /**
     * Creates and persists a new RenderPluginMap entity.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param RenderPluginInstance $render_plugin_instance
     * @param RenderPluginFields $render_plugin_fields
     * @param DataType|null $datatype
     * @param DataFields $datafield
     *
     * @return RenderPluginMap
     */
    protected function ODR_addRenderPluginMap($em, $user, $render_plugin_instance, $render_plugin_fields, $datatype, $datafield)
    {
        $rpm = new RenderPluginMap();
        $rpm->setRenderPluginInstance($render_plugin_instance);
        $rpm->setRenderPluginFields($render_plugin_fields);

        $rpm->setDataType($datatype);
        $rpm->setDataField($datafield);

        $rpm->setCreatedBy($user);
        $rpm->setUpdatedBy($user);

        $em->persist($rpm);

        return $rpm;
    }


    /**
     * Copies the contents of the given RenderPluginMap entity into a new RenderPluginMap entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'dataField'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param RenderPluginMap $render_plugin_map
     * @param array $properties
     *
     * @return bool
     */
    protected function ODR_copyRenderPluginMap($em, $user, $render_plugin_map, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'dataField' => $render_plugin_map->getDataField()->getId(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
//            return $render_plugin_map;
            return false;

        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpm = null;
        if ( self::createNewMetaEntry($user, $render_plugin_map) ) {
            // Clone the old RenderPluginMap entry
            $remove_old_entry = true;

            $new_rpm = clone $render_plugin_map;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_rpm->setCreated(new \DateTime());
            $new_rpm->setUpdated(new \DateTime());
            $new_rpm->setCreatedBy($user);
            $new_rpm->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpm = $render_plugin_map;
        }


        // Set any new properties
        if (isset($properties['dataField']))
            $new_rpm->setDataField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['dataField']) );

        $new_rpm->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($render_plugin_map);

        // Save the new meta entry
        $em->persist($new_rpm);
        $em->flush();

        // Return the new entry
//        return $new_rpm;
        return true;
    }


    /**
     * Creates and persists a new RenderPluginOption entity.  The caller needs to flush afterwards.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param $render_plugin_instance
     * @param $option_name
     * @param $option_value
     *
     * @return RenderPluginOptions
     */
    protected function ODR_addRenderPluginOption($em, $user, $render_plugin_instance, $option_name, $option_value)
    {
        $rpo = new RenderPluginOptions();
        $rpo->setRenderPluginInstance($render_plugin_instance);
        $rpo->setOptionName($option_name);
        $rpo->setOptionValue($option_value);

        $rpo->setActive(true);

        $rpo->setCreatedBy($user);
        $rpo->setUpdatedBy($user);

        $em->persist($rpo);

        return $rpo;
    }


    /**
     * Copies the contents of the given RenderPluginOptions entity into a new RenderPluginOptions entity if something was changed
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'optionValue'
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param RenderPluginOptions $render_plugin_option
     * @param array $properties
     *
     * @return bool
     */
    protected function ODR_copyRenderPluginOption($em, $user, $render_plugin_option, $properties)
    {
        // No point making a new entry if nothing is getting changed
        $changes_made = false;
        $existing_values = array(
            'optionValue' => $render_plugin_option->getOptionValue(),
        );
        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
//            return $render_plugin_option;
            return false;

        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpo = null;
        if ( self::createNewMetaEntry($user, $render_plugin_option) ) {
            // Clone the old RenderPluginOptions entry
            $remove_old_entry = true;

            $new_rpo = clone $render_plugin_option;

            // These properties aren't automatically updated when persisting the cloned entity...
            $new_rpo->setCreated(new \DateTime());
            $new_rpo->setUpdated(new \DateTime());
            $new_rpo->setCreatedBy($user);
            $new_rpo->setUpdatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_rpo = $render_plugin_option;
        }


        // Set any new properties
        if (isset($properties['optionValue']))
            $new_rpo->setOptionValue( $properties['optionValue'] );

        $new_rpo->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($render_plugin_option);

        // Save the new meta entry
        $em->persist($new_rpo);
        $em->flush();

        // Return the new entry
//        return $new_rpo;
        return true;
    }


    /**
     * Usually called after an image is uploaded, this resizes the uploaded image for use in different areas.
     * Will automatically attempt to replace existing thumbnails if possible.
     *
     * NOTE: all thumbnails for the provided image will have their decrypted version deleted off the server...if for some reason you need it immediately after calling this function, you'll have to use decryptObject() to re-create it
     *
     * @param Image $my_obj The Image that was just uploaded.
     * @param User $user    The user requesting this action
     *
     */
    public function resizeImages(\ODR\AdminBundle\Entity\Image $my_obj, $user)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_image = $em->getRepository('ODRAdminBundle:Image');
//        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        // Create Thumbnails
        /** @var ImageSizes[] $sizes */
        $sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $my_obj->getDataField()->getId()) );

        foreach ($sizes as $size) {
            // Set original
            if ($size->getOriginal()) {
                $my_obj->setImageSize($size);
                $em->persist($my_obj);
            }
            else {
                $proportional = false;
                if ($size->getSizeConstraint() == "width"
                    || $size->getSizeConstraint() == "height"
                    || $size->getSizeConstraint() == "both"
                ) {
                    $proportional = true;
                }

                $filename = sha1(uniqid(mt_rand(), true));
                $ext = pathinfo($my_obj->getLocalFileName(), PATHINFO_EXTENSION);
                $new_file_path = "/tmp/" . $filename . "." . $ext;
                copy($my_obj->getLocalFileName(), $new_file_path);

                // resize file
                self::smart_resize_image(
                    $new_file_path,
                    $size->getWidth(),
                    $size->getHeight(),
                    $proportional,
                    'file',
                    false,
                    false
                );

                // Attempt to locate an already existing thumbnail for overwrite
                /** @var Image $image */
                $image = $repo_image->findOneBy( array('parent' => $my_obj->getId(), 'imageSize' => $size->getId()) );

                // If thumbnail doesn't exist, create a new image entity
                if ($image == null) {
                    $image = new Image();
                    $image->setDataField($my_obj->getDataField());
                    $image->setFieldType($my_obj->getFieldType());
                    $image->setDataRecord($my_obj->getDataRecord());
                    $image->setDataRecordFields($my_obj->getDataRecordFields());

                    $image->setOriginal(0);
                    $image->setImageSize($size);
                    $image->setParent($my_obj);
                    $image->setExt($my_obj->getExt());
                    $image->setOriginalChecksum('');

                    $image->setCreatedBy($user);

                    /* DO NOT create a new metadata entry for the thumbnail...all of its metadata properties are slaved to the parent image */
                }

                $em->persist($image);
                $em->flush();

                // Copy temp file to new file name
                $filename = $image->getUploadDir()."/Image_" . $image->getId() . "." . $ext;
                copy($new_file_path, $filename);
                $image->setLocalFileName($filename);

                /** @var int[] $sizes */
                $sizes = getimagesize($filename);
                $image->setImageWidth( $sizes[0] );
                $image->setImageHeight( $sizes[1] );
                $em->persist($image);
                $em->flush();

                // Encrypt thumbnail AFTER everything else is done
                self::encryptObject($image->getId(), 'image');

                // Set original checksum for thumbnail
                $file_path = self::decryptObject($image->getId(), 'image');
                $original_checksum = md5_file($file_path);
                $image->setOriginalChecksum($original_checksum);

                $em->persist($image);
                $em->flush();

                // A decrypted version of this thumbnail still exists on the server...delete it here since all its properties have been saved
                unlink($file_path);
            }
        }
    }


    /**
     * Locates and returns a datarecord based on its external id
     * @todo - move to datarecord info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datafield_id
     * @param string $external_id_value
     *
     * @return DataRecord|null
     */
    protected function getDatarecordByExternalId($em, $datafield_id, $external_id_value)
    {
        // Get required information
        /** @var DataFields $datafield */
        $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
        $typeclass = $datafield->getFieldType()->getTypeClass();

        // Attempt to locate the datarecord using the given external id
        $query = $em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$typeclass.' AS e
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH e.dataRecordFields = drf
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf.dataRecord = dr
            WHERE e.dataField = :datafield AND e.value = :value
            AND e.deletedAt IS NULL AND drf.deletedAt IS NULL AND dr.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield_id, 'value' => $external_id_value) );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Locates and returns a child datarecord based on its external id and its parent's external id
     * @todo - move to datarecord info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $child_datafield_id
     * @param string $child_external_id_value
     * @param integer $parent_datafield_id
     * @param string $parent_external_id_value
     *
     * @return DataRecord|null
     */
    protected function getChildDatarecordByExternalId($em, $child_datafield_id, $child_external_id_value, $parent_datafield_id, $parent_external_id_value)
    {
        // Get required information
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

        /** @var DataFields $child_datafield */
        $child_datafield = $repo_datafield->find($child_datafield_id);
        $child_typeclass = $child_datafield->getFieldType()->getTypeClass();

        /** @var DataFields $parent_datafield */
        $parent_datafield = $repo_datafield->find($parent_datafield_id);
        $parent_typeclass = $parent_datafield->getFieldType()->getTypeClass();

        // Attempt to locate the datarecord using the given external id
        $query = $em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:'.$child_typeclass.' AS e_1
            JOIN ODRAdminBundle:DataRecordFields AS drf_1 WITH e_1.dataRecordFields = drf_1
            JOIN ODRAdminBundle:DataRecord AS dr WITH drf_1.dataRecord = dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecordFields AS drf_2 WITH drf_2.dataRecord = parent
            JOIN ODRAdminBundle:'.$parent_typeclass.' AS e_2 WITH e_2.dataRecordFields = drf_2
            WHERE dr.id != parent.id AND e_1.dataField = :child_datafield AND e_1.value = :child_value AND e_2.dataField = :parent_datafield AND e_2.value = :parent_value
            AND e_1.deletedAt IS NULL AND drf_1.deletedAt IS NULL AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND drf_2.deletedAt IS NULL AND e_2.deletedAt IS NULL'
        )->setParameters( array('child_datafield' => $child_datafield_id, 'child_value' => $child_external_id_value, 'parent_datafield' => $parent_datafield_id, 'parent_value' => $parent_external_id_value) );
        $results = $query->getResult();

        // Return the datarecord if it exists
        $datarecord = null;
        if ( isset($results[0]) )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Locates and returns a single child datarecord based on its parent's external id...this assumes
     * that only a single child datarecord is allowed in this child datatype
     * @todo - move to datarecord info service?
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $child_datatype_id
     * @param integer $parent_datafield_id
     * @param string $parent_external_id_value
     *
     * @return DataRecord|null
     */
    protected function getChildDatarecordByParent($em, $child_datatype_id, $parent_datafield_id, $parent_external_id_value)
    {
        // Get required information
        $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

        /** @var DataFields $parent_datafield */
        $parent_datafield = $repo_datafield->find($parent_datafield_id);
        $parent_typeclass = $parent_datafield->getFieldType()->getTypeClass();

        // Attempt to locate the datarecord using the given external id
        $query = $em->createQuery(
           'SELECT dr
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = parent
            JOIN ODRAdminBundle:'.$parent_typeclass.' AS e WITH e.dataRecordFields = drf
            WHERE dr.dataType = :datatype_id AND e.value = :parent_value AND e.dataField = :parent_datafield
            AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND drf.deletedAt IS NULL AND e.deletedAt IS NULL'
        )->setParameters( array('datatype_id' => $child_datatype_id, 'parent_value' => $parent_external_id_value, 'parent_datafield' => $parent_datafield_id) );
        $results = $query->getResult();

        // Return the datarecord if it exists, and also return null if there's more than one...the
        //  function is called to determine whether the parent datarecord has a single child datarecord
        //  that it can overwrite during importing
        $datarecord = null;
        if ( isset($results[0]) && count($results) == 1 )
            $datarecord = $results[0];

        return $datarecord;
    }


    /**
     * Does the actual work of resizing an image to some arbitrary dimension.
     * TODO - need source for this...pretty sure it's copy/pasted from somewhere
     *
     * @param string $file                Should be a path to the file
     * @param integer $width              Desired width for the resulting thumbnail
     * @param integer $height             Desired height for the resulting thumbnail
     * @param boolean $proportional       Whether to preserve aspect ratio while resizing
     * @param string $output              'browser', 'file', or 'return'
     * @param boolean $delete_original    Whether to delete the original file or not after resizing
     * @param boolean $use_linux_commands If true, use linux commands to delete the original file, otherwise use windows commands
     *
     * @return array Contains height/width after resizing
     */
    public static function smart_resize_image(
        $file,
        $width              = 0,
        $height             = 0,
        $proportional       = false,
        $output             = 'file',
        $delete_original    = true,
        $use_linux_commands = false
    ) {

        if ( $height <= 0 && $width <= 0 ) return false;

        # Setting defaults and meta
        $info                         = getimagesize($file);
        $image                        = '';
        $final_width                  = 0;
        $final_height                 = 0;

        list($width_old, $height_old) = $info;

        # Calculating proportionality
        if ($proportional) {
            if      ($width  == 0)  $factor = $height/$height_old;
            elseif  ($height == 0)  $factor = $width/$width_old;
            else                    $factor = min( $width / $width_old, $height / $height_old );

            $final_width  = round( $width_old * $factor );
            $final_height = round( $height_old * $factor );
        }
        else {
            $final_width = ( $width <= 0 ) ? $width_old : $width;
            $final_height = ( $height <= 0 ) ? $height_old : $height;
        }

        # Loading image to memory according to type
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   $image = imagecreatefromgif($file);   break;
            case IMAGETYPE_JPEG:  $image = imagecreatefromjpeg($file);  break;
            case IMAGETYPE_PNG:   $image = imagecreatefrompng($file);   break;
            case IMAGETYPE_WBMP:   $image = imagecreatefromwbmp($file);   break;
            default: return false;
        }

        # This is the resizing/resampling/transparency-preserving magic
        $image_resized = imagecreatetruecolor( $final_width, $final_height );
        if ( ($info[2] == IMAGETYPE_GIF) || ($info[2] == IMAGETYPE_PNG) ) {
            $transparency = imagecolortransparent($image);

            if ($transparency >= 0) {
                // TODO figure out what trnprt_index is used for.
                $trnprt_indx = null;
                $transparent_color  = imagecolorsforindex($image, $trnprt_indx);
                $transparency       = imagecolorallocate($image_resized, $transparent_color['red'], $transparent_color['green'], $transparent_color['blue']);
                imagefill($image_resized, 0, 0, $transparency);
                imagecolortransparent($image_resized, $transparency);
            }
            elseif ($info[2] == IMAGETYPE_PNG) {
                imagealphablending($image_resized, false);
                $color = imagecolorallocatealpha($image_resized, 0, 0, 0, 127);
                imagefill($image_resized, 0, 0, $color);
                imagesavealpha($image_resized, true);
            }
        }
        imagecopyresampled($image_resized, $image, 0, 0, 0, 0, $final_width, $final_height, $width_old, $height_old);

        # Taking care of original, if needed
        if ( $delete_original ) {
            if ( $use_linux_commands ) exec('rm '.$file);
            else @unlink($file);
        }

        # Preparing a method of providing result
        switch ( strtolower($output) ) {
            case 'browser':
                $mime = image_type_to_mime_type($info[2]);
                header("Content-type: $mime");
                $output = NULL;
                break;
            case 'file':
                $output = $file;
                break;
            case 'return':
                return $image_resized;
                break;
            default:
                break;
        }

        # Writing image according to type to the output destination
        switch ( $info[2] ) {
            case IMAGETYPE_GIF:   imagegif($image_resized, $output );    break;
            case IMAGETYPE_JPEG:  imagejpeg($image_resized, $output, '90');   break;
            case IMAGETYPE_PNG:   imagepng($image_resized, $output, '2');    break;
            default: return false;
        }

        $stats = array($final_height, $final_width);
        return $stats;
    }


    /**
     * TODO - generalize this to support more than just two?
     * Ensures both ImageSizes entities for the given datafield exist.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                       The user requesting the creation of this entity
     * @param DataFields $datafield
     */
    public function ODR_checkImageSizes($em, $user, $datafield)
    {
        // Attempt to load both ImageSize entities from the database
        $query = $em->createQuery(
           'SELECT image_size
            FROM ODRAdminBundle:ImageSizes AS image_size
            WHERE image_size.dataFields = :datafield
            AND image_size.deletedAt IS NULL'
        )->setParameters( array('datafield' => $datafield->getId()) );
        $results = $query->getArrayResult();

        // Determine if either are missing
        $has_original = false;
        $has_thumbnail = false;

        foreach ($results as $num => $result) {
            $original = $result['original'];
            $image_type = $result['imagetype'];

            if ( $original == true )
                $has_original = true;
            if ( $original == null && $image_type == 'thumbnail' )
                $has_thumbnail = true;
        }

        if (!$has_original) {
            // Create an ImageSize entity for the original image
            $query =
               'INSERT INTO odr_image_sizes (data_fields_id, size_constraint, min_width, width, max_width, min_height, height, max_height, original, created, createdBy, updated, updatedBy)
                SELECT * FROM (
                    SELECT :df_id AS data_fields_id, :size_constraint AS size_constraint,
                        :min_width AS min_width, :width AS width, :max_width AS max_width,
                        :min_height AS min_height, :height AS height, :max_height AS max_height,
                        :original AS original,
                        NOW() AS created, :created_by AS createdBy, NOW() AS updated, :updated_by AS updatedBy
                ) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_image_sizes WHERE data_fields_id = :df_id AND size_constraint = :size_constraint AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array(
                'df_id' => $datafield->getId(),
                'size_constraint' => 'none',

                'min_width' => 1024,
                'width' => 0,
                'max_width' => 0,
                'min_height' => 768,
                'height' => 0,
                'max_height' => 0,

                'original' => 1,
//                'imagetype' => null,
                'created_by' => $user->getId(),
                'updated_by' => $user->getId(),
            );

            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            // Reload the newly created ImageSize entity
            /** @var ImageSizes $image_size */
            $image_size = $em->getRepository('ODRAdminBundle:ImageSizes')->findOneBy( array('dataFields' => $datafield->getId(), 'size_constraint' => 'none') );
        }

        if (!$has_thumbnail) {
            // Create an ImageSize entity for the thumbnail
            $query =
               'INSERT INTO odr_image_sizes (data_fields_id, size_constraint, min_width, width, max_width, min_height, height, max_height, original, imagetype, created, createdBy, updated, updatedBy)
                SELECT * FROM (
                    SELECT :df_id AS data_fields_id, :size_constraint AS size_constraint,
                        :min_width AS min_width, :width AS width, :max_width AS max_width,
                        :min_height AS min_height, :height AS height, :max_height AS max_height,
                        :original AS original, :imagetype AS imagetype,
                        NOW() AS created, :created_by AS createdBy, NOW() AS updated, :updated_by AS updatedBy
                ) AS tmp
                WHERE NOT EXISTS (
                    SELECT id FROM odr_image_sizes WHERE data_fields_id = :df_id AND size_constraint = :size_constraint AND deletedAt IS NULL
                ) LIMIT 1;';
            $params = array(
                'df_id' => $datafield->getId(),
                'size_constraint' => 'both',

                'min_width' => 500,
                'width' => 500,
                'max_width' => 500,
                'min_height' => 375,
                'height' => 375,
                'max_height' => 375,

                'original' => 0,
                'imagetype' => 'thumbnail',
                'created_by' => $user->getId(),
                'updated_by' => $user->getId(),
            );
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query, $params);

            // Reload the newly created ImageSize entity
            $image_size = $em->getRepository('ODRAdminBundle:ImageSizes')->findOneBy( array('dataFields' => $datafield->getId(), 'size_constraint' => 'both') );
        }
    }


    /**
     * Returns errors encounted while processing a Symfony Form object as a string.
     *
     * @param \Symfony\Component\Form\Form $form
     *
     * @return string
     */
    protected function ODR_getErrorMessages(\Symfony\Component\Form\Form $form)
    {
        // Get all errors in this form, including those from the form's children
        $errors = $form->getErrors(true);

        $error_str = '';
        while( $errors->valid() ) {
            $error_str .= 'ERROR: '.$errors->current()->getMessage()."\n";
            $errors->next();
        }

        return $error_str;
    }


    /**
     * @deprecated
     *
     * Synchronizes the given theme with its source theme if needed, and returns whether to notify
     *  the user it did so.  At the moment, a notification isn't needed when the synchronization adds
     *  a datafield/datatype that the user can't view due to permissions.
     *
     * @param Theme $theme
     * @param User $user
     *
     * @return bool
     */
    protected function notifyOfThemeSync($theme, $user)
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var CloneThemeService $clone_theme_service */
        $clone_theme_service = $this->container->get('odr.clone_theme_service');
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
        foreach ($results as $t) {
            $current_theme_version = $t->getSourceSyncVersion();
            $source_theme_version = $t->getSourceTheme()->getSourceSyncVersion();

            if ( $current_theme_version !== $source_theme_version ) {
                $properties = array(
                    'sourceSyncVersion' => $source_theme_version
                );
                self::ODR_copyThemeMeta($em, $user, $t, $properties);
            }
        }


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
