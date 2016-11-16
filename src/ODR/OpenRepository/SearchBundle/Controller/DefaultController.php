<?php

/**
* Open Data Repository Data Publisher
* Search Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The search controller handles rendering of search pages and
* the actual process of searching.
*
* The rendering of the results from searching is handled by
* ODRCustomController.
*/

namespace ODR\OpenRepository\SearchBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Controllers/Classes
use ODR\AdminBundle\Controller\ODRCustomController;
// Entites
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTypeMeta;
use ODR\AdminBundle\Entity\Theme;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;


class DefaultController extends Controller
{

    /**
     * Provides error display capability to various parts of this controller.
     *
     * @param string $error_message
     * @param Request $request
     * @param boolean $inline
     *
     * @return Response
     */
    public function searchPageError($error_message, Request $request, $inline = false)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $html = '';

        try
        {
            // Grab user and their permissions if possible
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

            // Store if logged in or not
            $logged_in = true;
            if ($user === 'anon.') {
                $user = null;
                $logged_in = false;
            }

            if ($inline) {
                $templating = $this->get('templating');
                $return['d'] = array(
                    'html' => $templating->render(
                        'ODROpenRepositorySearchBundle:Default:searchpage_error.html.twig',
                        array(
                            'logged_in' => $logged_in,
                            'error_message' => $error_message,
                        )
                    )
                );
            }
            else {
                // Grab user permissions if possible
                $datatype_permissions = array();
                if ($logged_in) {
                    /** @var ODRCustomController $odrcc */
                    $odrcc = $this->get('odr_custom_controller', $request);
                    $odrcc->setContainer($this->container);

                    /** @var \Doctrine\ORM\EntityManager $em */
                    $em = $this->getDoctrine()->getManager();
                    $user_permissions = $odrcc->getUserPermissionsArray($em, $user->getId());
                    $datatype_permissions = $user_permissions['datatypes'];
                }

                $site_baseurl = $this->container->getParameter('site_baseurl');
                if ($this->container->getParameter('kernel.environment') === 'dev')
                    $site_baseurl .= '/app_dev.php';

                // Generate a random key to identify this tab
                $tokenGenerator = $this->container->get('fos_user.util.token_generator');
                $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);

                $html = $this->renderView(
                    'ODROpenRepositorySearchBundle:Default:index_error.html.twig',
                    array(
                        // required twig/javascript parameters
                        'user' => $user,
                        'user_permissions' => $datatype_permissions,

//                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
                        'window_title' => 'ODR Admin',
                        'source' => 'searching',
                        'search_slug' => 'admin',
                        'site_baseurl' => $site_baseurl,
                        'search_string' => '',
                        'odr_tab_id' => $odr_tab_id,

                        'error_message' => $error_message,
                    )
                );
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x22127327 ' . $e->getMessage();
        }

        $response = null;
        if ($inline) {
            $response = new Response(json_encode($return));
            $response->headers->set('Content-Type', 'application/json');
        }
        else {
            $response = new Response($html);
            $response->headers->set('Content-Type', 'text/html');
        }

