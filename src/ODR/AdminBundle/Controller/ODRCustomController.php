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
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\FileChecksum;
use ODR\AdminBundle\Entity\FileMeta;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageChecksum;
use ODR\AdminBundle\Entity\ImageMeta;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedError;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\CloneThemeService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\EntityMetaModifyService;
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
     * @deprecated
     *
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
        $sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataField' => $my_obj->getDataField()->getId()) );

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
     * TODO - move to datarecord info service?
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
     * TODO - move to datarecord info service?
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
     * TODO - move to datarecord info service?
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
     * @deprecated Want to replace with ODRRenderService...
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