        return $response;
    }


    /**
     * Returns a list of all datatypes which are either children or linked to an optional target datatype, minus the ones a user doesn't have permissions to see
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $target_datatype_id      If set, which top-level datatype to save child/linked datatypes for
     * @param array $datatype_permissions      If set, the current user's permissions array
     *
     * @return array
     */
    private function getRelatedDatatypes($em, $target_datatype_id = null, $datatype_permissions = array())
    {
        // Grab all entities out of the 
        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, ancestor_meta.shortName AS ancestor_name, descendant.id AS descendant_id, descendant_meta.shortName AS descendant_name, descendant_meta.publicDate AS public_date, dtm.is_link AS is_link
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataTypeMeta AS ancestor_meta WITH ancestor_meta.dataType = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            JOIN ODRAdminBundle:DataTypeMeta AS descendant_meta WITH descendant_meta.dataType = descendant
            WHERE dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND ancestor_meta.deletedAt IS NULL AND descendant.deletedAt IS NULL AND descendant_meta.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatype_names = array();
        $descendant_of = array();
        $links = array();
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $ancestor_name = $result['ancestor_name'];
            $descendant_id = $result['descendant_id'];
            $descendant_name = $result['descendant_name'];
            $public_date = $result['public_date'];
            $is_link = $result['is_link'];

            $datatype_is_public = true;
            if ( $public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' )
                $datatype_is_public = false;

            if ($is_link == 0) {
                // Save childtypes encountered
                if ( !isset($descendant_of[$ancestor_id]) ) {
                    $descendant_of[$ancestor_id] = array();
                    $datatype_names[$ancestor_id] = $ancestor_name;
                }

                // Only save this datatype if the user is allowed to view it
                if ( $datatype_is_public || (isset($datatype_permissions[$descendant_id]) && isset($datatype_permissions[$descendant_id]['dt_view'])) ) {
                    $descendant_of[$ancestor_id][] = $descendant_id;
                    $datatype_names[$descendant_id] = $descendant_name;
                }
            }
            else {
                // Save datatype links encountered
                if ( !isset($links[$ancestor_id]) ) {
                    $links[$ancestor_id] = array();
                    $datatype_names[$ancestor_id] = $ancestor_name;
                }

                // Only save this datatype if the user is allowed to view it
                if ( $datatype_is_public || (isset($datatype_permissions[$descendant_id]) && isset($datatype_permissions[$descendant_id]['dt_view'])) ) {
                    $links[$ancestor_id][] = $descendant_id;
                    $datatype_names[$descendant_id] = $descendant_name;
                }
            }
        }

/*
print '$target_datatype_id: '.$target_datatype_id."\n";
//print '$descendant_of: '.print_r($descendant_of, true)."\n";
print '$links: '.print_r($links, true)."\n";
*/

        $descendants = array();
        if ($target_datatype_id == null) {
            $descendants = $descendant_of;
        }
        else {
            // Only want ids of datatypes that are descendants of the given datatype
            $list = array($target_datatype_id);

            while ( count($list) > 0 ) {
                // Grab the id of the first datatype to process
                $datatype_id = array_shift($list);

                // Save any descendants of this datatype
                if ( isset($descendant_of[$datatype_id]) )
                    $descendants[$datatype_id] = $descendant_of[$datatype_id];
                else
                    $descendants[$datatype_id] = '';

                // If there are descendants of this datatype, queue them for processing
                $tmp = array();
                if ( isset($descendant_of[$datatype_id]) ) {
                    $tmp = $descendant_of[$datatype_id];

                    foreach ($tmp as $num => $id)
                        $list[] = $id;
                }
            }

            // TODO - links in childtypes? 
            // Only save datatypes that are linked to the target datatype
            foreach ($links as $ancestor_id => $tmp) {
                if ($ancestor_id != $target_datatype_id)
                    unset($links[$ancestor_id]);
            }
        }

        $linked_datatypes = array();
        if ( isset($links[$target_datatype_id]) )
            $linked_datatypes = $links[$target_datatype_id];

        $datatype_tree = array(
            'target_datatype' => $target_datatype_id,
            'child_datatypes' => $descendants,
            'linked_datatypes' => $linked_datatypes,
            'datatype_names' => $datatype_names,
        );
/*
print '<pre>';
print_r($datatype_tree);
print '</pre>';
exit();
*/

        return $datatype_tree;
    }


    /**
     * Given a list of datatypes, returns an array of all datafields the requesting user is allowed to search
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes          An array returned by @see self::getRelatedDatatypes()
     * @param boolean $logged_in
     * @param array $datatype_permissions       If set, the current user's datatype permission array
     * @param array $datafield_permissions      If set, the current user's datafield permission array
     *
     * @return array an array of ODR\AdminBundle\Entity\DataFields objects, grouped by their Datatype id
     */
    private function getSearchableDatafields($em, $related_datatypes, $logged_in, $datatype_permissions = array(), $datafield_permissions = array())
    {
        $searchable_datafields = array();

        // Need an array of related datatypes...MUST NOT be a comma-separated string for some reasno
        $datatype_list = array();
        foreach ($related_datatypes['child_datatypes'] as $datatype_id => $tmp)
            $datatype_list[] = $datatype_id;
        foreach ($related_datatypes['linked_datatypes'] as $num => $linked_datatype_id)
            $datatype_list[] = $linked_datatype_id;


        // Get all searchable datafields of all datatypes that the user is allowed to search on
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dfm.publicDate AS public_date, df
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:Theme AS t WITH t.dataType = dt
            JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
            JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
            WHERE dt.id IN (:datatypes) AND t.themeType = :theme_type AND dfm.searchable > 0
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datatypes' => $datatype_list, 'theme_type' => 'master') );
        $results = $query->getResult();
//        $results = $query->getArrayResult();

//print '<pre>'.print_r($results, true).'</pre>';  exit();

        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $public_date = $result['public_date']->format('Y-m-d H:i:s');

            /** @var DataFields $datafield */
            $datafield = $result[0];
            $df_id = $datafield->getId();

            $datafield_is_public = true;
            if ($public_date == '2200-01-01 00:00:00')
                $datafield_is_public = false;

            $can_view_datafield = false;
            if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) )
                $can_view_datafield = true;

            if ( !$datafield_is_public && !$can_view_datafield ) {
                // the user lacks the view permission for this datafield...don't save in $datafield_array
            }
            else {
                // The user has permissions to view this datafield...save it
                if ( !isset($searchable_datafields[$dt_id]) )
                    $searchable_datafields[$dt_id] = array();

                $searchable_datafields[$dt_id][] = $datafield;
            }
        }

        return $searchable_datafields;
    }


    /**
     * Renders the base page for searching purposes
     * 
     * @param String $search_slug   Which datatype to load a search page for.
     * @param String $search_string An optional string to immediately enter into the general search field and search with.
     * @param Request $request
     * 
     * @return Response
     */
    public function searchpageAction($search_slug, $search_string, Request $request)
    {
        $html = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRCustomController $odrcc */
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            $cookies = $request->cookies;

            // ------------------------------
            // Grab user and their permissions if possible
            /** @var User $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $datatype_permissions = array();
            $datafield_permissions = array();

            // Store if logged in or not
            $logged_in = true;
            if ($admin_user === 'anon.') {
                $admin_user = null;
                $logged_in = false;
            }
            else {
                // Grab user permissions
                $user_permissions = $odrcc->getUserPermissionsArray($em, $admin_user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];
            }
            // ------------------------------


            // Locate the datatype referenced by the search slug, if possible...
            $target_datatype = null;
            if ($search_slug == '') {
                if ( $cookies->has('prev_searched_datatype') ) {
                    $search_slug = $cookies->get('prev_searched_datatype');
                    return $this->redirect( $this->generateUrl('odr_search', array( 'search_slug' => $search_slug ) ));
                }
                else {
                    if ($logged_in) {
                        // Instead of displaying a "page not found", redirect to the datarecord list
                        $baseurl = $this->generateUrl('odr_admin_homepage');
                        $hash = $this->generateUrl('odr_list_types', array( 'section' => 'records') );

                        return $this->redirect( $baseurl.'#'.$hash );
                    }
                    else {
                        return $this->redirect( $this->generateUrl('odr_admin_homepage') );
                    }
                }
            }
            else {
                /** @var DataTypeMeta $meta_entry */
                $meta_entry = $em->getRepository('ODRAdminBundle:DataTypeMeta')->findOneBy( array('searchSlug' => $search_slug) );
                if ($meta_entry == null)
                    return self::searchPageError("Page not found", $request);
                $target_datatype = $meta_entry->getDataType();
                if ($target_datatype == null)
                    return self::searchPageError("Page not found", $request);
            }
            /** @var DataType $target_datatype */


            // Check if user has permission to view datatype
            $target_datatype_id = $target_datatype->getId();

            $can_view_datatype = false;
            if ( isset($datatype_permissions[ $target_datatype_id ]) && isset($datatype_permissions[ $target_datatype_id ][ 'dt_view' ]) )
                $can_view_datatype = true;

            $can_view_datarecord = false;
            if ( isset($datatype_permissions[ $target_datatype_id ]) && isset($datatype_permissions[ $target_datatype_id ][ 'dr_view' ]) )
                $can_view_datarecord = true;

            if ( !$target_datatype->isPublic() && !$can_view_datatype )
                return self::searchPageError("You don't have permission to access this DataType.", $request);

            // Need to grab all searchable datafields for the target_datatype and its descendants

            // ----------------------------------------
            // Grab ids of all datatypes related to the requested datatype that the user can view
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $datatype_permissions);

            // Grab all searchable datafields
            $searchable_datafields = self::getSearchableDatafields($em, $related_datatypes, $logged_in, $datatype_permissions, $datafield_permissions);

            // ----------------------------------------
            // Grab a random background image if one exists and the user is allowed to see it
            $background_image_id = null;
            if ( $target_datatype !== null && $target_datatype->getBackgroundImageField() !== null) {

                // Determine whether the user is allowed to view the background image datafield
                $df = $target_datatype->getBackgroundImageField();
                if ( $df->isPublic() || ( isset($datafield_permissions[$df->getId()]) && isset($datafield_permissions[$df->getId()]['view']) ) ) {
                    $query = null;
                    if ($can_view_datarecord) {
                        $query = $em->createQuery(
                           'SELECT i.id AS image_id
                            FROM ODRAdminBundle:Image AS i
                            WHERE i.original = 1 AND i.dataField = :datafield_id
                            AND i.deletedAt IS NULL'
                        )->setParameters( array('datafield_id' => $df->getId()) );
                    }
                    else {
                        $query = $em->createQuery(
                           'SELECT i.id AS image_id
                            FROM ODRAdminBundle:Image AS i
                            JOIN ODRAdminBundle:ImageMeta AS im WITH im.image = i
                            WHERE i.original = 1 AND i.dataField = :datafield_id AND im.publicDate NOT LIKE :public_date
                            AND i.deletedAt IS NULL AND im.deletedAt IS NULL'
                        )->setParameters( array('datafield_id' => $df->getId(), 'public_date' => '2200-01-01 00:00:00') );
                    }
                    $results = $query->getArrayResult();

                    // Pick a random image from the list of available images
                    if (count($results) > 0) {
                        $index = rand(0, count($results) - 1);
                        $background_image_id = $results[$index]['image_id'];
                    }
                }
            }


            // ----------------------------------------
            // Grab users to populate the created/modified by boxes with
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

            // Determine if the user has the permissions required to see anybody in the created/modified by search fields
            $admin_permissions = array();
            foreach ($datatype_permissions as $datatype_id => $up) {
                if ( (isset($up['dr_edit']) && $up['dr_edit'] == 1) || (isset($up['dr_delete']) && $up['dr_delete'] == 1) || (isset($up['dr_add']) && $up['dr_add'] == 1) ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }

            if ( $admin_user == null || count($admin_permissions) == 0 ) {
                // Not logged in, or has none of the required permissions
                $user_list = array();
            }
            else if ( $admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                /* do nothing, which lets the user see everybody */
            }
            else {
                // Get a list of datatypes the user is allowed to access
                $datatype_list = array();
                foreach ($admin_permissions as $dt_id => $tmp)
                    $datatype_list[] = $dt_id;

                // Get all other users which can view that list of datatypes
                $query = $em->createQuery(
                   'SELECT u
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
                    JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                    JOIN ODRAdminBundle:GroupDatatypePermissions AS gdtp WITH gdtp.group = g
                    JOIN ODRAdminBundle:GroupDatafieldPermissions AS gdfp WITH gdfp.group = g
                    WHERE g.dataType IN (:datatypes) AND (gdtp.can_add_datarecord = 1 OR gdtp.can_delete_datarecord = 1 OR gdfp.can_edit_datafield = 1)
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $user)
                    $user_list[] = $user;
            }

            // Generate a random key to identify this tab
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);


            // ----------------------------------------
            // Render just the html for the base page and the search page...$this->render() apparently creates a full Response object
            $site_baseurl = $this->container->getParameter('site_baseurl');
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $site_baseurl .= '/app_dev.php';

            $html = $this->renderView(
                'ODROpenRepositorySearchBundle:Default:index.html.twig',
                array(
                    // required twig/javascript parameters
                    'user' => $admin_user,
                    'datatype_permissions' => $datatype_permissions,
                    'datafield_permissions' => $datafield_permissions,

                    'user_list' => $user_list,
                    'logged_in' => $logged_in,
                    'window_title' => $target_datatype->getShortName(),
                    'source' => 'searching',
                    'search_slug' => $search_slug,
                    'site_baseurl' => $site_baseurl,
                    'search_string' => $search_string,
                    'odr_tab_id' => $odr_tab_id,

                    // required for background image
                    'background_image_id' => $background_image_id,

                    // datatype/datafields to search
                    'target_datatype' => $target_datatype,
                    'related_datatypes' => $related_datatypes,
                    'searchable_datafields' => $searchable_datafields,
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x81286282 ' . $e->getMessage();
        }

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->setCookie(new Cookie('prev_searched_datatype', $search_slug));
        return $response;
    }


    /**
     * Renders a version of the search page currently used for linking datarecords.
     * TODO - move this somewhere?  reorganize so that this is the action that renders search page?
     * 
     * @param integer $target_datatype_id The database id of the DataType marked for searching...
     * @param Request $request
     * 
     * @return Response
     */
    public function searchboxAction($target_datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Need to only return top-level datatypes
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var ODRCustomController $odrcc */
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // Need to grab all searchable datafields for the target_datatype and its descendants
            /** @var DataType $target_datatype */
            $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($target_datatype_id);
            if ($target_datatype == null)
                return $odrcc->deletedEntityError('Datatype');

            // ------------------------------
            // Grab user and their permissions if possible
            /** @var User $admin_user */
            $admin_user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = $odrcc->getUserPermissionsArray($em, $admin_user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];

            $logged_in = true;

            // ----------------------------------------
            // Grab ids of all datatypes related to the requested datatype that the user can view
            $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $datatype_permissions);
            // Grab all searchable datafields 
            $searchable_datafields = self::getSearchableDatafields($em, $related_datatypes, $logged_in, $datatype_permissions, $datafield_permissions);


            // Grab all the users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

            // Determine if the user has the permissions required to see anybody in the created/modified by search fields
            $admin_permissions = array();
            foreach ($datatype_permissions as $datatype_id => $up) {
                if ( (isset($up['dr_edit']) && $up['dr_edit'] == 1) || (isset($up['dr_delete']) && $up['dr_delete'] == 1) || (isset($up['dr_add']) && $up['dr_add'] == 1) ) {
                    $admin_permissions[ $datatype_id ] = $up;
                }
            }


            if ( $admin_user == null || count($admin_permissions) == 0 ) {
                // Not logged in, or has none of the required permissions
                $user_list = array();
            }
            else if ( $admin_user->hasRole('ROLE_SUPER_ADMIN') ) {
                /* do nothing, which will let the user see everybody */
            }
            else {
                // Get a list of datatypes the user is allowed to access
                $datatype_list = array();
                foreach ($admin_permissions as $dt_id => $tmp)
                    $datatype_list[] = $dt_id;

                // Get all other users which can view that list of datatypes
                $query = $em->createQuery(
                   'SELECT u
                    FROM ODROpenRepositoryUserBundle:User AS u
                    JOIN ODRAdminBundle:UserGroup AS ug WITH ug.user = u
                    JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                    JOIN ODRAdminBundle:GroupDatatypePermissions AS gdtp WITH gdtp.group = g
                    JOIN ODRAdminBundle:GroupDatafieldPermissions AS gdfp WITH gdfp.group = g
                    WHERE g.dataType IN (:datatypes) AND (gdtp.can_add_datarecord = 1 OR gdtp.can_delete_datarecord = 1 OR gdfp.can_edit_datafield = 1)
                    GROUP BY u.id'
                )->setParameters( array('datatypes' => $datatype_list) );   // purposefully getting ALL users, including the ones that are deleted
                $results = $query->getResult();

                // Convert them into a list of users that the admin user is allowed to search by
                $user_list = array();
                foreach ($results as $user)
                    $user_list[] = $user;
            }


            // Render the template
            $site_baseurl = $this->container->getParameter('site_baseurl');
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Default:search.html.twig',
                    array(
                        // required twig/javascript parameters
                        'user' => $admin_user,
                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'user_list' => $user_list,
                        'logged_in' => $logged_in,
//                        'window_title' => $target_datatype->getShortName(),
                        'source' => 'linking',
//                        'search_slug' => $search_slug,
                        'site_baseurl' => $site_baseurl,
//                        'search_string' => $search_string,

                        // required for background image
                        'background_image_id' => null,

                        // datatype/datafields to search
                        'target_datatype' => $target_datatype,
                        'related_datatypes' => $related_datatypes,
                        'searchable_datafields' => $searchable_datafields,
                    )
                )
            );

            // Clear the previously viewed datarecord since the user is probably pulling up a new list if he looks at this
            $session = $request->getSession();
            $session->set('scroll_target', '');

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18742232 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders a Short/Textresults list of all datarecords stored in a given memcached key
     * 
     * @param string $search_key The terms the user is searching for
     * @param integer $offset    Which page of the search results to render
     * @param string $source     "searching" if searching from frontpage, or "linking" if searching for datarecords to link
     * @param Request $request
     * 
     * @return Response
     */
    public function renderAction($search_key, $offset, $source, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab default objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
//            $templating = $this->get('templating');
/*
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();
*/
            /** @var ODRCustomController $odrcc */
            $odrcc = $this->get('odr_custom_controller', $request);
            $odrcc->setContainer($this->container);

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datatype_permissions = array();
            $datafield_permissions = array();

            if ($user !== 'anon.') {
                $user_permissions = $odrcc->getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];
            }
            // --------------------


            // -----------------------------------
            // Attempt to load the search results (for this user and/or search string?) from memcached
            // Extract the datatype id from the search string
            $datatype_id = '';
            $tokens = preg_split("/\|(?![\|\s])/", $search_key);
            foreach ($tokens as $num => $token) {
                $pieces = explode('=', $token);
                if ($pieces[0] == 'dt_id') {
                    $datatype_id = $pieces[1];
                    break;
                }
            }

            if ($datatype_id == '')
                throw new \Exception('Invalid search string');


            // Attempt to grab the list of datarecords from the cache...
            $search_params = $odrcc->getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype_id, $search_key, $request);
            if ($search_params['redirect'] == 1) {
                $url = $this->generateUrl('odr_search_render', array('search_key' => $search_params['encoded_search_key'], 'offset' => 1, 'source' => 'searching'));
                return $odrcc->searchPageRedirect($user, $url);
            }

            // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find( $search_params['datatype_id'] );
            $datarecord_list = $search_params['datarecord_list'];
            $encoded_search_key = $search_params['encoded_search_key'];

            // Turn the search results string into an array of datarecord ids
            $datarecords = array();
            if ( trim($datarecord_list) !== '')
                $datarecords = explode(',', trim($datarecord_list));


            // -----------------------------------
            // Bypass list entirely if only one datarecord
            if ( count($datarecords) == 1 && $source !== 'linking' ) {
                $datarecord_id = $datarecords[0];

                // Can't use $this->redirect, because it won't update the hash...
                $return['r'] = 2;
                $return['d'] = array( 'url' => $this->generateURL('odr_display_view', array('datarecord_id' => $datarecord_id)) );

                $response = new Response(json_encode($return));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            }


            // -----------------------------------
            // TODO - better error handling, likely need more options as well...going to need a way to get which theme the user wants to use too
            // Grab the desired theme to use for rendering search results
            $theme_type = null;
            if ($source == 'linking' || $datatype->getUseShortResults() == 0)
                $theme_type = 'table';
            else
                $theme_type = 'search_results';

            /** @var Theme $theme */
            $theme = $em->getRepository('ODRAdminBundle:Theme')->findOneBy( array('dataType' => $datatype->getId(), 'themeType' => $theme_type) );
            if ($theme == null)
                throw new \Exception('The datatype "'.$datatype->getShortName().'" wants to use a "'.$theme_type.'" theme to render search results, but no such theme exists.');


            // -----------------------------------
            // Render and return the page
            $path_str = $this->generateUrl('odr_search_render', array('search_key' => $encoded_search_key) );   // this will double-encode the search key, mostly
            $path_str = urldecode($path_str);   // decode the resulting string so search-key is only single-encoded

            $target = 'results';
            if ($source == 'linking')
                $target = $source;
            $html = $odrcc->renderList($datarecords, $datatype, $theme, $user, $path_str, $target, $encoded_search_key, $offset, $request);

             $return['d'] = array(
                'html' => $html,
            );

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x14168352 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called when the user performs a search from the search page.
     * 
     * @param string $search_key The terms the user wants to search on
     * @param Request $request
     * 
     * @return Response
     */
    public function searchAction($search_key, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            //
            $search_params = self::performSearch($search_key, $request);

            if ( $search_params['error'] == true ) {
                throw new \Exception( $search_params['message'] );
            }
            // Theoretically, this should never be true...
            else if ( $search_params['redirect'] == true ) {
                /** @var ODRCustomController $odrcc */
                $odrcc = $this->get('odr_custom_controller', $request);
                $odrcc->setContainer($this->container);

                $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in

                $url = $this->generateUrl('odr_search_render', array('search_key' => $search_params['encoded_search_key'], 'offset' => 1, 'source' => 'searching'));
                return $odrcc->searchPageRedirect($user, $url);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x19846813 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Searches a DataType for all DataRecords matching the user-defined search criteria, then stores the result in memcached using the given search_key
     * 
     * @param string $search_key The terms the user wants to search on
     * @param Request $request
     *
     * @return boolean|array true if no error, or array with necessary data otherwise
     */
    public function performSearch($search_key, Request $request)
    {
        // Grab default objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var ODRCustomController $odrcc */
        $odrcc = $this->get('odr_custom_controller', $request);
        $odrcc->setContainer($this->container);


$debug = array(
//    'basic' => true,                 // print out the minimum info required to determine whether searching is returning the correct results
//    'timing' => true,                // print out timing information
//    'cached_searches' => true,       // print out all cached searches after this search has completed

//    'show_queries' => true,          // print out the native SQL queries and their immediate results
//    'show_matches' => true,          // print out the list of datarecords matching the search queries before self::getIntermediateSearchResults() starts determining which grandparent datarecords match
//    'show_descendants' => true,      // print out the complete datarecord tree structure...extremely unlikely to break
//    'search_string_parsing' => true, // print out additional info about search string parsing
);

if ( count($debug) > 0 )
    print '<pre>'."\n";

$start_time = null;
$function_start_time = null;
if (isset($debug['timing'])) {
    $start_time = microtime(true);
    $function_start_time = microtime(true);
}

        // ----------------------------------------
        // Split the entire search key on pipes that are not followed by another pipe or space
        $get = preg_split("/\|(?![\|\s])/", $search_key);

        // Determine which datatype the user is searching on
        $target_datatype_id = null;
        foreach ($get as $num => $value) {
            if ( strpos($value, 'dt_id=') == 0 ) {
                $target_datatype_id = substr($value, 6);
                break;
            }
        }

        // Ensure it's a valid datatype before continuing...
        if ( !is_numeric($target_datatype_id) )
            return array( 'error' => true, 'redirect' => false, 'message' => 'This Datatype is deleted', 'encoded_search_key' => self::encodeURIComponent($search_key) );
        /** @var DataType $target_datatype */
        $target_datatype = $em->getRepository('ODRAdminBundle:DataType')->find($target_datatype_id);
        if ($target_datatype == null)
            return array( 'error' => true, 'redirect' => false, 'message' => 'This Datatype is deleted', 'encoded_search_key' => self::encodeURIComponent($search_key) );

        $target_datatype_id = intval($target_datatype_id);


        // Determine level of user's permissions...
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        $datatype_permissions = array();
        $datafield_permissions = array();

        if ($user !== 'anon.') {
            $user_permissions = $odrcc->getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];
        }

        // Don't allow user to search the datatype if it's non-public and they can't view it
        if ( !$target_datatype->isPublic() && !( isset($datatype_permissions[$target_datatype_id]) && $datatype_permissions[$target_datatype_id]['dt_view'] == 1) )
            return array( 'error' => true, 'redirect' => false, 'message' => 'Permission Denied', 'encoded_search_key' => self::encodeURIComponent($search_key) );


        // ----------------------------------------
        // Get a list of all datafields the user is allowed to search on
        $datafield_array = self::getSearchDatafieldsForUser($em, $user, $target_datatype_id, $datatype_permissions, $datafield_permissions);

if (isset($debug['timing'])) {
    print 'user permissions loaded in '.((microtime(true) - $start_time) * 1000)."ms \n\n";
    $start_time = microtime(true);
}

        // ----------------------------------------
        // Expand $datafield_array with the results of parsing the search key
        $parse_all = true;
        /*$dropped_datafields = */self::buildSearchArray($search_key, $datafield_array, $datatype_permissions, $parse_all, $debug);


        if ( $datafield_array['filtered_search_key'] !== $search_key )
            return array('error' => false, 'redirect' => true, 'encoded_search_key' => $datafield_array['encoded_search_key']);

//        if ( count($dropped_datafields) > 0 ) {
            // TODO - Figure out why each datafield in here was dropped from the search query...provide some sort of warning?
            // TODO - I forget...correct to just have a single "you don't have permissions" message even when the search key is completely wrong?  e.g. datafield listed for a different datatype
/*
            if ( $tmp_datafield == null || $tmp_datafield->getSearchable() == 0 || $tmp_datafield->getDataType()->getId() !== $datatype->getId() )
                return array('message' => "Invalid search query", 'encoded_search_key' => $encoded_search_key);
            else if (!$logged_in)
                return array('message' => "Permission denied...try logging in", 'encoded_search_key' => $encoded_search_key);
            else
                return array('message' => "Permission denied", 'encoded_search_key' => $encoded_search_key);
*/
//        }

if (isset($debug['timing'])) {
    print '$datafield_array built in '.((microtime(true) - $start_time) * 1000)."ms \n\n";
    $start_time = microtime(true);
}


        // ----------------------------------------
        // Grab all datatypes related to the one being searched
        $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $datatype_permissions);

        // Build the arrays that will be used to determine which datarecords matched the search
        $matched_datarecords = array();
        $descendants_of_datarecord = array();
        self::buildDatarecordArrays($em, $related_datatypes, $datafield_array, $matched_datarecords, $descendants_of_datarecord, $debug);

if (isset($debug['timing'])) {
    print '$datarecord_arrays built in '.((microtime(true) - $start_time) * 1000)."ms \n\n";
    $start_time = microtime(true);
}

        // ----------------------------------------
        // Run search queries only if necessary
        if ( isset($datafield_array['general']) && count($datafield_array['general']) == 0 )
            unset( $datafield_array['general'] );
        if ( isset($datafield_array['advanced']) && count($datafield_array['advanced']) == 0 )
            unset( $datafield_array['advanced'] );
        if ( isset($datafield_array['metadata']) && count($datafield_array['metadata']) == 0 )
            unset( $datafield_array['metadata'] );

        $had_search_criteria = false;
        if ( isset($datafield_array['general']) || isset($datafield_array['advanced']) || isset($datafield_array['metadata']) ) {
            $had_search_criteria = true;
            self::getSearchQueryResults($em, $related_datatypes, $datafield_array, $matched_datarecords, $debug);
        }

if (isset($debug['timing'])) {
    print '$query_results obtained in '.((microtime(true) - $start_time) * 1000)."ms \n\n";
    $start_time = microtime(true);
}


        // ----------------------------------------
        // Recursively determine which datarecords matched the original search
        $datarecord_ids = '';
        $grandparent_ids = '';
        if ($had_search_criteria) {
            // Recursively include/exclude datarecords by the rules specified in the header comment of self::buildDatarecordArrays()
            foreach ($descendants_of_datarecord[0] as $dt_id => $top_level_datarecords) {
                foreach ($top_level_datarecords as $gp_id => $tmp) {
                    self::getIntermediateSearchResults($matched_datarecords, $descendants_of_datarecord[0][$dt_id], $gp_id);
                }
            }

            // Get the final list of datarecords/grandparent datarecords matched by the query
            foreach ($descendants_of_datarecord[0] as $dt_id => $top_level_datarecords) {
                foreach ($top_level_datarecords as $gp_id => $tmp) {
                    $results = self::getFinalSearchResults($matched_datarecords, $descendants_of_datarecord[0][$dt_id], $gp_id, true);

                    $datarecord_ids .= $results;
                    if ($results !== '')
                        $grandparent_ids .= $gp_id.',';
                }
            }
        }
        else {
            // No search criteria, every datarecord id matches
            foreach ($matched_datarecords as $dr_id => $num)
                $datarecord_ids .= $dr_id.',';

            foreach ($descendants_of_datarecord[0] as $dt_id => $top_level_datarecords) {
                foreach ($top_level_datarecords as $gp_id => $tmp)
                    $grandparent_ids .= $gp_id.',';
            }
        }

        // Remove trailing commas
        if (strpos($datarecord_ids, ',') !== false) {
            $datarecord_ids = substr($datarecord_ids, 0, -1);

            // Get rid of duplicates in this string
            $datarecord_ids = explode(',', $datarecord_ids);
            $datarecord_ids = array_unique($datarecord_ids);
            $datarecord_ids = implode(',', $datarecord_ids);
        }

        $public_grandparent_ids = '';
        if (strpos($grandparent_ids, ',') !== false) {
            $grandparent_ids = substr($grandparent_ids, 0, -1);
            $grandparent_ids = $odrcc->getSortedDatarecords($target_datatype, $grandparent_ids);

            // Need to figure out which of these grandparent datarecords are viewable to people without the 'dr_view' permission
            $grandparent_ids_as_array = explode(',', $grandparent_ids);
            $query = $em->createQuery(
               'SELECT gp.id AS gp_id
                FROM ODRAdminBundle:DataRecord AS gp
                JOIN ODRAdminBundle:DataRecordMeta AS gpm WITH gpm.dataRecord = gp
                WHERE gp.id IN (:grandparent_ids) AND gpm.publicDate != :public_date
                AND gp.deletedAt IS NULL AND gpm.deletedAt IS NULL'
            )->setParameters( array('grandparent_ids' => $grandparent_ids_as_array, 'public_date' => '2200-01-01 00:00:00') );
            $results = $query->getArrayResult();

            foreach($results as $result)
                $public_grandparent_ids .= $result['gp_id'].',';
            $public_grandparent_ids = substr($public_grandparent_ids, 0, -1);
            $public_grandparent_ids = $odrcc->getSortedDatarecords($target_datatype, $public_grandparent_ids);
        }

if (isset($debug['basic'])) {
    print '$datarecord_ids: '.print_r($datarecord_ids, true)."\n";
    print '$grandparent_ids: '.print_r($grandparent_ids, true)."\n";

    if ($datarecord_ids == '') {
        print 'count($datarecord_ids): 0'."\n";
    }
    else {
        $datarecord_ids_as_array = explode(',', $datarecord_ids);
        print 'count($datarecord_ids): '.count($datarecord_ids_as_array)."\n";
    }

    if ($grandparent_ids == '') {
        print 'count($grandparent_ids): 0'."\n";
        print 'count($public_grandparent_ids): 0'."\n";
    }
    else {
        $grandparent_ids_as_array = explode(',', $grandparent_ids);
        print 'count($grandparent_ids): '.count($grandparent_ids_as_array)."\n";

        $grandparent_ids_as_array = explode(',', $public_grandparent_ids);
        print 'count($public_grandparent_ids): '.count($grandparent_ids_as_array)."\n";
    }
}
if (isset($debug['timing'])) {
    print 'final results obtained in '.((microtime(true) - $start_time) * 1000)."ms \n\n";
    $start_time = microtime(true);
}


        // --------------------------------------------------
        // Store the list of datarecord ids for later use
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Determine which datafields were searched on
        $searched_datafields = array();
        if ( isset($datafield_array['general']) ) {
            foreach ($datafield_array['general'] as $df_id => $tmp)
                $searched_datafields[] = $df_id;
        }
        if ( isset($datafield_array['advanced']) ) {
            foreach ($datafield_array['advanced'] as $df_id => $tmp)
                $searched_datafields[] = $df_id;
        }

        // If the datatype has a sort datafield, then it was effectively searched on, if only by an ORDER BY clause
        // Therefore, it should always be included in the list of searched datafields
        if ($target_datatype->getSortField() !== null)
            $searched_datafields[] = $target_datatype->getSortField()->getId();

        $searched_datafields = array_unique($searched_datafields);
        $searched_datafields = implode(',', $searched_datafields);


        // Create pieces of the array if they don't exist
        $search_checksum = md5($search_key);

        $cached_searches = ODRCustomController::getRedisData($redis->get($redis_prefix.'.cached_search_results'));
//        $cached_searches = array();

        if ($cached_searches == false)
            $cached_searches = array();
        if ( !isset($cached_searches[$target_datatype_id]) )
            $cached_searches[$target_datatype_id] = array();
        if ( !isset($cached_searches[$target_datatype_id][$search_checksum]) )
            $cached_searches[$target_datatype_id][$search_checksum] = array('searched_datafields' => $searched_datafields, 'encoded_search_key' => $datafield_array['encoded_search_key']);

        // Store the data in the memcached entry
        $cached_searches[$target_datatype_id][$search_checksum]['complete_datarecord_list'] = $datarecord_ids;
        $cached_searches[$target_datatype_id][$search_checksum]['datarecord_list'] = array('all' => $grandparent_ids, 'public' => $public_grandparent_ids);

        $redis->set($redis_prefix.'.cached_search_results', gzcompress(serialize($cached_searches)));

if (isset($debug['cached_searches'])) {
    print '$cached_searches: '.print_r($cached_searches, true)."\n";
}
if (isset($debug['timing'])) {
    print 'cache updated in '.((microtime(true) - $start_time) * 1000)."ms \n\n";

    print 'entire function took '.((microtime(true) - $function_start_time) * 1000)."ms \n\n";
}

        return array('redirect' => false, 'error' => false);
    }


    /**
     * Returns an array of all valid datafields that the user is allowed to search on
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param integer $target_datatype_id
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     *
     * @return array
     */
    public function getSearchDatafieldsForUser($em, $user, $target_datatype_id, $datatype_permissions, $datafield_permissions)
    {
        //
        $datafield_array = array(
            'general' => array(),
            'advanced' => array(),
            'metadata' => array(),
        );

        // Determine level of user's permissions...
        $logged_in = false;
        if ($user !== null && $user !== 'anon.')
            $logged_in = true;

        // Get an array structure of all child/linked datatypes related to the target datatype, filtered by the user's datatype permissions
        $related_datatypes = self::getRelatedDatatypes($em, $target_datatype_id, $datatype_permissions);

        // The next DQL query needs a comma separated list of datatype ids...
        $datatype_list = array();
        foreach ($related_datatypes['child_datatypes'] as $child_datatype_id => $tmp)
            $datatype_list[] = $child_datatype_id;
        foreach ($related_datatypes['linked_datatypes'] as $num => $linked_datatype_id)
            $datatype_list[] = $linked_datatype_id;


        // Get all searchable datafields of all datatypes that the user is allowed to search on
        $query = $em->createQuery(
           'SELECT dt.id AS dt_id, dfm.publicDate AS public_date, df.id AS df_id, dfm.searchable AS searchable, ft.typeClass AS type_class
            FROM ODRAdminBundle:DataType AS dt
            JOIN ODRAdminBundle:Theme AS t WITH t.dataType = dt
            JOIN ODRAdminBundle:ThemeElement AS te WITH te.theme = t
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
            JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            JOIN ODRAdminBundle:FieldType AS ft WITH dfm.fieldType = ft
            WHERE dt.id IN (:datatypes) AND t.themeType = :theme_type AND dfm.searchable > 0
            AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL AND dt.deletedAt IS NULL'
        )->setParameters( array('datatypes' => $datatype_list, 'theme_type' => 'master') );
        $results = $query->getArrayResult();

        foreach ($results as $result) {
            $dt_id = $result['dt_id'];
            $public_date = $result['public_date']->format('Y-m-d H:i:s');
            $df_id = $result['df_id'];
            $searchable = $result['searchable'];
            $typeclass = $result['type_class'];

            $datafield_is_public = true;
            if ($public_date == '2200-01-01 00:00:00')
                $datafield_is_public = false;

            $has_view_permission = false;
            if ( isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) )
                $has_view_permission = true;

            if ( !$datafield_is_public && !$has_view_permission ) {
                // the user lacks the view permission for this datafield...don't save in $datafield_array
            }
            else {
                // Save the datafield id and typeclass in the list of datafields to run a general search on, assuming it's a valid fieldtype for general search
                if ($typeclass == 'ShortVarchar' || $typeclass == 'MediumVarchar' || $typeclass == 'LongVarchar' || $typeclass == 'LongText' || $typeclass == 'IntegerValue' || $typeclass == 'Radio') {
                    // NOTE - this purposefully ignores boolean...otherwise a general search string of '1' would return all checked boolean entities, and '0' would return all unchecked boolean entities
                    // TODO - include DecimalValue?  I think it's not included right now because the value is considered a string instead of a float...

                    if ($searchable == 1 || $searchable == 2) {
                        $datafield_array['general'][$df_id] = array(
                            'typeclass' => $typeclass,
                            'datatype' => $dt_id,
                        );
                    }
                }

                // If set for advanced search, also save it in the list of datafields to run an advanced search on
                if ($searchable == 2 || $searchable == 3) {
                    $datafield_array['advanced'][$df_id] = array(
                        'typeclass' => $typeclass,
                        'datatype' => $dt_id,
                    );
                }
            }
        }

        // Return the array of datafields the user is allowed to search on
        return $datafield_array;
    }


    /**
     * Given an array of datafields the user is allowed to search on, parse the requested search values out of $search_key
     *
     * @param string $search_key
     * @param array $datafield_array
     * @param array $datatype_permissions
     * @param boolean $parse_all           If false, then will only determine the filtered search key...if actually searching, this MUST be true
     * @param array $debug
     */
    public function buildSearchArray($search_key, &$datafield_array, $datatype_permissions, $parse_all = false, $debug = array())
    {
        // May end up modifying the provided $search_key if the user doesn't have all the required datafield permissions...
        $dropped_datafields = array();
        $encoded_search_keys = array();
        $filtered_search_keys = array();

        // Split the entire search key on pipes that are not followed by another pipe or space
        $get = preg_split("/\|(?![\|\s])/", $search_key);

        foreach ($get as $key => $value) {
            // Split each search value into "(datafield id or other identifier)=(search term)"
            $pattern = '/([0-9a-z_]+)\=(.+)/';
            $matches = array();
            preg_match($pattern, $value, $matches);

            $key = trim($matches[1]);
            $value = trim($matches[2]);

            // TODO - get rid of extraneous extra spaces in $value?  e.g. the extra spaces in  "<df_id>=zip    ||  csv"

            // Determine whether this field is a radio fieldtype
            $is_radio = false;
            if ( is_numeric($key) && isset($datafield_array['general'][$key]) ) {
                $typeclass = $datafield_array['general'][$key]['typeclass'];     // 'general' should always have all datafields with a radio fieldtype...
                if ($typeclass == 'Radio')
                    $is_radio = true;
            }

            if ($is_radio) {
                // Only save in array if user has permissions
                if ( isset($datafield_array['advanced'][$key]) ) {
                    $values = explode(',', $value);
                    $datafield_array['advanced'][$key]['initial_value'] = $values;

                    $encoded_search_keys[$key] = $value;
                    $filtered_search_keys[$key] = $value;
                }
                else {
                    $dropped_datafields[] = $key;
                }
            }
            else if (strpos($key, '_s') !== false || strpos($key, '_e') !== false || strpos($key, '_by') !== false) {
                // Fields involving dates, or metadata
                $keys = explode('_', $key);

                if ( !is_numeric($keys[0]) ) {
                    // create/modify_date metadata
                    $dt_id = $keys[1];
                    $type = $keys[2];       // 'm' or 'c' (modify or create)
                    if ($type == 'm')
                        $type = 'updated';
                    else
                        $type = 'created';

                    $position = $keys[3];   // 's' or 'e' (start or end)

                    // Ensure both start and end dates exist regardless of contents of $search_key
                    if ( !isset($datafield_array['metadata'][$dt_id][$type]) ) {
                        $datafield_array['metadata'][$dt_id][$type] = array(
//                            's' => '1980-01-01 00:00:00',
//                            'e' => '2200-01-01 00:00:00',
                            's' => '1980-01-01',
                            'e' => '2200-01-01',
                        );
                    }

//                    if ($position == 's' || $position == 'e')
//                        $value .= ' 00:00:00';    // TODO - instead, change javascript on page to do "Y-m-d H:i:s" instead of the "Y-m-d" it does currently?

                    $datafield_array['metadata'][$dt_id][$type][$position] = $value;

                    $encoded_search_keys[$key] = $value;
                    $filtered_search_keys[$key] = $value;
                }
                else {
                    // DatetimeValue fields
                    $df_id = $keys[0];
                    $pos = $keys[1];

                    // Only save in array if user has permissions
                    if ( isset($datafield_array['advanced'][$df_id]) ) {

                        // Ensure both start and end dates exist regardless of contents of $search_key
                        if ( !isset($datafield_array['advanced'][$df_id]['initial_value']) ) {
                            $datafield_array['advanced'][$df_id]['initial_value'] = array(
//                                's' => '1980-01-01 00:00:00',
//                                'e' => '2200-01-01 00:00:00',
                                's' => '1980-01-01',
                                'e' => '2200-01-01',
                            );
                        }

                        $datafield_array['advanced'][$df_id]['initial_value'][$pos] = $value.' 00:00:00';

                        $encoded_search_keys[$key] = $value;
                        $filtered_search_keys[$key] = $value;
                    }
                    else {
                        $dropped_datafields[] = $df_id;
                    }
                }
            }
            else if (strpos($key, '_pub') !== false) {
                // public/non-public metadata
                $keys = explode('_', $key);
                $dt_id = $keys[1];

                $datafield_array['metadata'][$dt_id]['public'] = $value;

                $encoded_search_keys[$key] = $value;
                $filtered_search_keys[$key] = $value;
            }
            else {
                if ( is_numeric($key) ) {
                    // Should cover all other fieldtypes...
                    if ( isset($datafield_array['advanced'][$key]) ) {
                        $datafield_array['advanced'][$key]['initial_value'] = $value;
                        $encoded_search_keys[$key] = self::encodeURIComponent($value);
                        $filtered_search_keys[$key] = $value;
                    }
                    else {
                        $dropped_datafields[] = $key;
                    }
                }
                else {
                    // should only execute for 'dt_id' or 'gen' right now...
                    $datafield_array[$key] = $value;
                    $encoded_search_keys[$key] = self::encodeURIComponent($value);
                    $filtered_search_keys[$key] = $value;
                }
            }
        }

        // ----------------------------------------
        // Parameterize the search values for each datafield that has them, and remove all datafields from the array that aren't being searched on
        if ( isset($datafield_array['gen']) ) {

            if ($parse_all) {
                // All fieldtypes other than Radio use the same set of parameters
                $general_search_params = self::parseField($datafield_array['gen'], $debug);

                // ----------------------------------------
                // Radio fields require slightly different parameters...
                $comparision = 'LIKE';

                // If general_string has quotes around it, strip them
                $general_string = $datafield_array['gen'];
                if (substr($general_string, 0, 1) == "\"" && substr($general_string, -1) == "\"")
                    $comparision = '=';

                $conditions = array();
                foreach ($general_search_params['params'] as $key => $value)
                    $conditions[] = '(ro.option_name '.$comparision.' :'.$key.' AND rs.selected = 1)';

                $radio_search_params = array(
                    'str' => implode(' AND ', $conditions),
                    'params' => $general_search_params['params'],
                );


                // ----------------------------------------
                // Assign each datafield in general search the correct set of parameters
                foreach ($datafield_array['general'] as $df_id => $tmp) {
                    $typeclass = $tmp['typeclass'];

                    if ($typeclass == 'Radio')
                        $datafield_array['general'][$df_id]['search_params'] = $radio_search_params;
                    else
                        $datafield_array['general'][$df_id]['search_params'] = $general_search_params;
                }
            }

            // No longer need this value
            unset( $datafield_array['gen'] );
        }
        else {
            // No general search string specified, so do not search on these datafields
            unset( $datafield_array['general'] );
        }


        foreach ($datafield_array['advanced'] as $df_id => $tmp) {
            if ( !isset($tmp['initial_value']) ) {
                // No search value specified for this datafield...get rid of it
                unset( $datafield_array['advanced'][$df_id] );
            }
            else {
                if ($parse_all) {
                    // Pull the raw search value from the array
                    $initial_value = $tmp['initial_value'];
                    $typeclass = $tmp['typeclass'];

                    $search_params = array();
                    if ($typeclass == 'Radio') {
                        // Ensure $initial_value is an array
                        if (!is_array($initial_value)) {
                            $tmp = $initial_value;
                            $initial_value = array($tmp);
                        }

                        // Turn the array of radio options into a search string
                        $conditions = array();
                        $parameters = array();
                        $count = 0;
                        foreach ($initial_value as $num => $radio_option_id) {
                            $str = '';
                            if (strpos($radio_option_id, '-') !== false) {
                                // Want this option to be unselected
                                $radio_option_id = substr($radio_option_id, 1);
                                $str = '(ro.id = :radio_option_'.$count.' AND rs.selected = 0)';
                            }
                            else {
                                // Want this option to be selected
                                $str = '(ro.id = :radio_option_'.$count.' AND rs.selected = 1)';
                            }

                            $conditions[] = $str;
                            $parameters['radio_option_'.$count] = $radio_option_id;
                            $count++;
                        }

                        // Build array of search params for this datafield
                        $search_params = array(
                            'str' => implode(' OR ', $conditions),
                            'params' => $parameters,
                        );
                    }
                    else if ($typeclass == 'File' || $typeclass == 'Image') {
                        $is_filename = true;
                        $search_params = self::parseField($initial_value, $debug, $is_filename);
                    }
                    else if ($typeclass == 'DatetimeValue') {
                        // Datetime fields...
                        $start = $tmp['initial_value']['s'];
                        $end = $tmp['initial_value']['e'];

                        // TODO - why did the metadata created/updated dates have this adjustment, but regular datetime fields didn't?
//                        if ($start != '1980-01-01 00:00:00' && $end != '2200-01-01 00:00:00') {
                        if ($start != '1980-01-01' && $end != '2200-01-01') {
                            // Selecting a date start of...say, 2015-04-26 and a date end of 2015-04-28...gives the impression that the search will everything between the "26th" and the "28th", inclusive.
                            // However, to actually include results from the "28th", the end date needs to be incremented by 1 to 2015-04-29...
                            $date_end = new \DateTime($end);
                            $date_end->add(new \DateInterval('P1D'));

//                            $end = $date_end->format('Y-m-d H:i:s');
                            $end = $date_end->format('Y-m-d');

                            if (isset($debug['basic']))
                                print 'adjusted end date of datafield '.$df_id.' to "'.$end.'"'."\n";
                        }

                        $search_params = array(
                            'str' => 'e.value BETWEEN :start AND :end',
                            'params' => array(
                                'start' => $start,
                                'end' => $end,
                            ),
                        );
                    }
                    else {
                        // Every other field...
                        $is_filename = false;
                        $search_params = self::parseField($initial_value, $debug, $is_filename);
                    }

                    $datafield_array['advanced'][$df_id]['search_params'] = $search_params;
                }

                // Don't need the initial value of this datafield anymore
                unset( $datafield_array['advanced'][$df_id]['initial_value'] );
            }
        }


        // ----------------------------------------
        // Adjust any dates in the metadata fields
        foreach ($datafield_array['metadata'] as $dt_id => $metadata) {
            foreach ($metadata as $key => $data) {
                // Only change dates...
                if ( !($key == 'created' || $key == 'updated') )
                    continue;

                $start = $data['s'];
                $end = $data['e'];

                // TODO - why did the metadata created/updated dates have this adjustment, but regular datetime fields didn't?
//                if ($start != '1980-01-01 00:00:00' && $end != '2200-01-01 00:00:00') {
                if ($start != '1980-01-01' && $end != '2200-01-01') {
                    // Selecting a date start of...say, 2015-04-26 and a date end of 2015-04-28...gives the impression that the search will everything between the "26th" and the "28th", inclusive.
                    // However, to actually include results from the "28th", the end date needs to be incremented by 1 to 2015-04-29...
                    $date_end = new \DateTime($end);
                    $date_end->add(new \DateInterval('P1D'));

//                    $datafield_array['metadata'][$dt_id][$key]['e'] = $date_end->format('Y-m-d H:i:s');
                    $datafield_array['metadata'][$dt_id][$key]['e'] = $date_end->format('Y-m-d');

if ( isset($debug['basic']))
//    print 'adjusted "'.$key.'" end date of datatype '.$dt_id.' to "'.$date_end->format('Y-m-d H:i:s').'"'."\n";
    print 'adjusted "'.$key.'" end date of datatype '.$dt_id.' to "'.$date_end->format('Y-m-d').'"'."\n";
                }
            }
        }

        // ----------------------------------------
        // Determine which datatypes are being searched on
        $searched_datatypes = array();
        $searched_datatypes[] = $datafield_array['dt_id'];

        // TODO - Don't care about general search at this point?
/*
        foreach ($datafield_array['general'] as $df_id => $data) {
            $dt_id = $data['datatype'];
            if ( !in_array($dt_id, $searched_datatypes) )
                $searched_datatypes[] = $dt_id;
        }
*/
        foreach ($datafield_array['advanced'] as $df_id => $data) {
            $dt_id = $data['datatype'];
            if ( !in_array($dt_id, $searched_datatypes) )
                $searched_datatypes[] = $dt_id;
        }
        foreach ($datafield_array['metadata'] as $dt_id => $data) {
            if ( !in_array($dt_id, $searched_datatypes) )
                $searched_datatypes[] = $dt_id;
        }


        // Need to enforce these rules...
        // 1) If user doesn't have the can_view_datarecord permission for target datatype, only show public datarecords of target datatype
        // If user doesn't have the can_view_datarecord permission for child/linked datatypes, then
        //  2) searching datafields of child/linked dataypes must be restricted to public datarecords, or the user would be able to see non-public datarecords
        //  3) searching datafields of just the target datatype can't be restricted by non-public child/linked datatypes...or the user would be able to infer the existence of non-public child/linked datarecords
        foreach ($searched_datatypes as $num => $dt_id) {
            if ( !( isset($datatype_permissions[$dt_id]) && isset($datatype_permissions[$dt_id]['dr_view']) ) ) {
                $datafield_array['metadata'][$dt_id] = array();   // clears updated/created (by) on purpose

                // Get rid of any created/modified searching
                self::clearSearchKeyMetadata($dt_id, $encoded_search_keys);
                self::clearSearchKeyMetadata($dt_id, $filtered_search_keys);
/*
                // Force a search of public datarecords only
                $datafield_array['metadata'][$dt_id]['public'] = 1;
                $encoded_search_keys['dt_'.$dt_id.'_pub'] = 1;
                $filtered_search_keys['dt_'.$dt_id.'_pub'] = 1;
*/
            }
        }


        // ----------------------------------------
        // Store a few pieces of info that aren't already in the array...
        $encoded_search_key = '';
        foreach ($encoded_search_keys as $key => $value)
            $encoded_search_key .= $key.'='.$value.'|';
        $encoded_search_key = substr($encoded_search_key, 0, -1);

        $filtered_search_key = '';
        foreach ($filtered_search_keys as $key => $value)
            $filtered_search_key .= $key.'='.$value.'|';
        $filtered_search_key = substr($filtered_search_key, 0, -1);

        $datafield_array['search_key'] = $search_key;
        $datafield_array['encoded_search_key'] = $encoded_search_key;
        $datafield_array['filtered_search_key'] = $filtered_search_key;

if ( isset($debug['basic']) ) {
    print '$datafield_array: '.print_r($datafield_array, true)."\n";
    print 'md5($search_key): '.md5($search_key)."\n";
    print 'md5($encoded_search_key): '.md5($encoded_search_key)."\n";
    print '$dropped_datafields: '.print_r($dropped_datafields, true)."\n";
}

//        return $dropped_datafields;
    }


    /**
     * Utility function to assist with creating a filtered search string.
     *
     * @param $datatype_id
     * @param &$array
     */
    private function clearSearchKeyMetadata($datatype_id, &$array)
    {
        $pieces = array('m_s', 'm_e', 'm_by', 'c_s', 'c_e', 'c_by', 'pub');
        foreach ($pieces as $piece) {
            if ( isset($array['dt_'.$datatype_id.'_'.$piece]) )
                unset( $array['dt_'.$datatype_id.'_'.$piece] );
        }
    }


    /**
     * Builds the datarecord array structure that will keep track of which datarecords match the search query
     *
     * Need to recursively enforce these logical rules for searching...
     * 1) A child datarecord of a child datatype that isn't being directly searched on is automatically included if its parent is included
     * 2) A datarecord of a datatype that is being directly searched on must match criteria to be included
     * 3) If none of the child datarecords of a given child datatype for a given parent datarecord match the search query, that parent datarecord must also be excluded
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes          @see self::getRelatedDatatypes()
     * @param array $datafield_array
     * @param array $matched_datarecords
     * @param array $descendants_of_datarecord
     * @param array $debug
     */
    private function buildDatarecordArrays($em, $related_datatypes, $datafield_array, &$matched_datarecords, &$descendants_of_datarecord, $debug)
    {
        // $matched_datarecords is an array where every single datarecord id listed in $descendants_of_datarecord points to the integers -1, 0, or 1
        // -1 denotes "does not match search, exclude", 0 denotes "not being searched on", and 1 denotes "matched search"

        // All datarecords of every datatype that are being searched on are initialized to -1...their contents must match the search for them to be included in the results.
        // During the very last phase of searching, $descendants_of_datarecords is recursively traversed...datarecords with a 0 or 1 are included in the final list of search results unless it would violate rule 3 above.
        $target_datatype_id = $datafield_array['dt_id'];

        // --------------------------------------------------
        // By default, most of the related datatypes don't have to match search criteria exactly...
        $initial_datatype_flags = array();
        foreach ($related_datatypes['datatype_names'] as $dt_id => $dt_name)
            $initial_datatype_flags[$dt_id] = 0;

        // If the user is searching on a datafield, then datarecords of that datafield's datatype MUST match search query to be included
        foreach ($datafield_array['advanced'] as $df_id => $data) {
            $dt_id = $data['datatype'];
            $initial_datatype_flags[$dt_id] = -1;
        }
        // Same thing for searching on metadata...
        foreach ($datafield_array['metadata'] as $dt_id => $data) {
            $initial_datatype_flags[$dt_id] = -1;
        }


        // --------------------------------------------------
        // Get all top-level datarecords of this datatype that could possibly match the desired search
        // ...has to be done this way so that when the user searches on criteria for both childtypes A and B, a top-level datarecord that only has either childtype A or childtype B won't match
        $allowed_grandparents = null;
        foreach ($initial_datatype_flags as $dt_id => $flag) {
            // Don't restrict by top-level datatype here, and also don't restrict if the user isn't directly searching on a datafield of the datatype
            if ($dt_id == $target_datatype_id || $flag == 0)
                continue;

            // Linked datarecords needs a different query to extract "grandparents", since we actually want the grandparent of the datarecord that is linking to this linked datarecord
            $sub_query = null;
            if ( in_array($dt_id, $related_datatypes['linked_datatypes']) ) {
                $sub_query = $em->createQuery(
                   'SELECT grandparent.id AS id
                    FROM ODRAdminBundle:DataRecord AS ldr
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldr = ldt.descendant
                    JOIN ODRAdminBundle:DataRecord AS dr WITH dr = ldt.ancestor
                    JOIN ODRAdminBundle:DataRecord AS grandparent WITH grandparent = dr.grandparent
                    WHERE ldr.dataType = :linked_datatype_id AND grandparent.dataType = :target_datatype_id
                    AND ldr.deletedAt IS NULL AND ldt.deletedAt IS NULL AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                )->setParameters( array('linked_datatype_id' => $dt_id, 'target_datatype_id' => $target_datatype_id) );
            }
            else {
                $sub_query = $em->createQuery(
                   'SELECT grandparent.id AS id
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
                    WHERE dr.dataType = :dt_id AND grandparent.dataType = :target_datatype_id
                    AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
                )->setParameters( array('dt_id' => $dt_id, 'target_datatype_id' => $target_datatype_id) );
            }
            $result = $sub_query->getArrayResult();

            // Flatten the array doctrine returns so array_intersect works properly
            $sub_result = array();
            foreach ($result as $tmp => $data)
                $sub_result[] = $data['id'];
            $sub_result = array_unique($sub_result);

            // Intersect the flattened array with the array of currently allowed top-level datarecords
            if ($allowed_grandparents == null)
                $allowed_grandparents = $sub_result;
            else
                $allowed_grandparents = array_intersect($allowed_grandparents, $sub_result);
        }


        // Get every single child datarecord of each of the allowed top-level datarecords
        $parameters = array();
        $query_str =
           'SELECT dr.id AS dr_id, parent.id AS parent_id, dt.id AS datatype_id
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            JOIN ODRAdminBundle:DataRecord AS parent WITH dr.parent = parent
            JOIN ODRAdminBundle:DataRecord AS grandparent WITH dr.grandparent = grandparent
            WHERE grandparent.dataType = :target_datatype';
        $parameters['target_datatype'] = $target_datatype_id;

        // If there's some restriction on the top-level datarecords to return, splice that into the query
        if ( $allowed_grandparents !== null && count($allowed_grandparents) > 0 ) {
            $query_str .= ' AND grandparent.id IN (:allowed_grandparents)';
            $parameters['allowed_grandparents'] = $allowed_grandparents;
        }
        $query_str .= ' AND dr.deletedAt IS NULL AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND dt.deletedAt IS NULL';

        // Run the query to get all child datarecords
        $query = $em->createQuery($query_str)->setParameters($parameters);
        $child_datarecords = $query->getArrayResult();


        // Get every single linked datarecord of each of the allowed top-level datarecords
        $parameters = array();
        $query_str =
           'SELECT ldr.id AS dr_id, parent.id AS parent_id, ldr_dt.id AS datatype_id
            FROM ODRAdminBundle:DataRecord AS ldr
            JOIN ODRAdminBundle:DataType AS ldr_dt WITH ldr.dataType = ldr_dt
            JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = ldr
            JOIN ODRAdminBundle:DataRecord AS parent WITH ldt.ancestor = parent
            JOIN ODRAdminBundle:DataRecord AS grandparent WITH parent.grandparent = grandparent
            WHERE grandparent.dataType = :target_datatype';
        $parameters['target_datatype'] = $target_datatype_id;

        // If there's some restriction on the top-level datarecords to return, splice that into the query
        if ( $allowed_grandparents !== null && count($allowed_grandparents) > 0 ) {
            $query_str .= ' AND grandparent.id IN (:allowed_grandparents)';
            $parameters['allowed_grandparents'] = $allowed_grandparents;
        }
        $query_str .= ' AND ldr.deletedAt IS NULL AND ldr_dt.deletedAt IS NULL AND ldt.deletedAt IS NULL AND parent.deletedAt IS NULL AND grandparent.deletedAt IS NULL';

        // Run the query to get all linked datarecords
        $query = $em->createQuery($query_str)->setParameters($parameters);
        $linked_datarecords = $query->getArrayResult();


        // Merge all child and linked datarecords together into the same array
        $results = array_merge($child_datarecords, $linked_datarecords);

        // @see self::buildDatarecordTree() for structure of this array
        $descendants_of_datarecord = array();
        $matched_datarecords = array();

        foreach ($results as $result) {
            $dr_id = $result['dr_id'];
            $parent_id = $result['parent_id'];
            $dt_id = $result['datatype_id'];

            // Keep track of which children this datarecord has
            if ($dr_id == $parent_id) {
                // ...this is a top-level datarecord
                if ( !isset($descendants_of_datarecord[0]) ) {
                    $descendants_of_datarecord[0] = array();
                    $descendants_of_datarecord[0][$target_datatype_id] = array();
                }
                $descendants_of_datarecord[0][$target_datatype_id][$dr_id] = '';
            }
            else {
                // ...this is a some child or linked datarecord
                if ( !isset($descendants_of_datarecord[$parent_id]) )
                    $descendants_of_datarecord[$parent_id] = array();
                if ( !isset($descendants_of_datarecord[$parent_id][$dt_id]) )
                    $descendants_of_datarecord[$parent_id][$dt_id] = array();
                $descendants_of_datarecord[$parent_id][$dt_id][$dr_id] = '';
            }

            // Apply the default inclusion flag to this datarecord, if specified
            $flag = 0;
            if ( isset($initial_datatype_flags[$dt_id]) )
                $flag = $initial_datatype_flags[$dt_id];

            $matched_datarecords[$dr_id] = $flag;
        }

        // $descendants_of_datarecord array is currently partially flattened..."inflate" it into the true tree structure described above
        $matched_datarecords[0] = 0;
        $descendants_of_datarecord = array(0 => self::buildDatarecordTree($descendants_of_datarecord, 0));

        // Don't actually want a datarecord id of zero in here...
        unset( $matched_datarecords[0] );

if ( isset($debug['show_descendants']) ) {
    print '$matched_datarecords: '.print_r($matched_datarecords, true)."\n";    // included here instead of under $debug['show_matches'] because $matched_datarecords is nearly useless at this point
    print '$descendants_of_datarecord: '.print_r($descendants_of_datarecord, true)."\n";
}
    }


    /**
     * Turns the originally flattened $descendants_of_datarecord array into a recursive tree structure of the form...
     *
     * parent_datarecord_id => array(
     *     child_datatype_1_id => array(
     *         child_datarecord_1_id of child_datatype_1 => '',
     *         child_datarecord_2_id of child_datatype_1 => '',
     *         ...
     *     ),
     *     child_datatype_2_id => array(
     *         child_datarecord_1_id of child_datatype_2 => '',
     *         child_datarecord_2_id of child_datatype_2 => '',
     *         ...
     *     ),
     *     ...
     * )
     *
     * If child_datarecord_X_id has children of its own, then it is also a parent datarecord, and it points to another recursive tree structure of this type instead of an empty string.
     * Linked datatypes/datarecords are handled identically to child datatypes/datarecords.
     *
     * The tree's root looks like...
     *
     * 0 => array(
     *     target_datatype_id => array(
     *         top_level_datarecord_1_id => ...
     *         top_level_datarecord_2_id => ...
     *         ...
     *     )
     * )
     *
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     *
     * @return array
     */
    private function buildDatarecordTree($descendants_of_datarecord, $current_datarecord_id)
    {
        if ( !isset($descendants_of_datarecord[$current_datarecord_id]) ) {
            // $current_datarecord_id has no children
            return '';
        }
        else {
            // $current_datarecord_id has children
            $result = array();

            // For every child datatype this datarecord has...
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $datarecords) {
                // For every child datarecord of this child datatype...
                foreach ($datarecords as $dr_id => $tmp) {
                    // ...get all children of this child datarecord and store them
                    $result[$dt_id][$dr_id] = self::buildDatarecordTree($descendants_of_datarecord, $dr_id);
                }
            }

            return $result;
        }
    }


    /**
     * Runs each of the search queries outlined in $datafield_array, and calculates the intersection between the results to
     *  determine all datarecords which directly match the criteria in $datafield_array.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes         @see self::getRelatedDatatypes()
     * @param array $datafield_array
     * @param array $matched_datarecords
     * @param array $debug
     */
    private function getSearchQueryResults($em, $related_datatypes, $datafield_array, &$matched_datarecords, $debug)
    {
        $search_results = array();

        // Queries in this function assume the existence of this array...doesn't matter if it's empty though
        if ( !isset($datafield_array['metadata']) )
            $datafield_array['metadata'] = array();

        // ----------------------------------------
        // Run the general search queries if they exist
        if ( isset($datafield_array['general']) ) {
            foreach ($datafield_array['general'] as $df_id => $data) {
                // Don't need to organize by datatype id because a datarecord is considered to match a general search query if any searchable datafield matches the query
                $dt_id = $data['datatype'];

                // Ensure arrays exist
                if ( !isset($search_results['general']) )
                    $search_results['general'] = array();

                // Build and run the query for this datafield
                $search_results['general'][$df_id] = self::runSearchQuery($em, $related_datatypes, $df_id, $data, $datafield_array['metadata'], $debug);

                // If metadata existed for this datatype, mark it as having been searched on
                if ( isset($datafield_array['metadata'][$dt_id]) )
                    $datafield_array['metadata'][$dt_id]['searched'] = true;
            }
        }

        // Run the advanced search queries if they exist
        if ( isset($datafield_array['advanced']) ) {
            foreach ($datafield_array['advanced'] as $df_id => $data) {
                // Need to organize by datatype id because a datarecords is only considered to match an advanced search query if it also matches all other advanced search queries for its datatype
                $dt_id = $data['datatype'];

                // Ensure arrays exist
                if ( !isset($search_results['advanced']) )
                    $search_results['advanced'] = array();
                if ( !isset($search_results['advanced'][$dt_id]) )
                    $search_results['advanced'][$dt_id] = array();

                // Build and run the query for this datafield
                $search_results['advanced'][$dt_id][$df_id] = self::runSearchQuery($em, $related_datatypes, $df_id, $data, $datafield_array['metadata'], $debug);

                // If metadata existed for this datatype, mark it as having been searched on
                if ( isset($datafield_array['metadata'][$dt_id]) )
                    $datafield_array['metadata'][$dt_id]['searched'] = true;
            }
        }

        // Run any remaining metadata queries if they exist
        foreach ($datafield_array['metadata'] as $dt_id => $data) {
            if ( !isset($data['searched']) ) {
                $search_results['advanced'][$dt_id]['metadata'] = self::runMetadataSearchQuery($em, $related_datatypes, $dt_id, $datafield_array['metadata'], $debug);
            }
        }

if ( isset($debug['basic']) ) {
    print '$search_results: '.print_r($search_results, true)."\n";
}

        // ----------------------------------------
        // Calculate the intersection within each datatype to figure out which datarecords matched all of the search queries
        if ( isset($search_results['advanced']) ) {
            $matches = array();
            foreach ($search_results['advanced'] as $dt_id => $data) {
                $intersection = null;
                foreach ($data as $df_id => $dr_list) {
                    if ($intersection == null) {
                        $intersection = $dr_list;
                        continue;
                    }
                    else {
                        $intersection = array_intersect_assoc($intersection, $dr_list);
                    }
                }

                foreach ($intersection as $dr_id => $num) {
                    if ( !isset($matches[$dr_id]) )
                        $matches[$dr_id] = 1;
                }
            }

            $search_results['advanced'] = $matches;
        }

        // Merge all datarecords in the general section of $search_results together
        if ( isset($search_results['general']) ) {
            $matches = array();
            foreach ($search_results['general'] as $df_id => $data) {
                foreach ($data as $dr_id => $num) {
                    if ( !isset($matches[$dr_id]) )
                        $matches[$dr_id] = 1;
                }
            }

            $search_results['general'] = $matches;
        }


        // ----------------------------------------
        // Compute the intersection between general search and advanced search if it was searched on
        $intersection = array();
        if ( !isset($search_results['general']) && !isset($search_results['advanced']) ) {
            // Search returned no results
            $intersection = array();
        }
        else if ( isset($search_results['general']) && !isset($search_results['advanced']) ) {
            // User only searched on a specific datafield (or piece of metadata)
            $intersection = $search_results['general'];
        }
        else if ( !isset($search_results['general']) && isset($search_results['advanced']) ) {
            // User only searched on a general search string
            $intersection = $search_results['advanced'];
        }
        else {
            // User searched on both a general search string and some specific datafield (or piece of metadata)...intersect the results, preserving the array keys
            $intersection = array_intersect_assoc($search_results['general'], $search_results['advanced']);
        }

        // Store those results in $matched_datarecords
        foreach ($intersection as $dr_id => $num) {
            if ( isset($matched_datarecords[$dr_id]) )
                $matched_datarecords[$dr_id] = 1;
        }

if (isset($debug['show_matches'])) {
    print '$matched_datarecords: '.print_r($matched_datarecords, true)."\n";
}
    }


    /**
     * Given a set of search parameters, runs a search on the given datafields, and returns the grandparent id of all datarecords that match the query
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes         @see self::getRelatedDatatypes()
     * @param integer $datafield_id
     * @param array $data
     * @param array $metadata
     * @param array $debug
     *
     * @return array
     */
    private function runSearchQuery($em, $related_datatypes, $datafield_id, $data, $metadata, $debug)
    {
        // ----------------------------------------
        // Conversion array from typeclass to physical table name
        $table_names = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',

            'IntegerValue' => 'odr_integer_value',
            'DecimalValue' => 'odr_decimal_value',
            'DatetimeValue' => 'odr_datetime_value',

            'Boolean' => 'odr_boolean',

            'File' => 'odr_file',
            'Image' => 'odr_image',
        );


if ( isset($debug['show_queries']) )
    print '----------------------------------------'."\n";

        // Pull required info from the $data array...
        $target_datatype_id = $related_datatypes['target_datatype'];
        $typeclass = $data['typeclass'];
        $datatype_id = $data['datatype'];
        $search_params = $data['search_params'];
        $parameters = $search_params['params'];


        // ----------------------------------------
        // Always include created/updated/public metadata for the grandparent if it exists...
        $metadata_str = '';

        if ( isset($metadata[$target_datatype_id]) )  {
            $search_metadata = self::buildMetadataQueryStr($metadata[$target_datatype_id], 'grandparent');

            if ( $search_metadata['metadata_str'] !== '' ) {
                $metadata_str .= $search_metadata['metadata_str'];
                $parameters = array_merge($parameters, $search_metadata['metadata_params']);
            }
        }


        $searching_linked_datatype = false;
        if ( $datatype_id !== $target_datatype_id && isset($related_datatypes['child_datatypes'][ $datatype_id ]) ) {
            // Searching from a child datatype requires different metadata
            if ( isset($metadata[$datatype_id]) ) {
                $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'dr');

                if ( $search_metadata['metadata_str'] !== '' ) {
                    $metadata_str .= $search_metadata['metadata_str'];
                    $parameters = array_merge($parameters, $search_metadata['metadata_params']);
                }
            }
        }
        else if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) ) {
            $searching_linked_datatype = true;
            // Searching from a linked datatype also requires different metadata
            if ( isset($metadata[$datatype_id]) ) {
                $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], 'ldr');

                if ( $search_metadata['metadata_str'] !== '' ) {
                    $metadata_str .= $search_metadata['metadata_str'];
                    $parameters = array_merge($parameters, $search_metadata['metadata_params']);
                }
            }
        }

        // Determine whether this query's search parameters contain an empty string...if so, going to have to run an additional query later because of how ODR is designed...
        $null_drf_possible = false;
        foreach ($parameters as $key => $value) {
            if ($value == '')
                $null_drf_possible = true;
        }


        // ----------------------------------------
        // The native SQL queries run by this function all start pretty much the same...
        $query_start = '';
        $drf_join = '';
        $deleted_at = '';
        if ( !$searching_linked_datatype ) {
            $query_start =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record_meta AS grandparent_meta ON grandparent_meta.data_record_id = grandparent.id
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                INNER JOIN odr_data_record_meta AS dr_meta ON dr_meta.data_record_id = dr.id
                ';

            $drf_join = 'INNER JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id';
            $deleted_at = 'AND grandparent.deletedAt IS NULL AND grandparent_meta.deletedAt IS NULL AND dr.deletedAt IS NULL AND dr_meta.deletedAt IS NULL ';
        }
        else {
            $query_start =
               'SELECT ldr.id AS dr_id
                FROM odr_data_record AS grandparent
                INNER JOIN odr_data_record_meta AS grandparent_meta ON grandparent_meta.data_record_id = grandparent.id
                INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
                INNER JOIN odr_data_record_meta AS dr_meta ON dr_meta.data_record_id = dr.id
                INNER JOIN odr_linked_data_tree AS ldt ON ldt.ancestor_id = dr.id
                INNER JOIN odr_data_record AS ldr ON ldt.descendant_id = ldr.id
                INNER JOIN odr_data_record_meta AS ldr_meta ON ldr_meta.data_record_id = ldr.id
                ';

            $drf_join = 'INNER JOIN odr_data_record_fields AS drf ON drf.data_record_id = ldr.id';
            $deleted_at = 'AND grandparent.deletedAt IS NULL AND grandparent_meta.deletedAt IS NULL AND dr.deletedAt IS NULL AND dr_meta.deletedAt IS NULL AND ldr.deletedAt IS NULL AND ldr_meta.deletedAt IS NULL ';
        }


        // ----------------------------------------
        // The second half of the native SQL queries depends primarily on what typeclass is being searched on
        $query = '';
        if ($typeclass == 'Radio') {
            // TODO - this won't really return correct results when searching on an unselected radioselection...both missing datarecordfield and missing radioselection entities will mess this query up
            $query = $query_start.$drf_join;
            if (!$searching_linked_datatype) {
                $query .= '
                    INNER JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
                    INNER JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
                    WHERE dr.data_type_id = '.$datatype_id.' AND ro.data_fields_id = '.$datafield_id.' AND ('.$search_params['str'].')
                    '.$deleted_at.' AND drf.deletedAt IS NULL
                    AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL ';
            }
            else {
                $query .= '
                    INNER JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
                    INNER JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
                    WHERE ldr.data_type_id = '.$datatype_id.' AND ro.data_fields_id = '.$datafield_id.' AND ('.$search_params['str'].')
                    '.$deleted_at.' AND drf.deletedAt IS NULL
                    AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL ';
            }

            $query .= $metadata_str;
        }
        else if ($typeclass == 'Image' || $typeclass == 'File') {
            // Assume that the datarecordfield entries might not exist...but require them to not be deleted if they do exist
            $query = $query_start;
            if (!$searching_linked_datatype)
                $query .= 'LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL) ';
            else
                $query .= 'LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = ldr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL) ';

            // The file/image entries might not exist either...but still require them to not be deleted if they do exist
            $query .= '
                LEFT JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id AND (e.deletedAt IS NULL OR e.id IS NULL)
                LEFT JOIN '.$table_names[$typeclass].'_meta AS e_m ON e_m.'.strtolower($typeclass).'_id = e.id AND (e_m.deletedAt IS NULL OR e_m.id IS NULL)
                WHERE dr.data_type_id = '.$datatype_id;


            if ( !isset($parameters['term_1']) && $parameters['term_0'] == '' && strpos($search_params['str'], '!') === false ) {
                // If the above conditions are true, then the user is effectively searching for datarecords without files/images uploaded to this datafield
                // ...Can't just use count() due to potential metadata constraints also being in $parameters
                $query .= ' AND e.id IS NULL ';
            }
            else {
                // Otherwise, do a regular search for the original filename
                $query .= ' AND ('.$search_params['str'].') ';
            }

            // Debug readability newline...
            $query .= "\n";

            // Ensure that entities required to exist are not deleted...
            if (!$searching_linked_datatype)
                $query .= 'AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL ';
            else
                $query .= 'AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND ldr.deletedAt IS NULL ';

            $query .= $metadata_str;
        }
        else {
            // Finish building the native SQL query that gets contents of all other storage entities
            $query = $query_start.$drf_join;
            $query .= '
                INNER JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE e.data_field_id = '.$datafield_id.' AND ('.$search_params['str'].')
                '.$deleted_at.' AND e.deletedAt IS NULL ';

            $query .= $metadata_str;

            // If one of the parameters involves the empty string...
            if ($null_drf_possible) {
                // Ensure that the query actually has a logical chance of returning results...
                $results_are_possible = self::canQueryReturnResults($search_params['str'], $search_params['params']);

                if ($results_are_possible) {
                    // Create a second native SQL query specifically to pull null drf entries, and union with the first
                    $query .= '
                        UNION
                        ';

                    if (!$searching_linked_datatype) {
                        $query .= $query_start.
                           'LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL)
                            LEFT JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                            WHERE dr.data_type_id = '.$datatype_id.'
                            AND e.id IS NULL
                            AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL ';

                        $query .= $metadata_str;
                    }
                    else {
                        $query .= $query_start.
                           'LEFT JOIN odr_data_record_fields AS drf ON drf.data_record_id = ldr.id AND ((drf.data_field_id = '.$datafield_id.' AND drf.deletedAt IS NULL) OR drf.id IS NULL)
                            LEFT JOIN '.$table_names[$typeclass].' AS e ON e.data_record_fields_id = drf.id
                            WHERE dr.data_type_id = '.$datatype_id.'
                            AND e.id IS NULL
                            AND grandparent.deletedAt IS NULL AND dr.deletedAt IS NULL AND ldr.deletedAt IS NULL ';

                        $query .= $metadata_str;
                    }
                }
            }
        }

if ( isset($debug['show_queries']) ) {
    $query = preg_replace('/[ ]+/', ' ', $query);   // replace all consecutive spaces with at most one space
    print $query."\n";
    print '$parameters: '.print_r($parameters, true)."\n";
}

        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $em->getConnection();
        $results = $conn->fetchAll($query, $parameters);

if ( isset($debug['show_queries']) ) {
    print '>> '.print_r($results, true)."\n";
    print '----------------------------------------'."\n";
}

        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Determines whether the provided string of MYSQL conditions has a chance of returning search results or not.
     * If it has no chance of returning results, then the union query that picks up null drf entries shouldn't be run...it would return datarecords that only match part of the query, instead of all
     *
     * @param string $str
     * @param array $params
     *
     * @return boolean
     */
    private function canQueryReturnResults($str, $params)
    {
        // Because right now the user isn't allowed to group logical operators, this single php statment will effectively suffice for determining MYSQL order of operations
        // Individual statements connected by AND will be executed first...the results of each block of ANDs will then be ORed together
        $pieces = explode(' OR ', $str);

        $results = array();
        foreach ($pieces as $piece) {
            if ( strpos($piece, 'AND') === false ) {
                // A single entry at this point of the array always has the chance to evaluate to true
                $results[] = true;
            }
            else {
                // If there are multiple exact matches required...e.g. e.value = :term_x AND e.value = :term_y ...
                if ( substr_count($piece, '=') > 1 ) {
                    // ...then unless each term of each exact match is identical, this piece of the query is guaranteed to return false...e.value can't be equal to 'a' and equal to 'b' at the same time, for instance

                    // Determine which of these search terms must be exact
                    $matches = array();
                    $pattern = '/ = :(term_\d+)/';
                    preg_match_all($pattern, $piece, $matches);

                    // Get the unique list of all of the search terms
                    $terms = array();
                    foreach ($matches[1] as $match)
                        $terms[] = $params[$match];
                    $terms = array_unique($terms);

                    // If all of the search terms are the same, then this piece of the search query has the chance to evaluate to true...otherwise, it will never evaluate to true
                    if ( count($terms) == 1 )
                        $results[] = true;
                    else
                        $results[] = false;
                }
            }
        }

        // If any part of this query has the chance of returning true, then actual search results are possible
        $results_are_possible = false;
        foreach ($results as $num => $result)
            $results_are_possible = $results_are_possible || $result;

        return $results_are_possible;
    }


    /**
     * Builds and runs native SQL queries for all datatypes that didn't already get metadata applied during self::runSearchQuery()
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $related_datatypes         @see self::getRelatedDatatypes()
     * @param integer $datatype_id
     * @param array $metadata
     * @param array $debug
     *
     * @return array
     */
    private function runMetadataSearchQuery($em, $related_datatypes, $datatype_id, $metadata, $debug)
    {
        // Need different queries depending how the unsearched datatype relates to the target datatype
        $search_metadata = array();
        $linked_join = '';
        $where = '';
        if ($datatype_id == $related_datatypes['target_datatype']) {
            $metadata_target = 'grandparent';
            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
        }
        else if ( isset($related_datatypes['child_datatypes'][ $datatype_id ]) ) {
            $metadata_target = 'dr';
            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
            $where = 'WHERE dr.data_type_id = '.$datatype_id.' ';
        }
        else if ( in_array($datatype_id, $related_datatypes['linked_datatypes']) ) {
            $metadata_target = 'ldr';
            $search_metadata = self::buildMetadataQueryStr($metadata[$datatype_id], $metadata_target);
            $linked_join =
               'INNER JOIN odr_linked_data_tree AS ldt ON dr.id = ldt.ancestor_id
                INNER JOIN odr_data_record AS ldr ON ldt.descendant_id = ldr.id
                INNER JOIN odr_data_record_meta AS ldr_meta ON ldr_meta.data_record_id = ldr.id ';
            $where = 'WHERE ldr.deletedAt IS NULL AND ldr_meta.deletedAt IS NULL AND ldr.data_type_id = '.$datatype_id.' AND dr.data_type_id = '.$related_datatypes['target_datatype'].' ';    // TODO - links to childtypes?
        }


        //
        $metadata_str = $search_metadata['metadata_str'];
        $parameters = $search_metadata['metadata_params'];

        $query =
           'SELECT dr.id AS dr_id
            FROM odr_data_record AS grandparent
            INNER JOIN odr_data_record_meta AS grandparent_meta ON grandparent_meta.data_record_id = grandparent.id
            INNER JOIN odr_data_record AS dr ON grandparent.id = dr.grandparent_id
            INNER JOIN odr_data_record_meta AS dr_meta ON dr_meta.data_record_id = dr.id
            ';
        if ($linked_join !== '')
            $query .= $linked_join;
        $query .= $where.' AND dr.deletedAt IS NULL AND dr_meta.deletedAt IS NULL AND grandparent.deletedAt IS NULL AND grandparent_meta.deletedAt IS NULL '.$metadata_str;

if ( isset($debug['show_queries']) ) {
    print '----------------------------------------'."\n";
    $query = preg_replace('/[ ]+/', ' ', $query);   // replace all consecutive spaces with at most one space
    print $query."\n";
    print '$parameters: '.print_r($parameters, true)."\n";
}

        // ----------------------------------------
        // Execute and return the native SQL query
        $conn = $em->getConnection();
        $results = $conn->fetchAll($query, $parameters);

if ( isset($debug['show_queries']) ) {
    print '>> '.print_r($results, true)."\n";
    print '----------------------------------------'."\n";
}

        // Save the query result so the search results are correctly restricted
        $datarecords = array();
        foreach ($results as $result)
            $datarecords[ $result['dr_id'] ] = 1;

        return $datarecords;
    }


    /**
     * Turns a specially built array of created/updated (by) and public date requirements for searching a datatype into native SQL
     *
     * @param array $metadata
     * @param string $target  'grandparent', 'dr', or 'ldr'...corresponding to attaching the metadata to the top-level, a child, or a linked datarecord, respectively
     *
     * @return array ...of native SQL and the corresponding parameters
     */
    private function buildMetadataQueryStr($metadata, $target)
    {
        $metadata_str = '';
        $metadata_params = array();

        // ----------------------------------------
        // Deal with modify dates and updatedBy
        if ( isset($metadata['updated']) ) {
            // Search by modify date
            if ( isset($metadata['updated']['s']) ) {
                $metadata_str .= 'AND '.$target.'.updated BETWEEN :updated_start AND :updated_end ';
                $metadata_params['updated_start'] = $metadata['updated']['s'];
                $metadata_params['updated_end'] = $metadata['updated']['e'];
            }

            // Search by updatedBy
            if ( isset($metadata['updated']['by']) ) {
                $metadata_str .= 'AND '.$target.'.updatedBy = :updated_by ';
                $metadata_params['updated_by'] = $metadata['updated']['by'];
            }
        }

        // ----------------------------------------
        // Deal with create dates and createdBy
        if ( isset($metadata['created']) ) {
            // Search by create date
            if ( isset($metadata['created']['s']) ) {
                $metadata_str .= 'AND '.$target.'.created BETWEEN :created_start AND :created_end ';
                $metadata_params['created_start'] = $metadata['created']['s'];
                $metadata_params['created_end'] = $metadata['created']['e'];
            }

            // Search by createdBy
            if ( isset($metadata['created']['by']) ) {
                $metadata_str .= 'AND '.$target.'.createdBy = :created_by ';
                $metadata_params['created_by'] = $metadata['created']['by'];
            }
        }

        // ----------------------------------------
        // Deal with public status
        if ( isset($metadata['public']) ) {
            if ( $metadata['public'] == 1 ) {
                // Search for public datarecords only
                $metadata_str .= 'AND '.$target.'_meta.public_date != :public_date ';
                $metadata_params['public_date'] = '2200-01-01 00:00:00';
            }
            else if ( $metadata['public'] == 0 ) {
                // Search for non-public datarecords only
                $metadata_str .= 'AND '.$target.'_meta.public_date = :public_date ';
                $metadata_params['public_date'] = '2200-01-01 00:00:00';
            }
        }

        $metadata = array(
            'metadata_str' => $metadata_str,
            'metadata_params' => $metadata_params,
        );

        return $metadata;
    }


    /**
     * Recursively traverses the datarecord tree for all datarecords of the datatype being searched on, and marks parent datarecords
     *  as "not matching search query" if all child datarecords of any given child datatype don't match
     *
     * @param array $matched_datarecords
     * @param array $descendants_of_datarecord
     * @param integer $current_datarecord_id
     *
     * @return integer
     */
    private function getIntermediateSearchResults(&$matched_datarecords, $descendants_of_datarecord, $current_datarecord_id)
    {
        // If this datarecord is excluded from the search results for some reason, don't bother checking any child datarecords...they're also excluded by definition
        if ( $matched_datarecords[$current_datarecord_id] == -1 ) {
            return -1;
        }
        // If this datarecord has children...
        else if ( is_array($descendants_of_datarecord[$current_datarecord_id]) ) {

            // ...then for each child datarecord of each child datatype...
            $include_count = 0;
            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $child_datarecords) {

                // ...keep track of how many of the child datarecords failed the search criteria
                $exclude_count = 0;
                foreach ($child_datarecords as $dr_id => $tmp) {
                    $num = self::getIntermediateSearchResults($matched_datarecords, $descendants_of_datarecord[$current_datarecord_id][$dt_id], $dr_id);

                    if ($num == -1)
                        $exclude_count++;
                    if ($num == 1)
                        $include_count++;
                }

                // If all of this datarecord's child datarecords for this child datatype didn't match the search criteria...
                if ( $exclude_count == count($descendants_of_datarecord[$current_datarecord_id][$dt_id]) ) {
                    // ...then this datarecord doesn't match the search criteria either
                    $matched_datarecords[$current_datarecord_id] = -1;
                    return -1;
                }
            }

            // ...if this point is reached, then the current datarecord isn't excluded because of child datarecords...include it in the search results
            if ($include_count > 0) {
                $matched_datarecords[$current_datarecord_id] = 1;
                return 1;
            }
            else {
                return 0;
            }

        }
        else {
            // ...otherwise, this datarecord has no children, and either matches the search or is not otherwise excluded
            return $matched_datarecords[$current_datarecord_id];
        }
    }


    /**
     * Recursively traverses the datarecord tree for all datarecords of the datatype being searched on, and returns a comma-separated
     *  list of all child/linked/top-level datarecords that effectively match the search query
     *
     * @param array $matched_datarecords
     * @param array $descendants_of_datarecord
     * @param string|integer $current_datarecord_id
     * @param boolean $is_top_level
     *
     * @return string
     */
    private function getFinalSearchResults($matched_datarecords, $descendants_of_datarecord, $current_datarecord_id, $is_top_level)
    {
        // If this datarecord is excluded from the search results for some reason, don't bother checking any child datarecords...they're also excluded by definition
        if ( $matched_datarecords[$current_datarecord_id] == -1 ) {
            return '';
        }
        else if ( $is_top_level && $matched_datarecords[$current_datarecord_id] == 0 ) {
            return '';
        }
        // If this datarecord has children...
        else if ( is_array($descendants_of_datarecord[$current_datarecord_id]) ) {
            // ...keep track of whether each child datarecord matched the search
            $datarecords = '';

            foreach ($descendants_of_datarecord[$current_datarecord_id] as $dt_id => $child_datarecords) {

                $dr_matches = '';
                foreach ($child_datarecords as $dr_id => $tmp)
                    $dr_matches .= self::getFinalSearchResults($matched_datarecords, $descendants_of_datarecord[$current_datarecord_id][$dt_id], $dr_id, false);

                if ($dr_matches == '') {
                    // None of the child datarecords of this datatype matched the search...therefore the parent datarecord doesn't match either
                    return '';
                }
                else {
                    // Save the child datarecords that matched this search
                    $datarecords .= $dr_matches;
                }
            }

            // ...otherwise, return this datarecord and all of the child datarecords that matched
            return $current_datarecord_id.','.$datarecords;
        }
        else {
            // ...otherwise, this datarecord has no children, and either matches the search or is not otherwise excluded
            return $current_datarecord_id.',';
        }
    }


    /**
     * Turns a piece of the search string into a more DQL-friendly format.
     *
     * @param string $str           The string to turn into DQL...
     * @param array $debug          Whether to print out debug info or not.
     * @param boolean $is_filename  Whether this is being parsed as a filename or not
     *
     * @return array
     */
    private function parseField($str, $debug, $is_filename = false) {
        // ?
        $str = str_replace(array("\n", "\r"), '', $str);

if ( isset($debug['search_string_parsing']) ) {
    print "\n".'--------------------'."\n";
    print $str."\n";
}

        $pieces = array();
        $in_quotes = false;
        $tmp = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
        
            if ($char == "\"") {
                if ($in_quotes) {
                    // found closing quote
                    $in_quotes = false;
        
                    // save fragment
                    $tmp .= "\"";
                    $pieces[] = $tmp;
                    $tmp = '';
        
                    // skip over next character?
        //            $i++;
                }
                else {
                    // found opening quote
                    $in_quotes = true;
                    $tmp = "\"";
                }
            }
            else {
                if ($in_quotes) {
                    // append to fragment
                    $tmp .= $char;
                }
                else {
                    switch ($char) {
                        case ' ':
                            // save any existing piece before saving the operator
                            if ($tmp !== '') {
                                $pieces[] = $tmp;
                                $tmp = '';
                            }
                            $pieces[] = '&&';
                            break;
                        case '!':
//                        case '-':
                            // attempt to ignore the operator if not attached to a term
                            /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '!';
                            break;
                        case '>':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '>=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '>';
                            break;
                        case '<':
                            // attempt to ignore the operator if not attached to a term
                            if ( $str[$i+1] == '=' /*&& $str[$i+2] !== ' '*/ ) {
                                $pieces[] = '<=';
                                $i++;
                            }
                            else /*if ( $str[$i+1] !== ' ' )*/
                                $pieces[] = '<';
                            break;
                        case 'o':
                        case 'O':
                            // only count this as an operator if the 'O' is part of the substring ' OR '
                            if ( $i != 0 && $str[$i-1] == ' ' && ($str[$i+1] == 'R' || $str[$i+1] == 'r') && $str[$i+2] == ' ' ) {
                                $pieces[] = '||';
        //                        $i++;
                                $i += 2;
/*
                                // cut out the 'AND' token that was added as a result of the preceding space
                                if ( $pieces[count($pieces)-2] == '&&' )
                                    unset( $pieces[count($pieces)-2] );
*/
                            }
                            else {
                                // otherwise, part of a string
                                $tmp .= $char;
                            }
                            break;
                        default:
                            // part of a string
                            $tmp .= $char;
                            break;
                    }
                }
            }
        }
        // save any remaining piece
        if ($tmp !== '')
            $pieces[] = $tmp;

if ( isset($debug['search_string_parsing']) )
    print_r($pieces);

        // clean up the array as best as possible
        $pieces = array_values($pieces);
        $first = true;
        $previous = 0;
        foreach ($pieces as $num => $piece) {
            // prevent operators needing two operands from being out in front
            if ( $first && self::isConnective($piece) ) {
                unset( $pieces[$num] );
                continue;
            }
            // save the first "good" token
            if ($first) {
                $first = false;
                $previous = $piece;
                continue;
            }

            // Delete some consecutive logical operators
            if ( $pieces[$num] == '&&' && $pieces[$num+1] == '&&' )
                unset( $pieces[$num] );
            else if ( $pieces[$num] == '&&' && $pieces[$num+1] == '||' )
                unset( $pieces[$num] );
            else if ( self::isLogicalOperator($previous) && self::isLogicalOperator($piece) )
                unset( $pieces[$num] );
            // delete operators after inequalities
            else if ( self::isInequality($previous) && (self::isConnective($piece) || self::isInequality($piece)) )
                unset( $pieces[$num] );
            // legitimate token
            else
                $previous = $piece;
        }

        // remove trailing operators...they're unmatched by definition
        $pieces = array_values($pieces);
        $num = count($pieces)-1;
        if ( self::isLogicalOperator($pieces[$num]) || self::isInequality($pieces[$num]) )
            unset( $pieces[$num] );

if ( isset($debug['search_string_parsing']) )
    print_r($pieces);

        $negate = false;
        $inequality = false;
        $parameters = array();

        $str = 'e.value';
        if ($is_filename)
            $str = 'e_m.original_file_name';

        $count = 0;
        foreach ($pieces as $num => $piece) {
            if ($piece == '!') {
                $negate = true;
            }
            else if ($piece == '&&') {
                if (!$is_filename)
                    $str .= ' AND e.value';
                else
                    $str .= ' AND e_m.original_file_name';
            }
            else if ($piece == '||') {
                if (!$is_filename)
                    $str .= ' OR e.value';
                else
                    $str .= ' OR e_m.original_file_name';
            }
            else if ($piece == '>') {
                $inequality = true;
                if ($negate)
                    $str .= ' <= ';
                else
                    $str .= ' > ';
            }
            else if ($piece == '<') {
                $inequality = true;
                if ($negate)
                    $str .= ' >= ';
                else
                    $str .= ' < ';
            }
            else if ($piece == '>=') {
                $inequality = true;
                if ($negate)
                    $str .= ' < ';
                else
                    $str .= ' >= ';
            }
            else if ($piece == '<=') {
                $inequality = true;
                if ($negate)
                    $str .= ' > ';
                else
                    $str .= ' <= ';
            }
            else {
                if (!$inequality) {
                    if ( strpos($piece, "\"") !== false ) {  // does have a quote
                        $piece = str_replace("\"", '', $piece);
                        if ( is_numeric($piece) )
                            if ( strpos($piece, '.') === false )
                                $piece = intval($piece);
                            else
                                $piece = floatval($piece);

                        if ($negate)
                            $str .= ' != ';
                        else
                            $str .= ' = ';
                    }
                    else {
                        // MYSQL escape characters due to use of LIKE
                        $piece = str_replace("\\", '\\\\', $piece);     // replace backspace character with double backspace
                        $piece = str_replace( array('%', '_'), array('\%', '\_'), $piece);   // escape existing percent and understore characters

                        $piece = '%'.$piece.'%';
                        if ($negate)
                            $str .= ' NOT LIKE ';
                        else
                            $str .= ' LIKE ';
                    }
                }
                else if ( is_numeric($piece) ) {
                    if ( strpos($piece, '.') === false )
                        $piece = intval($piece);
                    else
                        $piece = floatval($piece);
                }
                $negate = false;
                $inequality = false;
        
                $str .= ':term_'.$count;
                $parameters['term_'.$count] = $piece;
                $count++;
            }
        }
        $str = trim($str);

if ( isset($debug['search_string_parsing']) ) {
    print $str."\n";
    print_r($parameters);
    print "\n".'--------------------'."\n";
}

        return array('str' => $str, 'params' => $parameters);
    }


    /**
     * Returns true if the string describes a binary operator  a && b, a || b
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isConnective($str) {
        if ( $str == '&&' || $str == '||' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes a logical operator  &&, ||, !
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isLogicalOperator($str) {
        if ( $str == '&&' || $str == '||' || $str == '!' )
            return true;
        else
            return false;
    }


    /**
     * Returns true if the string describes an inequality
     *
     * @param string $str The string to test
     *
     * @return boolean 
     */
    private function isInequality($str) {
        if ( $str == '>=' || $str == '<=' || $str == '<' || $str == '>' )
            return true;
        else
            return false;
    }


    /**
     * @param string $str The string to convert
     *
     * @return string
     */
    private function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

}
