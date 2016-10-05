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

// Controllers/Classes
use ODR\OpenRepository\SearchBundle\Controller\DefaultController as SearchController;
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
use ODR\AdminBundle\Entity\UserGroup;
// Forms
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
     * @param string $target      "Results" or "Record"...where to redirect when a datarecord from this list is selected
     * @param string $search_key  Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset     Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     *
     * @param Request $request
     *
     * @return string
     */
    public function renderList($datarecords, $datatype, $theme, $user, $path_str, $target, $search_key, $offset, Request $request)
    {
        // -----------------------------------
        // Grab necessary objects
        $templating = $this->get('templating');
        $session = $this->get('session');

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

        $logged_in = false;
        $user_permissions = array();
        $datatype_permissions = array();
        $datafield_permissions = array();
        if ($user !== 'anon.') {
            $logged_in = true;
            $user_permissions = self::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
            $datafield_permissions = $user_permissions['datafields'];
        }

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) ) {
            // If the tab id exists, use that
            $odr_tab_id = $params['odr_tab_id'];
        }
        else {
            // ...otherwise, generate a random key to identify this tab
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $odr_tab_id = substr($tokenGenerator->generateToken(), 0, 15);
        }

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        // Save how many datarecords were passed to this function
        $total_datarecords = count($datarecords);

        // -----------------------------------
        // Determine where on the page to scroll to if possible
        $scroll_target = '';
        if ($session->has('scroll_target')) {
            $scroll_target = $session->get('scroll_target');
            if ($scroll_target !== '') {
                // Don't scroll to someplace on the page if the datarecord doesn't match the datatype
                /** @var DataRecord $datarecord */
                $datarecord = $repo_datarecord->find($scroll_target);
                if ( $datarecord == null || $datarecord->getDataType()->getId() != $datatype->getId() || !in_array($scroll_target, $datarecords) )
                    $scroll_target = '';

                // Null out the scroll target
                $session->set('scroll_target', '');     // WTF WHY
            }
        }


        // -----------------------------------
        $final_html = '';
        if ( $theme->getThemeType() == 'search_results' ) {
            // -----------------------------------
            // Ensure offset exists for shortresults list
            if ( (($offset-1) * $page_length) > count($datarecords) )
                $offset = 1;

            // Reduce datarecord_list to just the list that will get rendered
            $datarecord_list = array();
            $start = ($offset-1) * $page_length;
            for ($index = $start; $index < ($start + $page_length); $index++) {
                if ( !isset($datarecords[$index]) )
                    break;

                $datarecord_list[] = $datarecords[$index];
            }


            // -----------------------------------
            // Build the html required for the pagination header
            $pagination_html = self::buildPaginationHeader($total_datarecords, $offset, $path_str, $request);


            // ----------------------------------------
            // Load datatype and datarecord data from the cache
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // Grab the cached versions of all of the associated datatypes, and store them all at the same level in a single array
            // print $redis_prefix.'.cached_datatype_'.$datatype->getId();
            $datatype_array = self::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype->getId())));
            if ($bypass_cache || $datatype_array == false) {
                $datatype_array = self::getDatatypeData($em, self::getDatatreeArray($em, $bypass_cache), $datatype->getId(), $bypass_cache);
            }

            // Grab the cached versions of all of the associated datarecords, and store them all at the same level in a single array
            $datarecord_array = array();
            foreach ($datarecord_list as $num => $dr_id) {
                $datarecord_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$dr_id)));
                if ($bypass_cache || $datarecord_data == false)
                    $datarecord_data = self::getDatarecordData($em, $dr_id, $bypass_cache);

                foreach ($datarecord_data as $dr_id => $data)
                    $datarecord_array[$dr_id] = $data;
            }

            // Delete everything that the user isn't allowed to see from the datatype/datarecord arrays
            self::filterByGroupPermissions($datatype_array, $datarecord_array, $user_permissions);


            // -----------------------------------
            // Finally, render the list
            $template = 'ODRAdminBundle:ShortResults:shortresultslist.html.twig';
            $final_html = $templating->render(
                $template,
                array(
                    'datatype_array' => $datatype_array,
                    'datarecord_array' => $datarecord_array,
                    'theme_id' => $theme->getId(),

                    'initial_datatype_id' => $datatype->getId(),

                    'count' => $total_datarecords,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'odr_tab_id' => $odr_tab_id,

                    'logged_in' => $logged_in,

                    'pagination_html' => $pagination_html,

                    // required for load_datarecord_js.html.twig
                    'target' => $target,
                    'search_key' => $search_key,
                    'offset' => $offset,
                )
            );

        }
        else if ( $theme->getThemeType() == 'table' ) {
            // -----------------------------------
            // Grab the...
            $column_data = self::getDatatablesColumnNames($em, $theme, $datafield_permissions);
            $column_names = $column_data['column_names'];
            $num_columns = $column_data['num_columns'];
/*
print '<pre>';
print_r($column_data);
print '</pre>';
exit();
*/

            // Don't render the starting textresults list here, it'll always be loaded via ajax later

            // -----------------------------------
            //
            $template = 'ODRAdminBundle:TextResults:textresultslist.html.twig';
            if ($target == 'linking')
                $template = 'ODRAdminBundle:Edit:link_datarecord_form_search.html.twig';

            $final_html = $templating->render(
                $template,
                array(
                    'datatype' => $datatype,
                    'count' => $total_datarecords,
                    'column_names' => $column_names,
                    'num_columns' => $num_columns,
                    'odr_tab_id' => $odr_tab_id,
                    'page_length' => $page_length,
                    'scroll_target' => $scroll_target,
                    'user' => $user,
                    'user_permissions' => $datatype_permissions,
                    'theme_id' => $theme->getId(),

                    'logged_in' => $logged_in,

                    // required for load_datarecord_js.html.twig
                    'target' => $target,
                    'search_key' => $search_key,
                    'offset' => $offset,

                    // Provide the list of all possible datarecord ids to twig just incase...though not strictly used by the datatables ajax, the rows returned will always end up being some subset of this list
                    'all_datarecords' => $datarecords,
                )
            );
        }

        return $final_html;
    }


    /**
     * Attempt to load the textresult version of the cached entries for each datarecord in $datarecord_list.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datarecord_list           The list of datarecord ids that need rendered
     * @param Theme $theme                     The 'table' theme for the relevant datatype
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return array
     */
    public function renderTextResultsList($em, $datarecord_list, $theme, Request $request)
    {
        try {
            // --------------------
            // Store whether the user has view privileges for this datatype
            $datatype = $theme->getDataType();

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            $datafield_permissions = array();

            $can_view_datarecord = false;
            if ($user !== 'anon.') {
                $user_permissions = self::getUserPermissionsArray($em, $user->getId());
                $datatype_permissions = $user_permissions['datatypes'];
                $datafield_permissions = $user_permissions['datafields'];

                // Check if user has permissions to view non-public datarecords
                if ( isset($datatype_permissions[ $datatype->getId() ]) && isset($datatype_permissions[ $datatype->getId() ][ 'dr_view' ]) )
                    $can_view_datarecord = true;
            }
            // --------------------


            // Grab necessary objects
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Always bypass cache if in dev mode?
            $bypass_cache = false;
            if ($this->container->getParameter('kernel.environment') === 'dev')
                $bypass_cache = true;


            // ----------------------------------------
            // Attempt to load from memcached...
//        $datatype_revision = intval( $datatype->getRevision() );      // TODO?
            $rows = array();
            foreach ($datarecord_list as $num => $datarecord_id) {
                // Get the table version for this datarecord from memcached if possible
                $data = self::getRedisData(($redis->get($redis_prefix.'.datarecord_table_data_'.$datarecord_id)));
                if ($bypass_cache || $data == false)
                    $data = self::Text_GetDisplayData($em, $datarecord_id, $request);

                $row = array();
                // Only add this datarecord to the list if the user is allowed to see it...
                if ( $can_view_datarecord || $data['publicDate'] !== '2200-01-01 00:00:00' ) {
                    // Don't save values from datafields the user isn't allowed to see...
                    $dr_data = null;
                    foreach ($data[$theme->getId()] as $display_order => $df_data) {        // TODO - apparently provides wrong theme id here at times?
                        if ($dr_data == null)
                            $dr_data = array();

                        $df_id = $df_data['id'];
                        $df_value = $df_data['value'];
                        $df_is_public = $df_data['is_public'];

                        if ( $df_is_public || (isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view'])) )
                            $dr_data[] = $df_value;
                    }

                    // If the user isn't prevented from seeing all datafields comprising this layout, store the data in an array
                    if ( is_null($dr_data) ) {
                        throw new \Exception('Table Theme has no datafields attached to it');
                    }
                    else if (count($dr_data) > 0) {
                        $row[] = strval($datarecord_id);
                        $row[] = strval($data['default_sort_value']);

                        foreach ($dr_data as $tmp)
                            $row[] = strval($tmp);
                    }
                    else {
                        throw new \Exception('You are not allowed to view any of the Datafields used by this Table Theme');
                    }

                }

                // If something exists in the array, append it to the list
                if (count($row) > 0)
                    $rows[] = $row;
            }

            return $rows;
        }
        catch (\Exception $e) {
            throw new \Exception( $e->getMessage() );
        }
    }


    /**
     * Determines values for the pagination header
     *
     * @param integer $num_datarecords The total number of datarecords belonging to the datatype/search result
     * @param integer $offset          Which page of results the user is currently on
     * @param string $path_str         The base url used before paging gets involved...$path_str + '/2' would redirect to page 2, $path_str + '/3' would redirect to page 3, etc
     * @param Request $request
     *
     * @return array
     */
    protected function buildPaginationHeader($num_datarecords, $offset, $path_str, Request $request)
    {
        // Grab necessary objects
        $session = $request->getSession();

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) )
            $odr_tab_id = $params['odr_tab_id'];

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        // If only one page, don't bother with pagination block
        $num_pages = ceil( $num_datarecords / $page_length );
//        if ($num_pages == 1)
//            return '';

        // Ensure $offset is in bounds
        if ($offset === '' || $offset < 1)
            $offset = 1;
        if ( (($offset-1) * $page_length) > $num_datarecords )
            $offset = ceil($num_datarecords / $page_length);


        // Render the pagination block
        $templating = $this->get('templating');
        $html = $templating->render(
            'ODRAdminBundle:Default:pagination_header.html.twig',
            array(
                'num_pages' => $num_pages,
                'offset' => $offset,
                'path_str' => $path_str,
                'num_datarecords' => $num_datarecords,
                'page_length' => $page_length
            )
        );
        return $html;
    }


    /**
     * Get (or create) a list of datarecords returned by searching on the given search key
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param integer $datatype_id
     * @param string $search_key
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return array
     */
    public function getSavedSearch($em, $user, $datatype_permissions, $datafield_permissions, $datatype_id, $search_key, Request $request)
    {
        // Get necessary objects
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // ----------------------------------------
        // Going to need the search controller for determining whether $search_key is valid or not
        /** @var SearchController $search_controller */
        $search_controller = $this->get('odr_search_controller', $request);
        $search_controller->setContainer($this->container);

        // Determine whether the search key needs to be filtered based on the user's permissions
        $datafield_array = $search_controller->getSearchDatafieldsForUser($em, $user, $datatype_id, $datatype_permissions, $datafield_permissions);
        $search_controller->buildSearchArray($search_key, $datafield_array, $datatype_permissions);

        if ( $search_key !== $datafield_array['filtered_search_key'] )
            return array('redirect' => true, 'encoded_search_key' => $datafield_array['encoded_search_key'], 'datarecord_list' => '');


        // ----------------------------------------
        // Otherwise, the search_key is fine...check to see if a cached version exists
        $search_checksum = md5($search_key);

        // Attempt to load the search result for this search_key
        $data = array();
        $cached_searches = self::getRedisData(($redis->get($redis_prefix.'.cached_search_results')));
        if ( $cached_searches == false
            || !isset($cached_searches[$datatype_id])
            || !isset($cached_searches[$datatype_id][$search_checksum]) ) {

            // Saved search doesn't exist, redo the search and reload the results
            $ret = $search_controller->performSearch($search_key, $request);
            if ($ret['error'] == true)
                throw new \Exception( $ret['message'] );
            else if ($ret['redirect'] == true)
                return array('redirect' => true, 'encoded_search_key' => $datafield_array['encoded_search_key'], 'datarecord_list' => '');

            $cached_searches = self::getRedisData($redis->get($redis_prefix.'.cached_search_results'));
        }

        // ----------------------------------------
        // Now that the search result is guaranteed to exist, grab it
        $search_params = $cached_searches[$datatype_id][$search_checksum];

        // Pull the individual pieces of info out of the search results
        $data['redirect'] = false;
        $data['search_checksum'] = $search_checksum;
        $data['datatype_id'] = $datatype_id;

        $data['searched_datafields'] = $search_params['searched_datafields'];
        $data['encoded_search_key'] = $search_params['encoded_search_key'];

        $data['datarecord_list'] = $search_params['datarecord_list'];                     // ...just top-level datarecords
        $data['complete_datarecord_list'] = $search_params['complete_datarecord_list'];   // ...top-level, child, and linked datarecords

        return $data;
    }


    /**
     * Utility function to let controllers easily force a redirect to a different search results page
     *
     * @param User $user
     * @param string $new_url
     *
     * @return Response
     */
    public function searchPageRedirect($user, $new_url)
    {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            //
            $logged_in = true;
            if ($user === 'anon.')
                $logged_in = false;

            //
            $templating = $this->get('templating');
            $return['d'] = array(
                'html' => $templating->render(
                    'ODROpenRepositorySearchBundle:Default:searchpage_redirect.html.twig',
                    array(
                        'logged_in' => $logged_in,
                        'url' => $new_url,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x412584345 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Determines values for the search header (next/prev/return to search results) of Results/Records when searching
     *
     * @param string $datarecord_list A comma-separated list of datarecord ids that satisfy the search TODO - why comma-separated?  just going to explode() them...
     * @param integer $datarecord_id  The database id of the datarecord the user is currently at...used to determine where the next/prev datarecord buttons redirect to
     * @param Request $request
     *
     * @return array
     */
    protected function getSearchHeaderValues($datarecord_list, $datarecord_id, Request $request)
    {
        // Grab necessary objects
        $session = $request->getSession();

        // Grab the tab's id, if it exists
        $params = $request->query->all();
        $odr_tab_id = '';
        if ( isset($params['odr_tab_id']) )
            $odr_tab_id = $params['odr_tab_id'];

        // Grab the page length for this tab from the session, if possible
        $page_length = 100;
        if ( $odr_tab_id !== '' && $session->has('stored_tab_data') ) {
            $stored_tab_data = $session->get('stored_tab_data');
            if ( isset($stored_tab_data[$odr_tab_id]) ) {
                if ( isset($stored_tab_data[$odr_tab_id]['page_length']) ) {
                    $page_length = $stored_tab_data[$odr_tab_id]['page_length'];
                }
                else {
                    $stored_tab_data[$odr_tab_id]['page_length'] = $page_length;
                    $session->set('stored_tab_data', $stored_tab_data);
                }
            }
        }

        $next_datarecord = '';
        $prev_datarecord = '';
        $search_result_current = '';
        $search_result_count = '';

        if ($datarecord_list !== null && trim($datarecord_list) !== '') {
            // Turn the search results string into an array of datarecord ids
            $search_results = explode(',', trim($datarecord_list));

            foreach ($search_results as $num => $id) {
                if ( $datarecord_id == $id ) {
                    $search_result_current = $num+1;
                    $search_result_count = count($search_results);

                    if ( $num == count($search_results)-1 )
                        $next_datarecord = $search_results[0];
                    else
                        $next_datarecord = $search_results[$num+1];

                    if ($num == 0)
                        $prev_datarecord = $search_results[ count($search_results)-1 ];
                    else
                        $prev_datarecord = $search_results[$num-1];
                }
            }
        }

        return array(
            'page_length' => $page_length,
            'next_datarecord' => $next_datarecord,
            'prev_datarecord' => $prev_datarecord,
            'search_result_current' => $search_result_current,
            'search_result_count' => $search_result_count
        );
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
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
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
                $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
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
            $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
            $filename = 'File_'.$object_id.'.'.$base_obj->getExt();

            // crypto bundle requires an absolute path to the file to encrypt/decrypt
            $absolute_path = realpath($file_upload_path).'/'.$filename;
        }
        else if ($object_type == 'image') {
            // Grab the image and associated information
            /** @var Image $base_obj */
            $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
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
     * Determines and returns an array of top-level datatype ids
     *
     * @return int[]
     */
    public function getTopLevelDatatypes()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery(
           'SELECT dt.id AS datatype_id
            FROM ODRAdminBundle:DataType AS dt
            WHERE dt.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $all_datatypes = array();
        foreach ($results as $num => $result)
            $all_datatypes[] = $result['datatype_id'];

        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id
            FROM ODRAdminBundle:DataTree AS dt
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE dtm.is_link = 0
            AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        );
        $results = $query->getArrayResult();

        $parent_of = array();
        foreach ($results as $num => $result)
            $parent_of[ $result['descendant_id'] ] = $result['ancestor_id'];

        $top_level_datatypes = array();
        foreach ($all_datatypes as $datatype_id) {
            if ( !isset($parent_of[$datatype_id]) )
                $top_level_datatypes[] = $datatype_id;
        }

        return $top_level_datatypes;
    }


    /**
     * Utility function to returns the DataTree table in array format
     * TODO: This function is a really bad idea - will be absolutely GIGANTIC at some point.
     * Why is this needed? Plus, how do you know when it needs to be flushed? 
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param boolean $force_rebuild
     *
     * @return array
     */
    public function getDatatreeArray($em, $force_rebuild = false)
    {
        // Attempt to load from cache first
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $datatree_array = self::getRedisData(($redis->get($redis_prefix.'.cached_datatree_array')));
        if ( !($force_rebuild || $datatree_array == false) ) {
            return $datatree_array;
        }

        $query = $em->createQuery(
           'SELECT ancestor.id AS ancestor_id, descendant.id AS descendant_id, dtm.is_link AS is_link, dtm.multiple_allowed AS multiple_allowed
            FROM ODRAdminBundle:DataType AS ancestor
            JOIN ODRAdminBundle:DataTree AS dt WITH ancestor = dt.ancestor
            JOIN ODRAdminBundle:DataTreeMeta AS dtm WITH dtm.dataTree = dt
            JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
            WHERE ancestor.deletedAt IS NULL AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND descendant.deletedAt IS NULL');
        $results = $query->getArrayResult();

        $datatree_array = array(
            'descendant_of' => array(),
            'linked_from' => array(),
            'multiple_allowed' => array(),
        );
        foreach ($results as $num => $result) {
            $ancestor_id = $result['ancestor_id'];
            $descendant_id = $result['descendant_id'];
            $is_link = $result['is_link'];
            $multiple_allowed = $result['multiple_allowed'];

            if ( !isset($datatree_array['descendant_of'][$ancestor_id]) )
                $datatree_array['descendant_of'][$ancestor_id] = '';

            if ($is_link == 0) {
                $datatree_array['descendant_of'][$descendant_id] = $ancestor_id;
            }
            else {
                if ( !isset($datatree_array['linked_from'][$descendant_id]) )
                    $datatree_array['linked_from'][$descendant_id] = array();

                $datatree_array['linked_from'][$descendant_id][] = $ancestor_id;
            }


            if ($multiple_allowed == 1)
                $datatree_array['multiple_allowed'][$descendant_id] = $ancestor_id;
        }

        // Store in cache and return
//print '<pre>'.print_r($datatree_array, true).'</pre>';  exit();
        $redis->set($redis_prefix.'.cached_datatree_array', gzcompress(serialize($datatree_array)));
        return $datatree_array;
    }


    /**
     * Returns the id of the grandparent of the given datatype
     *
     * @param array $datatree_array         @see self::getDatatreeArray()
     * @param integer $initial_datatype_id
     *
     * @return integer
     */
    protected function getGrandparentDatatypeId($datatree_array, $initial_datatype_id)
    {
        $grandparent_datatype_id = $initial_datatype_id;
        while( isset($datatree_array['descendant_of'][$grandparent_datatype_id]) && $datatree_array['descendant_of'][$grandparent_datatype_id] !== '' )
            $grandparent_datatype_id = $datatree_array['descendant_of'][$grandparent_datatype_id];

        return $grandparent_datatype_id;
    }


    /**
     * Automatically decompresses and unserializes redis data.
     *
     * @throws \Exception
     *
     * @param string $redis_value - the value returned by the redis call.
     *
     * @return boolean|string
     */
    public static function getRedisData($redis_value) {
        // print "::" . strlen($redis_value) . "::";
        if(strlen($redis_value) > 0) {
            return unserialize(gzuncompress($redis_value));
        }
        return false;
    }


    /**
     * Ensures the given user is in the given group.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param Group $group
     * @param User $admin_user
     *
     * @return UserGroup
     */
    protected function ODR_createUserGroup($em, $user, $group, $admin_user)
    {
        // Check to see if the user already belongs to this group
        $query = $em->createQuery(
           'SELECT ug
            FROM ODRAdminBundle:UserGroup AS ug
            WHERE ug.user = :user_id AND ug.group = :group_id
            AND ug.deletedAt IS NULL'
        )->setParameters( array('user_id' => $user->getId(), 'group_id' => $group->getId()) );
        /** @var UserGroup[] $results */
        $results = $query->getResult();

        $user_group = null;
        if ( count($results) > 0 ) {
            // If an existing user_group entry was found, return it and don't do anything else
            foreach ($results as $num => $ug)
                return $ug;
        }
        else {
            // ...otherwise, create a new user_group entry
            $user_group = new UserGroup();
            $user_group->setUser($user);
            $user_group->setGroup($group);

            $user_group->setCreatedBy($admin_user);

            $em->persist($user_group);
            $em->flush();
        }

        return $user_group;
    }


    /**
     * Create a new Group for users of the given datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataType $datatype               An id for a top-level datatype
     * @param string $initial_purpose          One of 'admin', 'edit_all', 'view_all', 'view_only', or ''
     *
     * @return array
     */
    protected function ODR_createGroup($em, $user, $datatype, $initial_purpose = '')
    {
        // ----------------------------------------
        // Create the Group entity
        $group = new Group();
        $group->setDataType($datatype);
        $group->setPurpose($initial_purpose);

        $group->setCreatedBy($user);

        $em->persist($group);
        $em->flush();
        $em->refresh($group);


        // Create the GroupMeta entity
        $group_meta = new GroupMeta();
        $group_meta->setGroup($group);
        if ($initial_purpose == 'admin') {
            $group_meta->setGroupName('Default Group - Admin');
            $group_meta->setGroupDescription('Users in this default Group are always allowed to view and edit all Datarecords, modify all layouts, and change permissions of any User with regards to this Datatype.');
        }
        else if ($initial_purpose == 'edit_all') {
            $group_meta->setGroupName('Default Group - Editor');
            $group_meta->setGroupDescription('Users in this default Group can always both view and edit all Datarecords and Datafields of this Datatype.');
        }
        else if ($initial_purpose == 'view_all') {
            $group_meta->setGroupName('Default Group - View All');
            $group_meta->setGroupDescription('Users in this default Group always have the ability to see non-public Datarecords and Datafields of this Datatype, but cannot make any changes.');
        }
        else if ($initial_purpose == 'view_only') {
            $group_meta->setGroupName('Default Group - View');
            $group_meta->setGroupDescription('Users in this default Group are always able to see public Datarecords and Datafields of this Datatype, though they cannot make any changes.  If the Datatype is public, then adding Users to this Group is meaningless.');
        }
        else {
            $group_meta->setGroupName('New user group for '.$datatype->getShortName());
            $group_meta->setGroupDescription('');
        }

        $group_meta->setCreatedBy($user);
        $group_meta->setUpdatedBy($user);

        $em->persist($group_meta);
        $em->flush();
        $em->refresh($group_meta);


        // ----------------------------------------
        // Need to keep track of which datatypes are top-level
        $top_level_datatypes = self::getTopLevelDatatypes();

        // Create the initial datatype permission entries
        $include_links = false;
        $associated_datatypes = self::getAssociatedDatatypes($em, array($datatype->getId()), $include_links);   // TODO - if datatypes are eventually going to be undeleteable, then this needs to also return deleted child datatypes
//print_r($associated_datatypes);

        // Build a single INSERT INTO query to add GroupDatatypePermissions entries for this top-level datatype and for each of its children
        $query_str = '
            INSERT INTO odr_group_datatype_permissions (
                group_id, data_type_id,
                can_view_datatype, can_view_datarecord, can_add_datarecord, can_delete_datarecord, can_design_datatype, is_datatype_admin,
                created, createdBy, updated, updatedBy
            )
            VALUES ';

        $has_datatypes = false;
        foreach ($associated_datatypes as $num => $dt_id) {
            $has_datatypes = true;

            if ($initial_purpose == 'admin')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "1", "1", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ($initial_purpose == 'edit_all')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "1", "1", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ($initial_purpose == 'view_all')
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "1", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else if ( $initial_purpose == 'view_only' || in_array($dt_id, $top_level_datatypes) )
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "1", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
            else
                $query_str .= '("'.$group->getId().'", "'.$dt_id.'", "0", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
        }

        if ($has_datatypes) {
            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);
        }

        // ----------------------------------------
        // Create the initial datafield permission entries
        $em->getFilters()->disable('softdeleteable');   // Temporarily disable the code that prevents the following query from returning deleted datafields
        $query = $em->createQuery(
           'SELECT df.id AS df_id, df.deletedAt AS df_deletedAt
            FROM ODRAdminBundle:DataFields AS df
            JOIN ODRAdminBundle:DataType AS dt WITH df.dataType = dt
            WHERE dt.id IN (:datatype_ids)'
        )->setParameters( array('datatype_ids' => $associated_datatypes) );
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');
//print_r($results);  exit();

        // Build a single INSERT INTO query to add GroupDatafieldPermissions entries for all datafields of this top-level datatype and its children
        $query_str = '
            INSERT INTO odr_group_datafield_permissions (
                group_id, data_field_id,
                can_view_datafield, can_edit_datafield,
                created, createdBy, updated, updatedBy, deletedAt
            )
            VALUES ';

        $has_datafields = false;
        foreach ($results as $result) {
            $has_datafields = true;
            $df_id = $result['df_id'];
            $df_deletedAt = $result['df_deletedAt'];

            // Want to also store GroupDatafieldPermission entries for deleted datafields, in case said datafields get undeleted later...
            $deletedAt = "NULL";
            if ( !is_null($df_deletedAt) )
                $deletedAt = '"'.$df_deletedAt->format('Y-m-d H:i:s').'"';

            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all')
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
            else if ($initial_purpose == 'view_all')
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "1", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
            else
                $query_str .= '("'.$group->getId().'", "'.$df_id.'", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'", '.$deletedAt.'),'."\n";
        }

        if ($has_datafields) {
            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);
        }

        // ----------------------------------------
        // Automatically add super-admin users to new default "admin" groups
        if ($initial_purpose == 'admin') {
            // Permissons are stored in memcached to allow other parts of the server to force a rebuild of any user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Grab all users
            $user_manager = $this->container->get('fos_user.user_manager');
            /** @var User[] $user_list */
            $user_list = $user_manager->findUsers();

            // Locate those with super-admin permissions...
            foreach ($user_list as $u) {
                if ( $u->hasRole('ROLE_SUPER_ADMIN') ) {
                    // ...add the super admin to this new admin group
                    self::ODR_createUserGroup($em, $u, $group, $user);

                    // ...delete the cached list of permissions for each super-admin belongs to
                    $redis->del($redis_prefix.'.user_'.$u->getId().'_permissions');
                }
            }
        }

        return array('group' => $group, 'group_meta' => $group_meta);
    }


    /**
     * Creates GroupDatatypePermissions for all groups when a new datatype is created.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataType $datatype
     * @param boolean $is_top_level
     */
    protected function ODR_createGroupsForDatatype($em, $user, $datatype, $is_top_level)
    {
        $datatype_id = $datatype->getId();

        // Locate all groups for this datatype's grandparent
        $repo_group = $em->getRepository('ODRAdminBundle:Group');
        $groups = false;

        /** @var Group[] $groups */
        if ($is_top_level) {
            $groups = $repo_group->findBy( array('dataType' => $datatype->getId()) );
            if ($groups == false) {
                // Determine whether this datatype has the default groups already...
                $admin_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'admin') );
                if ($admin_group == false)
                    self::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'admin');

                $edit_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'edit_all') );
                if ($edit_group == false)
                    self::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'edit_all');

                $view_all_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_all') );
                if ($view_all_group == false)
                    self::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'view_all');

                $view_only_group = $repo_group->findOneBy( array('dataType' => $datatype->getId(), 'purpose' => 'view_only') );
                if ($view_only_group == false)
                    self::ODR_createGroup($em, $datatype->getCreatedBy(), $datatype, 'view_only');


                // Now that the default groups exist, reload them
                $groups = $repo_group->findBy( array('dataType' => $datatype_id) );
            }
        }
        else {
            // This function call is to create initial groups for a child datatype...locate its grandparent id
            $datatree_array = self::getDatatreeArray($em, true);
            $grandparent_datatype_id = self::getGrandparentDatatypeId($datatree_array, $datatype_id);

            // Load all groups belonging to the grandparent datatype
            $groups = $repo_group->findBy( array('dataType' => $grandparent_datatype_id) );
        }

        // If this function is called to create groups for a top-level datatype, the following section wouldn't be needed since it's already covered by in self::ODR_createGroup()...
        if ( !$is_top_level ) {

            // ----------------------------------------
            // Going to need these later...
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');


            // ----------------------------------------
            // Load the list of groups and users this INSERT INTO query will affect
            $group_list = array();
            foreach ($groups as $group)
                $group_list[] = $group->getId();

            $query = $em->createQuery(
               'SELECT DISTINCT(u.id) AS user_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
                WHERE ug.group IN (:groups)
                AND ug.deletedAt IS NULL'
            )->setParameters( array('groups' => $group_list) );
            $results = $query->getArrayResult();

            $user_list = array();
            foreach ($results as $result)
                $user_list[] = $result['user_id'];


            // ----------------------------------------
            // Build a single INSERT INTO query to add a GroupDatatypePermissions entry for this child datatype to all groups found previously
            $query_str = '
                INSERT INTO odr_group_datatype_permissions (
                    group_id, data_type_id,
                    can_view_datatype, can_view_datarecord, can_add_datarecord, can_delete_datarecord, can_design_datatype, is_datatype_admin,
                    created, createdBy, updated, updatedBy
                )
                VALUES ';

            foreach ($groups as $group) {
                // Default permissions depend on the original purpose of this group...
                $initial_purpose = $group->getPurpose();

                $cache_update = null;
                if ($initial_purpose == 'admin') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "1", "1", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1, 'dr_add' => 1, 'dr_delete' => 1,/* 'dt_design' => 1,*/ 'dt_admin' => 1);
                }
                else if ($initial_purpose == 'edit_all') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "1", "1", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1, 'dr_add' => 1, 'dr_delete' => 1);
                }
                else if ($initial_purpose == 'view_all') {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "1", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1, 'dr_view' => 1);
                }
                else if ($initial_purpose == 'view_only' ) {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "1", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    $cache_update = array('dt_view' => 1);
                }
                else {
                    $query_str .= '("'.$group->getId().'", "'.$datatype_id.'", "0", "0", "0", "0", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                    /* no need to update the cache entry with this */
                }

                if ($cache_update != null) {
                    // Immediately update group permissions with the new datatype, if a cached version of those permissions exists
                    $group_permissions = self::getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
                    if ($group_permissions != false) {
                        $group_permissions['datatypes'][$datatype_id] = $cache_update;
                        $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
                    }
                }
            }

            // Get rid of the trailing comma and replace with a semicolon
            $query_str = substr($query_str, 0, -2).';';
            $conn = $em->getConnection();
            $rowsAffected = $conn->executeUpdate($query_str);


            // ----------------------------------------
            // Delete all permission entries for each affected user...
            // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
            foreach ($user_list as $user_id)
                $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');
        }
    }


    /**
     * Creates GroupDatafieldPermissions for all groups when a new datafield is created, and updates existing cache entries for groups and users with the new datafield.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user
     * @param DataFields $datafield
     */
    protected function ODR_createGroupsForDatafield($em, $user, $datafield)
    {
        // ----------------------------------------
        // Going to need these later...
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');


        // ----------------------------------------
        // Locate this datafield's datatype's grandparent
        $datatree_array = self::getDatatreeArray($em);
        $datatype_id = $datafield->getDataType()->getId();
        $grandparent_datatype_id = self::getGrandparentDatatypeId($datatree_array, $datatype_id);

        // Locate all groups for this datatype's grandparent
        /** @var Group[] $groups */
        $groups = $em->getRepository('ODRAdminBundle:Group')->findBy( array('dataType' => $grandparent_datatype_id) );
        // Unless something has gone wrong previously, there should always be results in this


        // ----------------------------------------
        // Load the list of groups and users this INSERT INTO query will affect
        $group_list = array();
        foreach ($groups as $group)
            $group_list[] = $group->getId();

        $query = $em->createQuery(
           'SELECT DISTINCT(u.id) AS user_id
            FROM ODRAdminBundle:UserGroup AS ug
            JOIN ODROpenRepositoryUserBundle:User AS u WITH ug.user = u
            WHERE ug.group IN (:groups)
            AND ug.deletedAt IS NULL'
        )->setParameters( array('groups' => $group_list) );
        $results = $query->getArrayResult();

        $user_list = array();
        foreach ($results as $result)
            $user_list[] = $result['user_id'];


        // ----------------------------------------
        // Build a single INSERT INTO query to add a GroupDatafieldPermissions entry for this datafield to all groups found previously
        $query_str = '
            INSERT INTO odr_group_datafield_permissions (
                group_id, data_field_id,
                can_view_datafield, can_edit_datafield,
                created, createdBy, updated, updatedBy
            )
            VALUES ';
        foreach ($groups as $group) {
            $initial_purpose = $group->getPurpose();

            $cache_update = null;
            if ($initial_purpose == 'admin' || $initial_purpose == 'edit_all') {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "1", "1", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                $cache_update = array('view' => 1, 'edit' => 1);
            }
            else if ($initial_purpose == 'view_all') {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "1", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                $cache_update = array('view' => 1);
            }
            else {
                $query_str .= '("'.$group->getId().'", "'.$datafield->getId().'", "0", "0", NOW(), "'.$user->getId().'", NOW(), "'.$user->getId().'"),'."\n";
                /* no need to update the cache entry with this */
            }

            if ($cache_update != null) {
                // Immediately update group permissions with the new datafield, if a cached version of those permissions exists
                $group_permissions = self::getRedisData(($redis->get($redis_prefix.'.group_'.$group->getId().'_permissions')));
                if ($group_permissions != false) {
                    if ( !isset($group_permissions['datafields'][$datatype_id]) )
                        $group_permissions['datafields'][$datatype_id] = array();

                    $group_permissions['datafields'][$datatype_id][$datafield->getId()] = $cache_update;
                    $redis->set($redis_prefix.'.group_'.$group->getId().'_permissions', gzcompress(serialize($group_permissions)));
                }
            }
        }

        // Get rid of the trailing comma and replace with a semicolon
        $query_str = substr($query_str, 0, -2).';';
        $conn = $em->getConnection();
        $rowsAffected = $conn->executeUpdate($query_str);


        // ----------------------------------------
        // Delete all permission entries for each affected user...
        // Not updating cached entry because it's a combination of all group permissions, and would take as much work to figure out what all to change as it would to just rebuild it
        foreach ($user_list as $user_id)
            $redis->del($redis_prefix.'.user_'.$user_id.'_permissions');
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_group_meta = new GroupMeta();
            $new_group_meta->setGroup($group);

            $new_group_meta->setGroupName( $old_meta_entry->getGroupName() );
            $new_group_meta->setGroupDescription( $old_meta_entry->getGroupDescription() );
            $new_group_meta->setDatarecordRestriction( $old_meta_entry->getDatarecordRestriction() );

            $new_group_meta->setCreatedBy($user);
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
            // Create a new UserPermissions entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_permission = new GroupDatatypePermissions();
            $new_permission->setGroup( $permission->getGroup() );
            $new_permission->setDataType( $permission->getDataType() );

            $new_permission->setCanViewDatatype( $permission->getCanViewDatatype() );
            $new_permission->setCanViewDatarecord( $permission->getCanViewDatarecord() );
            $new_permission->setCanAddDatarecord( $permission->getCanAddDatarecord() );
            $new_permission->setCanDeleteDatarecord( $permission->getCanDeleteDatarecord() );
            $new_permission->setCanDesignDatatype( $permission->getCanDesignDatatype() );
            $new_permission->setIsDatatypeAdmin( $permission->getIsDatatypeAdmin() );

            $new_permission->setCreatedBy($user);
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
            // Create a new UserPermissions entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_permission = new GroupDatafieldPermissions();
            $new_permission->setGroup( $permission->getGroup() );
            $new_permission->setDataField( $permission->getDataField() );

            $new_permission->setCanViewDatafield( $permission->getCanViewDatafield() );
            $new_permission->setCanEditDatafield( $permission->getCanEditDatafield() );

            $new_permission->setCreatedBy($user);
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
     * Gets and returns the permissions array for the given group.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $user_id
     * @param boolean $force_rebuild
     *
     * @throws \Exception
     *
     * @return array
     */
    public function getUserPermissionsArray($em, $user_id, $force_rebuild = false)
    {
        try {
            // Permissons are stored in memcached to allow other parts of the server to force a rebuild of any user's permissions
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

//$force_rebuild = true;
            $user_permissions = self::getRedisData(($redis->get($redis_prefix.'.user_'.$user_id.'_permissions')));
            if ( !$force_rebuild && $user_permissions != false )
                return $user_permissions;


            // ----------------------------------------
            // ...otherwise, get which groups the user belongs to
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:UserGroup AS ug
                JOIN ODRAdminBundle:Group AS g WITH ug.group = g
                WHERE ug.user = :user_id
                AND ug.deletedAt IS NULL AND g.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user_id) );
            $results = $query->getArrayResult();

            $user_groups = array();
            foreach ($results as $result)
                $user_groups[] = $result['group_id'];


            // ----------------------------------------
            // For each group the user belongs to, attempt to load that group's permissions from the cache
            $group_permissions = array();
            foreach ($user_groups as $num => $group_id) {
                // Attempt to load the permissions for this group
                $permissions = self::getRedisData(($redis->get($redis_prefix.'.group_'.$group_id.'_permissions')));

                if ( $force_rebuild || $permissions == false ) {
                    $permissions = self::rebuildGroupPermissionsArray($em, $group_id);
                    $redis->set($redis_prefix.'.group_'.$group_id.'_permissions', gzcompress(serialize($permissions)));
                }

                $group_permissions[$group_id] = $permissions;
            }


            // ----------------------------------------
            // Merge these group permissions into a single array for this user
            $user_permissions = array('datatypes' => array(), 'datafields' => array());
            foreach ($group_permissions as $group_id => $group_permission) {
                // TODO - datarecord restriction?

                foreach ($group_permission['datatypes'] as $dt_id => $dt_permissions) {
                    foreach ($dt_permissions as $permission => $num) {
                        $user_permissions['datatypes'][$dt_id][$permission] = 1;
                    }
                }

                foreach ($group_permission['datafields'] as $dt_id => $datafields) {
                    foreach ($datafields as $df_id => $df_permissions) {
                        if ( isset($df_permissions['view']) ) {
                            $user_permissions['datafields'][$df_id]['view'] = 1;
                        }

                        if ( isset($df_permissions['edit']) ) {
                            $user_permissions['datafields'][$df_id]['edit'] = 1;

                            $user_permissions['datatypes'][$dt_id]['dr_edit'] = 1;
                        }
                    }
                }
            }

            // If child datatypes have the "dr_edit" permission, ensure their parents do as well
            $datatree_array = self::getDatatreeArray($em, $force_rebuild);

            foreach ($user_permissions['datatypes'] as $dt_id => $dt_permissions) {
                if ( isset($dt_permissions['dr_edit']) ) {

                    $parent_datatype_id = $dt_id;
                    while( isset($datatree_array['descendant_of'][$parent_datatype_id]) && $datatree_array['descendant_of'][$parent_datatype_id] !== '' ) {
                        $parent_datatype_id = $datatree_array['descendant_of'][$parent_datatype_id];
                        $user_permissions['datatypes'][$parent_datatype_id]['dr_edit'] = 1;
                    }
                }
            }

            // Store that array in the cache
            $redis->set($redis_prefix.'.user_'.$user_id.'_permissions', gzcompress(serialize($user_permissions)));


            // ----------------------------------------
            // Return the permissions for all groups this user belongs to
            return $user_permissions;
        }
        catch (\Exception $e) {
            throw new \Exception( $e->getMessage() );
        }
    }


    /**
     * Rebuilds the cached version of a group's datatype/datafield permissions array
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $group_id
     *
     * @return array
     */
    protected function rebuildGroupPermissionsArray($em, $group_id)
    {
        // Load all permission entities from the database for the given group
        $query = $em->createQuery(
           'SELECT g, gm, gdtp, dt, gdfp, df, df_dt
            FROM ODRAdminBundle:Group AS g
            JOIN g.groupMeta AS gm
            LEFT JOIN g.groupDatatypePermissions AS gdtp
            LEFT JOIN gdtp.dataType AS dt
            LEFT JOIN g.groupDatafieldPermissions AS gdfp
            LEFT JOIN gdfp.dataField AS df
            LEFT JOIN df.dataType AS df_dt
            WHERE g.id = :group_id
            AND g.deletedAt IS NULL AND gm.deletedAt IS NULL AND gdtp.deletedAt IS NULL AND gdfp.deletedAt IS NULL AND dt.deletedAt IS NULL AND df.deletedAt IS NULL AND df_dt.deletedAt IS NULL'
        )->setParameters( array('group_id' => $group_id) );
        $results = $query->getArrayResult();
//print '<pre>'.print_r($results, true).'</pre>'; exit();

        // Read the query result to find...
        $datarecord_restriction = '';
        $datatype_permissions = array();
        $datafield_permissions = array();

        foreach ($results as $group) {
            // Extract datarecord restriction first
            $datarecord_restriction = $group['groupMeta'][0]['datarecord_restriction'];

            // Build the permissions list for datatypes
            foreach ($group['groupDatatypePermissions'] as $num => $permission) {
                $dt_id = $permission['dataType']['id'];
                $datatype_permissions[$dt_id] = array();

                if ($permission['can_view_datatype'])
                    $datatype_permissions[$dt_id]['dt_view'] = 1;
                if ($permission['can_view_datarecord'])
                    $datatype_permissions[$dt_id]['dr_view'] = 1;
                if ($permission['can_add_datarecord'])
                    $datatype_permissions[$dt_id]['dr_add'] = 1;
                if ($permission['can_delete_datarecord'])
                    $datatype_permissions[$dt_id]['dr_delete'] = 1;
//                if ($permission['can_design_datatype'])
//                    $datatype_permissions[$dt_id]['dt_design'] = 1;
                if ($permission['is_datatype_admin'])
                    $datatype_permissions[$dt_id]['dt_admin'] = 1;
            }

            // Build the permissions list for datafields
            foreach ($group['groupDatafieldPermissions'] as $num => $permission) {
                $dt_id = $permission['dataField']['dataType']['id'];
                if ( !isset($datafield_permissions[$dt_id]) )
                    $datafield_permissions[$dt_id] = array();

                $df_id = $permission['dataField']['id'];
                $datafield_permissions[$dt_id][$df_id] = array();

                if ($permission['can_view_datafield'])
                    $datafield_permissions[$dt_id][$df_id]['view'] = 1;
                if ($permission['can_edit_datafield'])
                    $datafield_permissions[$dt_id][$df_id]['edit'] = 1;
            }
        }

        // ----------------------------------------
        // Return the final array
        return array(
            'datarecord_restriction' => $datarecord_restriction,
            'datatypes' => $datatype_permissions,
            'datafields' => $datafield_permissions,
        );
    }


    /**
     * Given a group's permission arrays, filter the provided datarecord/datatype arrays so twig doesn't render anything they're not supposed to see.
     *
     * @param array &$datatype_array    @see self::getDatatypeArray()
     * @param array &$datarecord_array  @see self::getDatarecordArray()
     * @param array $permissions_array  @see self::getUserPermissionsArray()
     */
    protected function filterByGroupPermissions(&$datatype_array, &$datarecord_array, $permissions_array)
    {
$debug = true;
$debug = false;

if ($debug)
    print '----- permissions filter -----'."\n";

        // Save relevant permissions...
        $datatype_permissions = array();
        if ( isset($permissions_array['datatypes']) )
            $datatype_permissions = $permissions_array['datatypes'];
        $datafield_permissions = array();
        if ( isset($permissions_array['datafields']) )
            $datafield_permissions = $permissions_array['datafields'];

        $can_view_datatype = array();
        $can_view_datarecord = array();
        $datafields_to_remove = array();
        foreach ($datatype_array as $dt_id => $dt) {
            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dt_view' ]) )
                $can_view_datatype[$dt_id] = true;
            else
                $can_view_datatype[$dt_id] = false;

            if ( isset($datatype_permissions[ $dt_id ]) && isset($datatype_permissions[ $dt_id ][ 'dr_view' ]) )
                $can_view_datarecord[$dt_id] = true;
            else
                $can_view_datarecord[$dt_id] = false;
        }


        // For each datatype in the provided array...
        foreach ($datatype_array as $dt_id => $dt) {

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datatype[$dt_id]) )
                $can_view_datatype[$dt_id] = false;

            // If datatype is non-public and user does not have the 'can_view_datatype' permission, then remove the datatype from the array
            if ( $dt['dataTypeMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datatype[$dt_id] ) {
                unset( $datatype_array[$dt_id] );
if ($debug)
    print 'removed non-public datatype '.$dt_id."\n";

                // Also remove all datarecords of that datatype
                foreach ($datarecord_array as $dr_id => $dr) {
                    if ($dt_id == $dr['dataType']['id'])
                        unset( $datarecord_array[$dr_id] );
if ($debug)
    print ' -- removed datarecord '.$dr_id."\n";
                }

                // No sense checking anything else for this datatype, skip to the next one
                continue;
            }

            // Otherwise, the user is allowed to see this datatype...
            foreach ($dt['themes'] as $theme_id => $theme) {
                foreach ($theme['themeElements'] as $te_num => $te) {

                    // For each datafield in this theme element...
                    if ( isset($te['themeDataFields']) ) {
                        foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
                            $df_id = $tdf['dataField']['id'];

                            // If the user doesn't have the 'can_view_datafield' permission for that datafield...
                            if ( $tdf['dataField']['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !(isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view']) ) ) {
                                // ...remove it from the layout
                                unset( $datatype_array[$dt_id]['themes'][$theme_id]['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField'] );  // leave the theme_datafield entry on purpose
                                $datafields_to_remove[$df_id] = 1;
if ($debug)
    print 'removed datafield '.$df_id.' from theme_element '.$te['id'].' datatype '.$dt_id.' theme '.$theme_id.' ('.$theme['themeType'].')'."\n";
                            }
                        }
                    }
                }
            }
        }

        // Also need to go through the datarecord array and remove both datarecords and datafields that the user isn't allowed to see
        foreach ($datarecord_array as $dr_id => $dr) {
            // Save datatype id of this datarecord
            $dt_id = $dr['dataType']['id'];

            // If there was no datatype permission entry for this datatype, have it default to false
            if ( !isset($can_view_datarecord[$dt_id]) )
                $can_view_datarecord[$dt_id] = false;

            // If the datarecord is non-public and user doesn't have the 'can_view_datarecord' permission, then remove the datarecord from the array
            if ( $dr['dataRecordMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                unset( $datarecord_array[$dr_id] );
if ($debug)
    print 'removed non-public datarecord '.$dr_id."\n";

                // No sense checking anything else for this datarecord, skip to the next one
                continue;
            }

            // The user is allowed to view this datarecord...
            foreach ($dr['dataRecordFields'] as $df_id => $drf) {

                // Remove the datafield if needed
                if ( isset($datafields_to_remove[$df_id]) ) {
                    unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id] );
if ($debug)
    print 'removed datafield '.$df_id.' from datarecord '.$dr_id."\n";

                    // No sense checking file/image public status, skip to the next datafield
                    continue;
                }

                // ...remove the files the user isn't allowed to see
                foreach ($drf['file'] as $file_num => $file) {
                    if ( $file['fileMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['file'][$file_num] );
if ($debug)
    print 'removed non-public file '.$file['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }

                // ...remove the images the user isn't allowed to see
                foreach ($drf['image'] as $image_num => $image) {
                    if ( $image['parent']['imageMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00' && !$can_view_datarecord[$dt_id] ) {
                        unset( $datarecord_array[$dr_id]['dataRecordFields'][$df_id]['image'][$image_num] );
if ($debug)
    print 'removed non-public image '.$image['parent']['id'].' from datarecord '.$dr_id.' datatype '.$dt_id."\n";
                    }
                }
            }
        }
    }


    /**
     * Utility function so other controllers can return 403 errors easily.
     *
     * @param string $type
     *
     * @return Response
     */
    protected function permissionDeniedError($type = '')
    {
        $str = '';
        if ($type !== '')
            $str = "<h2>Permission Denied - You can't ".$type." this DataType!</h2>";
        else
            $str = "<h2>Permission Denied</h2>";

        $return = array();
        $return['r'] = 403;
        $return['t'] = 'html';
        $return['d'] = array(
            'html' => $str
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        $response->setStatusCode(403);
        return $response;
    }


    /**
     * Utility function so other controllers can notify of deleted entities easily.
     *
     * @param string $entity
     *
     * @return Response
     */
    protected function deletedEntityError($entity = '')
    {
        $str = '';
        if ($entity !== '')
            $str = "<h2>This ".$entity." has been deleted!</h2>";
//        else
//            $str = "<h2>Permission Denied</h2>";

        $return = array();
        $return['r'] = 1;   // TODO - switch to 404?
        $return['t'] = 'html';
        $return['d'] = array(
            'html' => $str
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Temporary? function to mark datarecord as updated and delete cached version
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataRecord $datarecord
     * @param User $user
     */
    protected function tmp_updateDatarecordCache($em, $datarecord, $user)
    {
        // Mark datarecord as updated
        $datarecord->setUpdatedBy($user);
        $datarecord->setUpdated(new \DateTime());   // guarantee that the datarecord gets a new updated timestamp...apparently won't happen if the same user makes changes repeatedly
        $em->persist($datarecord);
        $em->flush();

        // Locate and clear the cache entry for this datarecord
        $grandparent_datarecord_id = $datarecord->getGrandparent()->getId();
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $redis->del($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id);
        $redis->del($redis_prefix.'.datarecord_table_data_'.$grandparent_datarecord_id);
    }


    /**
     * Temporary? function to mark datatype as updated and delete cached version
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param DataType $datatype
     * @param User $user
     */
    protected function tmp_updateDatatypeCache($em, $datatype, $user)
    {
        // Mark datatype as updated
        $datatype->setUpdatedBy($user);
        $datatype->setUpdated(new \DateTime());   // guarantee that the datatype gets a new updated timestamp...apparently won't happen if the same user makes changes repeatedly
        $em->persist($datatype);
        $em->flush();

        // Locate and clear the cache entry for this datatype
//        $datatree_array = self::getDatatreeArray($em);
//        $grandparent_datatype_id = self::getGrandparentDatatypeId($datatree_array, $datatype->getId());
        $datatype_id = $datatype->getId();

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $redis->del($redis_prefix.'.cached_datatype_'.$datatype_id);
    }


    /**
     * Temporary? function to mark theme as updated and delete cached version of datatype
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Theme $theme
     * @param User $user
     * @param boolean $update_datatype
     */
    protected function tmp_updateThemeCache($em, $theme, $user, $update_datatype = false)
    {
        // Mark theme as updated
        $theme->setUpdatedBy($user);
        $theme->setUpdated(new \DateTime());   // guarantee that the theme gets a new updated timestamp...apparently won't happen if the same user makes changes repeatedly
        $em->persist($theme);

        if ($update_datatype) {
            // Also mark datatype as updated
            $datatype = $theme->getDataType();
            $datatype->setUpdated(new \DateTime());   // guarantee that the datatype gets a new updated timestamp...apparently won't happen if the same user makes changes repeatedly
            $datatype->setUpdatedBy($user);
            $em->persist($datatype);
        }

        $em->flush();

        // Locate and clear the cache entry for this datatype
//        $datatree_array = self::getDatatreeArray($em);
//        $grandparent_datatype_id = self::getGrandparentDatatypeId($datatree_array, $theme->getDataType()->getId());
        $datatype_id = $theme->getDataType()->getId();

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $redis->del($redis_prefix.'.cached_datatype_'.$datatype_id);
    }


    /**
     * Sorts DataRecords of a given DataType by the value contained in the DataField marked as the DataRecord's sorting field, and returns a comma-separated string of DataRecord ids in that order.
     *
     * @param DataType $datatype The DataType that needs to have its DataRecords sorted
     * @param string $subset_str The subset of datarecord ids to return as a string
     *
     * @return string
     */
    public function getSortedDatarecords($datatype, $subset_str = '')
    {
        // Get Entity Manager and setup objects
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Attempt to grab the list of datarecords for this datatype from the cache
        $datarecords = array();
        $datarecord_str = self::getRedisData(($redis->get($redis_prefix.'.data_type_'.$datatype->getId().'_record_order')));
//print 'loaded record_order: '.$datarecord_str."\n";

        // No caching in dev environment
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;


        if ( !$bypass_cache && $datarecord_str != false && trim($datarecord_str) !== '' ) {
            // List exists in cache, load datarecords
            $datarecord_str = explode(',', trim($datarecord_str));
//print '$datarecord_str: <pre>'.print_r($datarecord_str, true).'</pre>';

            foreach ($datarecord_str as $dr_id)
                $datarecords[$dr_id] = 1;
        }
        else {
            // Create a query to return the ids of all datarecords belonging to this datatype
            $query = $em->createQuery(
               'SELECT dr.id
                FROM ODRAdminBundle:DataRecord AS dr
                WHERE dr.dataType = :datatype AND dr.provisioned = false
                AND dr.deletedAt IS NULL
                ORDER BY dr.id'
            )->setParameters( array('datatype' => $datatype) );
            $results = $query->getArrayResult();

            // Flatten the array
            $datarecords = array();
            foreach ($results as $id => $data)
                $datarecords[ $data['id'] ] = '';
//print 'all datarecords: <pre>'.print_r($datarecords, true).'</pre>';


            // Need to get the DataField used to sort the DataType
            $sortfield = $datatype->getSortField();
            if ($sortfield == null) {
                // ...no sort order defined, use database id order
                foreach ($datarecords as $dr_id => $value)
                    $datarecords[$dr_id] = $dr_id;
            }
            else {
                // Create a query to return a collection of datarecord ids, sorted by the sortfield of the datatype
                $field_typeclass = $sortfield->getFieldType()->getTypeClass();
                $query = $em->createQuery(
                    'SELECT dr.id AS dr_id, e.value AS sort_value
                     FROM ODRAdminBundle:DataRecord AS dr
                     JOIN ODRAdminBundle:'.$field_typeclass.' AS e WITH e.dataRecord = dr
                     WHERE dr.dataType = :datatype AND dr.provisioned = false AND e.dataField = :datafield
                     AND dr.deletedAt IS NULL AND e.deletedAt IS NULL
                     ORDER BY e.value'
                )->setParameters( array('datatype' => $datatype, 'datafield' => $sortfield) );
                $results = $query->getArrayResult();
//print 'sort field results: <pre>'.print_r($results, true).'</pre>';

                // Flatten the array
                foreach ($results as $num => $data) {
                    $dr_id = $data['dr_id'];
                    $sort_value = $data['sort_value'];

                    if ($field_typeclass == "DatetimeValue") {
                        $sort_value = $sort_value->format('Y-m-d H:i:s');
                        if ($sort_value == '9999-12-31 00:00:00')
                            $sort_value = '';
                    }

                    $datarecords[ $dr_id ] = $sort_value;
                }

                asort($datarecords);
//print 'sorted datarecords: <pre>'.print_r($datarecords, true).'</pre>';
            }

            // Turn the sorted datarecords into a string to store in memcached
            $str = '';
            foreach ($datarecords as $id => $key)
                $str .= $id.',';
            $datarecord_str = substr($str, 0, strlen($str)-1);
//print 'saving record_order: '.$datarecord_str."\n";

            $redis->set($redis_prefix.'.data_type_'.$datatype->getId().'_record_order', gzcompress(serialize($datarecord_str)));
        }

        // TODO - leave this as comma-separated list, or return an array instead?
        $sorted_datarecords = '';
        if ($subset_str !== '') {
            // The user only wants the sort order for a subset of the DataType's DataRecords...
            $subset = explode(',', $subset_str);

            foreach ($datarecords as $id => $key) {
                if ( in_array($id, $subset) )
                    $sorted_datarecords .= $id.',';
            }
        }
        else {
            // Just flatten the array...
            foreach ($datarecords as $id => $key)
                $sorted_datarecords .= $id.',';
        }

        $sorted_datarecords = substr($sorted_datarecords, 0, strlen($sorted_datarecords)-1);
//print 'sorted_datarecords: '.$sorted_datarecords."\n";

        return $sorted_datarecords;
    }


    /**
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
            return self::deletedEntityError('TrackedJob');

        /** @var TrackedError[] $tracked_errors */
        $tracked_errors = $em->getRepository('ODRAdminBundle:TrackedError')->findBy( array('trackedJob' => $tracked_job_id) );
        foreach ($tracked_errors as $error)
            $job_errors[ $error->getId() ] = array('error_level' => $error->getErrorLevel(), 'error_body' => json_decode( $error->getErrorBody(), true ));

        return $job_errors;
    }


    /**
     * Deletes all TrackedError entities associated with a specified TrackedJob
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $tracked_job_id
     *
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
     * Creates and persists a new DataRecord entity.
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

        $em->persist($datarecord);
        $em->flush();
        $em->refresh($datarecord);

        $datarecord_meta = new DataRecordMeta();
        $datarecord_meta->setDataRecord($datarecord);
        $datarecord_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // default to not public

        $datarecord_meta->setCreatedBy($user);
        $datarecord_meta->setUpdatedBy($user);

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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_datarecord_meta = new DataRecordMeta();
            $new_datarecord_meta->setDataRecord($datarecord);

            $new_datarecord_meta->setPublicDate( $old_meta_entry->getPublicDate() );

            $new_datarecord_meta->setCreatedBy($user);
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

        // Return the new entry
        return $new_datarecord_meta;
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_datatree_meta = new DataTreeMeta();
            $new_datatree_meta->setDataTree($datatree);

            $new_datatree_meta->setMultipleAllowed( $old_meta_entry->getMultipleAllowed() );
            $new_datatree_meta->setIsLink( $old_meta_entry->getIsLink() );

            $new_datatree_meta->setCreatedBy($user);
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

        // Refresh the cache entries for the ancestor datarecord
        self::tmp_updateDatarecordCache($em, $ancestor_datarecord, $user);

        return $linked_datatree;
    }


    /**
     * Creates a new File/Image entity from the given file at the given filepath, and persists all required information to the database.
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
        $path_prefix = dirname(__FILE__).'/../../../../web/';
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
            $my_obj->setLocalFileName($local_filename);

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
            // Generate local filename
            /** @var File $my_obj */
            //$file_id = $my_obj->getId();

            // Move file to correct spot
            //$filename = 'File_'.$file_id.'.'.$my_obj->getExt();
            //rename($filepath.'/'.$original_filename, $destination_path.'/'.$filename);

            //$local_filename = $my_obj->getUploadDir().'/'.$filename;
            $local_filename = realpath( $path_prefix.$filepath.'/'.$original_filename );
            //dirname(__FILE__).'/../../../../web/'.$my_obj->getUploadDir().'/chunks/user_'.$user_id.'/completed/'.$original_filename );

            $my_obj->setLocalFileName($filepath.'/'.$original_filename);

            clearstatcache(true, $local_filename);
            $my_obj->setFilesize( filesize($local_filename) );

            // Save changes again before encryption process takes over
            $em->persist($my_obj);
            $em->flush();
            $em->refresh($my_obj);

            // Encrypt the file before it's used
            //self::encryptObject($file_id, 'file');

            // Decrypt the file and store its checksum in the database
            //$file_path = self::decryptObject($file_id, 'file');
            //$original_checksum = md5_file($file_path);
            //$my_obj->setOriginalChecksum($original_checksum);

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
                    "target_filepath" => '',
                    "crypto_type" => 'encrypt',
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
                $default_value = 0;
                break;
            case 'IntegerValue':
                $table_name = 'odr_integer_value';
                $default_value = 0;
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
        if ($storage_entity !== null)
            return $storage_entity;

        // Otherwise, locate/create the datarecordfield entity for this datarecord/datafield pair
        $drf = self::ODR_addDataRecordField($em, $user, $datarecord, $datafield);

        // Determine which value to use for the default value
        $insert_value = null;
        if ($initial_value !== null)
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

        // Decimal values need to run setValue() because there's php logic involved
        if ($typeclass == 'DecimalValue') {
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
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        if (!$changes_made)
            return $entity;


        // If this is an IntegerValue entity, set the value back to an integer or null so it gets saved correctly
        if ($typeclass == 'IntegerValue') {
            if ($properties['value'] == '')
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
     * 'description', 'original_filename', 'external_id', and/or 'public_date' (MUST BE A DATETIME OBJECT).
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_file_meta = new FileMeta();
            $new_file_meta->setFile($file);

            $new_file_meta->setDescription( $old_meta_entry->getDescription() );
            $new_file_meta->setOriginalFileName( $old_meta_entry->getOriginalFileName() );
            $new_file_meta->setExternalId( $old_meta_entry->getExternalId() );
            $new_file_meta->setPublicDate( $old_meta_entry->getPublicDate() );

            $new_file_meta->setCreatedBy($user);
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

        // Return the new entry
        return $new_file_meta;
    }


    /**
     * Modifies a meta entry for a given Image entity by copying the old meta entry to a new meta entry,
     *  updating the property(s) that got changed based on the $properties parameter, then deleting the old entry.
     *
     * The $properties parameter must contain at least one of the following keys...
     * 'caption', 'original_filename', 'external_id', 'public_date' (MUST BE A DATETIME OBJECT), and/or 'display_order.
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_image_meta = new ImageMeta();
            $new_image_meta->setImage($image);

            $new_image_meta->setCaption( $old_meta_entry->getCaption() );
            $new_image_meta->setOriginalFileName( $old_meta_entry->getOriginalFileName() );
            $new_image_meta->setExternalId( $old_meta_entry->getExternalId() );
            $new_image_meta->setPublicDate( $old_meta_entry->getPublicDate() );
            $new_image_meta->setDisplayorder( $old_meta_entry->getDisplayorder() );

            $new_image_meta->setCreatedBy($user);
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
     *
     * @return RadioOptions
     */
    protected function ODR_addRadioOption($em, $user, $datafield, $force_create, $option_name = "Option")
    {
        if ($force_create) {
            // Create a new RadioOption entity
            $radio_option = new RadioOptions();
            $radio_option->setDataField($datafield);
            $radio_option->setOptionName($option_name);     // exists to prevent potential concurrency issues, see below

            $radio_option->setCreatedBy($user);
            $radio_option->setCreated(new \DateTime());

            // Save and reload the RadioOption so the associated meta entry can access it
            $em->persist($radio_option);
            $em->flush($radio_option);
            $em->refresh($radio_option);

            // Create a new RadioOptionMeta entity
            $radio_option_meta = new RadioOptionsMeta();
            $radio_option_meta->setRadioOption($radio_option);
            $radio_option_meta->setOptionName($option_name);
            $radio_option_meta->setXmlOptionName('');
            $radio_option_meta->setDisplayOrder(0);
            $radio_option_meta->setIsDefault(false);

            $radio_option_meta->setCreatedBy($user);
            $radio_option_meta->setCreated( new \DateTime() );

            $em->persist($radio_option_meta);
            $em->flush($radio_option_meta);

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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_radio_option_meta = new RadioOptionsMeta();
            $new_radio_option_meta->setRadioOption($radio_option);

            $new_radio_option_meta->setOptionName( $old_meta_entry->getOptionName() );
            $new_radio_option_meta->setXmlOptionName( $old_meta_entry->getXmlOptionName() );
            $new_radio_option_meta->setDisplayOrder( $old_meta_entry->getDisplayOrder() );
            $new_radio_option_meta->setIsDefault( $old_meta_entry->getIsDefault() );

            $new_radio_option_meta->setCreatedBy($user);
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
            // Create a new entry and copy the previous one's data over
            $remove_old_entry = true;

            /** @var RadioSelection $new_entity */
            $new_entity = new RadioSelection();
            $new_entity->setRadioOption( $entity->getRadioOption() );
            $new_entity->setDataRecordFields( $entity->getDataRecordFields() );

            $new_entity->setSelected( $entity->getSelected() );

            $new_entity->setCreatedBy($user);
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
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),
/*
            'externalIdField' => $old_meta_entry->getExternalIdField()->getId(),
            'nameField' => $old_meta_entry->getNameField()->getId(),
            'sortField' => $old_meta_entry->getSortField()->getId(),
            'backgroundImageField' => $old_meta_entry->getBackgroundImageField()->getId(),
*/
            'searchSlug' => $old_meta_entry->getSearchSlug(),
            'shortName' => $old_meta_entry->getShortName(),
            'longName' => $old_meta_entry->getLongName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_shortName' => $old_meta_entry->getXmlShortName(),

            'useShortResults' => $old_meta_entry->getUseShortResults(),
            'publicDate' => $old_meta_entry->getPublicDate(),
        );

        // These Datafields entries can be null
        if ( $old_meta_entry->getExternalIdField() !== null )
            $existing_values['externalIdField'] = $old_meta_entry->getExternalIdField()->getId();
        if ( $old_meta_entry->getNameField() !== null )
            $existing_values['nameField'] = $old_meta_entry->getNameField()->getId();
        if ( $old_meta_entry->getSortField() !== null )
            $existing_values['sortField'] = $old_meta_entry->getSortField()->getId();
        if ( $old_meta_entry->getBackgroundImageField() !== null )
            $existing_values['backgroundImageField'] = $old_meta_entry->getBackgroundImageField()->getId();


        foreach ($existing_values as $key => $value) {
            if ( isset($properties[$key]) && $properties[$key] != $value )
                $changes_made = true;
        }

        // Need to do additional checking in case the mentioned datafields were null beforehand
        if ( isset($properties['externalIdField']) && !($properties['externalIdField'] == null || $properties['externalIdField'] == -1) && $datatype->getExternalIdField() == null )
            $changes_made = true;
        if ( isset($properties['nameField']) && !($properties['nameField'] == null || $properties['nameField'] == -1) && $datatype->getNameField() == null )
            $changes_made = true;
        if ( isset($properties['sortField']) && !($properties['sortField'] == null || $properties['sortField'] == -1) && $datatype->getSortField() == null )
            $changes_made = true;
        if ( isset($properties['backgroundImageField']) && !($properties['backgroundImageField'] == null || $properties['backgroundImageField'] == -1) && $datatype->getBackgroundImageField() == null )
            $changes_made = true;

        if (!$changes_made)
            return $old_meta_entry;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_datatype_meta = null;
        if ( self::createNewMetaEntry($user, $old_meta_entry) ) {
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_datatype_meta = new DataTypeMeta();
            $new_datatype_meta->setDataType($datatype);

            $new_datatype_meta->setRenderPlugin( $old_meta_entry->getRenderPlugin() );
            $new_datatype_meta->setExternalIdField( $old_meta_entry->getExternalIdField() );
            $new_datatype_meta->setNameField( $old_meta_entry->getNameField() );
            $new_datatype_meta->setSortField( $old_meta_entry->getSortField() );
            $new_datatype_meta->setBackgroundImageField( $old_meta_entry->getBackgroundImageField() );

            $new_datatype_meta->setSearchSlug( $old_meta_entry->getSearchSlug() );
            $new_datatype_meta->setShortName( $old_meta_entry->getShortName() );
            $new_datatype_meta->setLongName( $old_meta_entry->getLongName() );
            $new_datatype_meta->setDescription( $old_meta_entry->getDescription() );
            $new_datatype_meta->setXmlShortName( $old_meta_entry->getXmlShortName() );

            $new_datatype_meta->setUseShortResults( $old_meta_entry->getUseShortResults() );
            $new_datatype_meta->setPublicDate( $old_meta_entry->getPublicDate() );

            $new_datatype_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_datatype_meta = $old_meta_entry;
        }


        // Set any new properties
        if ( isset($properties['renderPlugin']) )
            $new_datatype_meta->setRenderPlugin( $em->getRepository('ODRAdminBundle:RenderPlugin')->find( $properties['renderPlugin'] ) );

        if ( isset($properties['externalIdField']) ) {
            if ($properties['externalIdField'] == null || $properties['externalIdField'] == -1)
                $new_datatype_meta->setExternalIdField(null);
            else
                $new_datatype_meta->setExternalIdField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['externalIdField']) );
        }
        if ( isset($properties['nameField']) ) {
            if ($properties['nameField'] == null || $properties['nameField'] == -1)
                $new_datatype_meta->setNameField(null);
            else
                $new_datatype_meta->setNameField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['nameField']) );
        }
        if ( isset($properties['sortField']) ) {
            if ($properties['sortField'] == null || $properties['sortField'] == -1)
                $new_datatype_meta->setSortField(null);
            else
                $new_datatype_meta->setSortField( $em->getRepository('ODRAdminBundle:DataFields')->find($properties['sortField']) );
        }
        if ( isset($properties['backgroundImageField']) ) {
            if ($properties['backgroundImageField'] == null || $properties['backgroundImageField'] == -1)
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

        if ( isset($properties['useShortResults']) )
            $new_datatype_meta->setUseShortResults( $properties['useShortResults'] );
        if ( isset($properties['publicDate']) )
            $new_datatype_meta->setPublicDate( $properties['publicDate'] );

        $new_datatype_meta->setUpdatedBy($user);


        // Delete the old entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_datatype_meta);
        $em->flush();

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

        $em->persist($datafield);
        $em->flush();
        $em->refresh($datafield);


        $datafield_meta = new DataFieldsMeta();
        $datafield_meta->setDataField($datafield);
        $datafield_meta->setFieldType($fieldtype);
        $datafield_meta->setRenderPlugin($renderplugin);

        $datafield_meta->setFieldName('New Field');
        $datafield_meta->setDescription('Field description.');
        $datafield_meta->setXmlFieldName('');
        $datafield_meta->setRegexValidator('');
        $datafield_meta->setPhpValidator('');

        $datafield_meta->setMarkdownText('');
        $datafield_meta->setIsUnique(false);
        $datafield_meta->setRequired(false);
        $datafield_meta->setSearchable(0);
        $datafield_meta->setUserOnlySearch(false);
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


        // Add the datafield to all groups for this datatype
        self::ODR_createGroupsForDatafield($em, $user, $datafield);

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
            'fieldType' => $old_meta_entry->getFieldType()->getId(),
            'renderPlugin' => $old_meta_entry->getRenderPlugin()->getId(),

            'fieldName' => $old_meta_entry->getFieldName(),
            'description' => $old_meta_entry->getDescription(),
            'xml_fieldName' => $old_meta_entry->getXmlFieldName(),
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
            'user_only_search' => $old_meta_entry->getUserOnlySearch(),
            'publicDate' => $old_meta_entry->getPublicDate(),
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_datafield_meta = new DataFieldsMeta();
            $new_datafield_meta->setDataField($datafield);
            $new_datafield_meta->setFieldType( $old_meta_entry->getFieldType() );
            $new_datafield_meta->setRenderPlugin( $old_meta_entry->getRenderPlugin() );

            $new_datafield_meta->setFieldName( $old_meta_entry->getFieldName() );
            $new_datafield_meta->setDescription( $old_meta_entry->getDescription() );
            $new_datafield_meta->setXmlFieldName( $old_meta_entry->getXmlFieldName() );
            $new_datafield_meta->setMarkdownText( $old_meta_entry->getMarkdownText() );
            $new_datafield_meta->setRegexValidator( $old_meta_entry->getRegexValidator() );
            $new_datafield_meta->setPhpValidator( $old_meta_entry->getPhpValidator() );
            $new_datafield_meta->setRequired( $old_meta_entry->getRequired() );
            $new_datafield_meta->setIsUnique( $old_meta_entry->getIsUnique() );
            $new_datafield_meta->setAllowMultipleUploads( $old_meta_entry->getAllowMultipleUploads() );
            $new_datafield_meta->setShortenFilename( $old_meta_entry->getShortenFilename() );
            $new_datafield_meta->setChildrenPerRow( $old_meta_entry->getChildrenPerRow() );
            $new_datafield_meta->setRadioOptionNameSort( $old_meta_entry->getRadioOptionNameSort() );
            $new_datafield_meta->setRadioOptionDisplayUnselected( $old_meta_entry->getRadioOptionDisplayUnselected() );
            $new_datafield_meta->setSearchable( $old_meta_entry->getSearchable() );
            $new_datafield_meta->setUserOnlySearch( $old_meta_entry->getUserOnlySearch() );
            $new_datafield_meta->setPublicDate( $old_meta_entry->getPublicDate() );

            $new_datafield_meta->setCreatedBy($user);
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
        if ( isset($properties['user_only_search']) )
            $new_datafield_meta->setUserOnlySearch( $properties['user_only_search'] );
        if ( isset($properties['publicDate']) )
            $new_datafield_meta->setPublicDate( $properties['publicDate'] );

        $new_datafield_meta->setUpdatedBy($user);


        // Delete the old meta entry if necessary
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        //Save the new meta entry
        $em->persist($new_datafield_meta);
        $em->flush();

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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_theme_meta = new ThemeMeta();
            $new_theme_meta->setTheme($theme);

            $new_theme_meta->setTemplateName( $old_meta_entry->getTemplateName() );
            $new_theme_meta->setTemplateDescription( $old_meta_entry->getTemplateDescription() );
            $new_theme_meta->setIsDefault( $old_meta_entry->getIsDefault() );

            $new_theme_meta->setCreatedBy($user);
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

        $new_theme_meta->setUpdatedBy($user);


        // Delete the old meta entry if needed
        if ($remove_old_entry)
            $em->remove($old_meta_entry);

        // Save the new meta entry
        $em->persist($new_theme_meta);
        $em->flush();

        // Return the new entry
        return $new_theme_meta;
    }


    /**
     * Creates and persists a new ThemeElement entity.
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
        $theme_element_meta->setCssWidthMed('1-1');
        $theme_element_meta->setCssWidthXL('1-1');
        $theme_element_meta->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

        $theme_element_meta->setCreatedBy($user);
        $theme_element_meta->setUpdatedBy($user);

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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $theme_element_meta = new ThemeElementMeta();
            $theme_element_meta->setThemeElement($theme_element);

            $theme_element_meta->setDisplayOrder( $old_meta_entry->getDisplayOrder() );
            $theme_element_meta->setCssWidthMed( $old_meta_entry->getCssWidthMed() );
            $theme_element_meta->setCssWidthXL( $old_meta_entry->getCssWidthXL() );

            $theme_element_meta->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $theme_element_meta = $old_meta_entry;
        }


        // Set any changed properties
        if ( isset($properties['displayOrder']) )
            $theme_element_meta->setDisplayOrder( $properties['displayOrder'] );
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

        // Return the meta entry
        return $theme_element_meta;
    }


    /**
     * Creates and persists a new ThemeDataField entity.
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

        $theme_datafield->setCreatedBy($user);
        $theme_datafield->setUpdatedBy($user);

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
            'themeElement' => $theme_datafield->getThemeElement()->getId(),

            'displayOrder' => $theme_datafield->getDisplayOrder(),
            'cssWidthMed' => $theme_datafield->getCssWidthMed(),
            'cssWidthXL' => $theme_datafield->getCssWidthXL(),
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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_theme_datafield = new ThemeDataField();
            $new_theme_datafield->setDataField( $theme_datafield->getDataField() );
            $new_theme_datafield->setThemeElement( $theme_datafield->getThemeElement() );

            $new_theme_datafield->setDisplayOrder( $theme_datafield->getDisplayOrder() );
            $new_theme_datafield->setCssWidthMed( $theme_datafield->getCssWidthMed() );
            $new_theme_datafield->setCssWidthXL( $theme_datafield->getCssWidthXL() );

            $new_theme_datafield->setCreatedBy($user);
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
     * Creates and persists a new ThemeDataType entity.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param User $user                  The user requesting the creation of this entity
     * @param DataType $datatype          The datatype this entry is for
     * @param ThemeElement $theme_element The theme_element this entry is attached to
     *
     * @return ThemeDataType
     */
    protected function ODR_addThemeDatatype($em, $user, $datatype, $theme_element)
    {
        // Create theme entry
        $theme_datatype = new ThemeDataType();
        $theme_datatype->setDataType($datatype);
        $theme_datatype->setThemeElement($theme_element);

        $theme_datatype->setDisplayType(0);     // 0 is accordion, 1 is tabbed, 2 is dropdown, 3 is list

        $theme_datatype->setCreatedBy($user);
        $theme_datatype->setUpdatedBy($user);

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
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_theme_datatype = new ThemeDataType();
            $new_theme_datatype->setDataType( $theme_datatype->getDataType() );
            $new_theme_datatype->setThemeElement( $theme_datatype->getThemeElement() );

            $new_theme_datatype->setDisplayType( $theme_datatype->getDisplayType() );

            $new_theme_datatype->setCreatedBy($user);
        }
        else {
            // Update the existing meta entry
            $new_theme_datatype = $theme_datatype;
        }


        // Set any new properties
        if (isset($properties['display_type']))
            $new_theme_datatype->setDisplayType( $properties['display_type'] );

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
     * Creates, persists, and flushes a new RenderPluginInstance entity
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
        if ( $render_plugin->getPluginType() == 1 && $datatype == null )
            throw new \Exception('Unable to create an instance of the RenderPlugin "'.$render_plugin->getPluginName().'" for a null Datatype');
        else if ( $render_plugin->getPluginType() == 3 && $datafield == null )
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
     * Creates and persists a new RenderPluginMap entity
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
     * @return RenderPluginMap
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
            return $render_plugin_map;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpm = null;
        if ( self::createNewMetaEntry($user, $render_plugin_map) ) {
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_rpm = new RenderPluginMap();
            $new_rpm->setRenderPluginInstance( $render_plugin_map->getRenderPluginInstance() );
            $new_rpm->setRenderPluginFields( $render_plugin_map->getRenderPluginFields() );

            $new_rpm->setDataType( $render_plugin_map->getDataType() );
            $new_rpm->setDataField( $render_plugin_map->getDataField() );

            $new_rpm->setCreatedBy($user);
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
        return $new_rpm;
    }


    /**
     * Creates and persists a new RenderPluginOption entity
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
     * @return RenderPluginOptions
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
            return $render_plugin_option;


        // Determine whether to create a new meta entry or modify the previous one
        $remove_old_entry = false;
        $new_rpo = null;
        if ( self::createNewMetaEntry($user, $render_plugin_option) ) {
            // Create a new meta entry and copy the old entry's data over
            $remove_old_entry = true;

            $new_rpo = new RenderPluginOptions();
            $new_rpo->setRenderPluginInstance( $render_plugin_option->getRenderPluginInstance() );
            $new_rpo->setOptionName( $render_plugin_option->getOptionName() );
            $new_rpo->setOptionValue( $render_plugin_option->getOptionValue() );

            $new_rpo->setCreatedBy($user);
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
        return $new_rpo;
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
                if ($size->getSizeConstraint() == "width" ||
                    $size->getSizeConstraint() == "height" ||
                    $size->getSizeConstraint() == "both") {
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
            JOIN ODRAdminBundle:'.$parent_typeclass.' AS e_2
            WHERE e_1.dataField = :child_datafield AND e_1.value = :child_value AND e_2.dataField = :parent_datafield AND e_2.value = :parent_value
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
     * Utility function to return the column definition for use by the datatables plugin
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param Theme $theme                     The 'table' theme that stores the order of datafields for its datatype
     * @param array $datafield_permissions     The datafield permissions array of the user requesting this page
     *
     * @return array
     */
    public function getDatatablesColumnNames($em, $theme, $datafield_permissions)
    {
        // First and second columns are always datarecord id and sort value, respectively
        $column_names  = '{"title":"datarecord_id","visible":false,"searchable":false},';
        $column_names .= '{"title":"datarecord_sortvalue","visible":false,"searchable":false},';
        $num_columns = 2;

        // Do a query to locate the names of all datafields that can be in the table
        $query = $em->createQuery(
           'SELECT df.id AS df_id, dfm.fieldName AS field_name, dfm.publicDate AS public_date
            FROM ODRAdminBundle:ThemeElement AS te
            JOIN ODRAdminBundle:ThemeDataField AS tdf WITH tdf.themeElement = te
            JOIN ODRAdminBundle:DataFields AS df WITH tdf.dataField = df
            JOIN ODRAdminBundle:DataFieldsMeta AS dfm WITH dfm.dataField = df
            WHERE te.theme = :theme_id
            AND te.deletedAt IS NULL AND tdf.deletedAt IS NULL AND df.deletedAt IS NULL AND dfm.deletedAt IS NULL
            ORDER BY tdf.displayOrder'
        )->setParameters( array('theme_id' => $theme->getId()) );
        $results = $query->getArrayResult();

        foreach ($results as $num => $data) {
            $df_id = $data['df_id'];
            $public_date = $data['public_date'];

            $datafield_is_public = true;
            if ($public_date->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                $datafield_is_public = false;

            if ($datafield_is_public || (isset($datafield_permissions[$df_id]) && isset($datafield_permissions[$df_id]['view'])) ) {
                $fieldname = $data['field_name'];
                $fieldname = str_replace('"', "\\\"", $fieldname);  // escape double-quotes in datafield name

                $column_names .= '{"title":"'.$fieldname.'"},';
                $num_columns++;
            }
        }

        return array('column_names' => $column_names, 'num_columns' => $num_columns);
    }


    /**
     * Rebuilds all cached versions of table themes for the given datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datarecord_id
     * @param Request $request
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function Text_GetDisplayData($em, $datarecord_id, Request $request)
    {
        // ----------------------------------------
        // Grab necessary objects
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
        $router = $this->container->get('router');


        // ----------------------------------------
        // Always bypass cache in dev mode
        $bypass_cache = false;
        if ($this->container->getParameter('kernel.environment') === 'dev')
            $bypass_cache = true;

        // Grab the cached version of the requested datarecord
        $datarecord_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$datarecord_id)));
        if ($bypass_cache || $datarecord_data == false)
            $datarecord_data = self::getDatarecordData($em, $datarecord_id, $bypass_cache);

        $datatype_id = $datarecord_data[$datarecord_id]['dataType']['id'];

        // Grab the cached version of the requested datatype
        // print $redis_prefix.'.cached_datatype_'.$datatype_id;
        $datatype_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
        if ($bypass_cache || $datatype_data == false) {
            $datatype_data = self::getDatatypeData($em, self::getDatatreeArray($em, $bypass_cache), $datatype_id, $bypass_cache);
        }


//print '<pre>'.print_r($datatype_data, true).'</pre>'; exit();
//print '<pre>'.print_r($datarecord_data, true).'</pre>'; exit();


        // ----------------------------------------
        // Need to build an array to store the data
        $data = array(
            'default_sort_value' => $datarecord_data[$datarecord_id]['sortField_value'],
            'publicDate' => $datarecord_data[$datarecord_id]['dataRecordMeta']['publicDate']->format('Y-m-d H:i:s'),
        );

        foreach ($datatype_data[$datatype_id]['themes'] as $theme_id => $theme) {
            if ($theme['themeType'] == 'table') {
                $data[$theme_id] = array();

                $theme_element = $theme['themeElements'][0];    // only ever one theme element for a table theme
                if ( !isset($theme_element['themeDataFields']) )
                    continue;

                foreach ($theme_element['themeDataFields'] as $display_order => $tdf) {

                    $df = $tdf['dataField'];
                    $dr = $datarecord_data[$datarecord_id];
                    $render_plugin = $df['dataFieldMeta']['renderPlugin'];

                    $df_id = $tdf['dataField']['id'];
                    $df_value = '';

                    $df_is_public = 1;
                    if ($df['dataFieldMeta']['publicDate']->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
                        $df_is_public = 0;

                    if ($render_plugin['id'] !== 1) {
                        // Run the render plugin for this datafield
                        try {
                            $plugin = $this->get($render_plugin['pluginClassName']);
                            $df_value = $plugin->execute($tdf['dataField'], $datarecord_data[$datarecord_id], $render_plugin, 'table');
                        }
                        catch (\Exception $e) {
                            throw new \Exception( 'Error executing RenderPlugin "'.$render_plugin['pluginName'].'" on Datafield '.$df['id'].' Datarecord '.$dr['id'].': '.$e->getMessage() );
                        }
                    }
                    else if ( !isset($dr['dataRecordFields']) || !isset($dr['dataRecordFields'][$df_id]) ) {
                        // A drf entry hasn't been created for this storage entity...just use the empty string
                        $df_value = '';
                    }
                    else {
                        // Locate this datafield's value from the datarecord array
                        $df_typeclass = $df['dataFieldMeta']['fieldType']['typeClass'];
                        $drf = $dr['dataRecordFields'][$df_id];

                        switch ($df_typeclass) {
                            case 'Boolean':
                                if ( $drf['boolean'][0]['value'] == 1 )
                                    $df_value = 'YES';
                                break;
                            case 'IntegerValue':
                                $df_value = $drf['integerValue'][0]['value'];
                                break;
                            case 'DecimalValue':
                                $df_value = $drf['decimalValue'][0]['value'];
                                break;
                            case 'LongText':
                                $df_value = $drf['longText'][0]['value'];
                                break;
                            case 'LongVarchar':
                                $df_value = $drf['longVarchar'][0]['value'];
                                break;
                            case 'MediumVarchar':
                                $df_value = $drf['mediumVarchar'][0]['value'];
                                break;
                            case 'ShortVarchar':
                                $df_value = $drf['shortVarchar'][0]['value'];
                                break;
                            case 'DatetimeValue':
                                $df_value = $drf['datetimeValue'][0]['value']->format('Y-m-d');
                                if ($df_value == '9999-12-31')
                                    $df_value = '';
                                break;

                            case 'File':
                                if ( isset($drf['file'][0]) ) {
                                    $file = $drf['file'][0];    // should only ever be one file in here anyways

                                    $url = $router->generate( 'odr_file_download', array('file_id' => $file['id']) );
                                    $df_value = '<a href='.$url.'>'.$file['fileMeta']['originalFileName'].'</a>';
                                }
                                break;

                            case 'Radio':
                                foreach ($drf['radioSelection'] as $ro_id => $rs) {
                                    if ( $rs['selected'] == 1 ) {
                                        $df_value = $rs['radioOption']['optionName'];
                                        break;
                                    }
                                }
                                break;
                        }
                    }

                    $data[$theme_id][$display_order] = array('id' => $df_id, 'value' => $df_value, 'is_public' => $df_is_public);
                }
            }
        }

        // Store the resulting array back in memcached before returning it
        $redis->set($redis_prefix.'.datarecord_table_data_'.$datarecord_id, gzcompress(serialize($data)));
        return $data;
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
                              $use_linux_commands = false ) {

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
               'INSERT INTO odr_image_sizes (data_fields_id, field_type_id, size_constraint, min_width, width, max_width, min_height, height, max_height, original, created, createdBy, updated, updatedBy)
                SELECT * FROM (
                    SELECT :df_id AS data_fields_id, :ft_id AS field_type_id, :size_constraint AS size_constraint,
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
                'ft_id' => $datafield->getFieldType()->getId(),
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
               'INSERT INTO odr_image_sizes (data_fields_id, field_type_id, size_constraint, min_width, width, max_width, min_height, height, max_height, original, imagetype, created, createdBy, updated, updatedBy)
                SELECT * FROM (
                    SELECT :df_id AS data_fields_id, :ft_id AS field_type_id, :size_constraint AS size_constraint,
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
                'ft_id' => $datafield->getFieldType()->getId(),
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
        $errors = $form->getErrors();

        $error_str = '';
        while( $errors->valid() ) {
            $error_str .= 'ERROR: '.$errors->current()->getMessage()."\n";
            $errors->next();
        }

        return $error_str;
    }


    /**
     * Gets all layout information required for the given datatype in array format
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $datatree_array
     * @param integer $datatype_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    protected function getDatatypeData($em, $datatree_array, $datatype_id, $force_rebuild = false)
    {
$debug = true;
$debug = false;
/*
$timing = true;
$timing = false;

$t0 = $t1 = null;
if ($timing)
    $t0 = microtime(true);
*/

        // If datatype data exists in memcached and user isn't demanding a fresh version, return that
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        if (!$force_rebuild) {
            $cached_datatype_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
            if ( $cached_datatype_data != false && count($cached_datatype_data) > 0 )
                return $cached_datatype_data;
        }


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $em->createQuery(
           'SELECT
                t, tm,
                dt, dtm, dt_rp, dt_rpi, dt_rpo, dt_rpm, dt_rpf, dt_rpm_df,
                te, tem,
                tdf, df, ro, rom,
                dfm, ft, df_rp, df_rpi, df_rpo, df_rpm,
                tdt, c_dt

            FROM ODRAdminBundle:dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm

            LEFT JOIN dt.themes AS t
            LEFT JOIN t.themeMeta AS tm

            LEFT JOIN dtm.renderPlugin AS dt_rp
            LEFT JOIN dt_rp.renderPluginInstance AS dt_rpi WITH (dt_rpi.dataType = dt)
            LEFT JOIN dt_rpi.renderPluginOptions AS dt_rpo
            LEFT JOIN dt_rpi.renderPluginMap AS dt_rpm
            LEFT JOIN dt_rpm.renderPluginFields AS dt_rpf
            LEFT JOIN dt_rpm.dataField AS dt_rpm_df

            LEFT JOIN t.themeElements AS te
            LEFT JOIN te.themeElementMeta AS tem

            LEFT JOIN te.themeDataFields AS tdf
            LEFT JOIN tdf.dataField AS df
            LEFT JOIN df.radioOptions AS ro
            LEFT JOIN ro.radioOptionMeta AS rom

            LEFT JOIN df.dataFieldMeta AS dfm
            LEFT JOIN dfm.fieldType AS ft

            LEFT JOIN dfm.renderPlugin AS df_rp
            LEFT JOIN df_rp.renderPluginInstance AS df_rpi WITH (df_rpi.dataField = df)
            LEFT JOIN df_rpi.renderPluginOptions AS df_rpo
            LEFT JOIN df_rpi.renderPluginMap AS df_rpm

            LEFT JOIN te.themeDataType AS tdt
            LEFT JOIN tdt.dataType AS c_dt

            WHERE
                dt.id = :datatype_id
                AND t.deletedAt IS NULL AND dt.deletedAt IS NULL AND te.deletedAt IS NULL
            ORDER BY dt.id, t.id, tem.displayOrder, te.id, tdf.displayOrder, df.id, rom.displayOrder, ro.id'
        )->setParameters( array('datatype_id' => $datatype_id) );

        $datatype_data = $query->getArrayResult();
//print '<pre>'.print_r($datatype_data, true).'</pre>';  exit();

/*
if ($timing) {
    $t1 = microtime(true);
    $diff = $t1 - $t0;
    print 'getLayoutData('.$datatype_id.')'."\n".'query execution in: '.$diff."\n";
}
*/

        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceeding query generates an array that needs to be slightly flattened in a few places
        foreach ($datatype_data as $dt_num => $dt) {
if ($debug)
    print 'dt_id: '.$dt['id']."\n";

            // Flatten datatype meta
            $dtm = $dt['dataTypeMeta'][0];
            $datatype_data[$dt_num]['dataTypeMeta'] = $dtm;

            // Flatten theme_meta of each theme, and organize by theme id instead of a random number
            $new_theme_array = array();
            foreach ($dt['themes'] as $t_num => $theme) {
if ($debug)
    print ' -- t_id: '.$theme['id']."\n";

                $theme_id = $theme['id'];

                $tm = $theme['themeMeta'][0];
                $theme['themeMeta'] = $tm;

                // Flatten theme_element_meta of each theme_element
                foreach ($theme['themeElements'] as $te_num => $te) {
if ($debug)
    print '    -- te_id: '.$te['id']."\n";

                    $tem = $te['themeElementMeta'][0];
                    $theme['themeElements'][$te_num]['themeElementMeta'] = $tem;

                    // Flatten datafield_meta of each datafield
                    foreach ($te['themeDataFields'] as $tdf_num => $tdf) {
if ($debug)
    print '       -- tdf_id: '.$tdf['id']."\n";
if ($debug)
    print '          -- df_id: '.$tdf['dataField']['id']."\n";

                        $dfm = $tdf['dataField']['dataFieldMeta'][0];
                        $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['dataFieldMeta'] = $dfm;

                        // Flatten radio options if it exists
                        foreach ($tdf['dataField']['radioOptions'] as $ro_num => $ro) {
if ($debug)
    print '             -- ro_id: '.$ro['id']."\n";

                            $rom = $ro['radioOptionMeta'][0];
                            $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'][$ro_num]['radioOptionMeta'] = $rom;
                        }
                        if ( count($tdf['dataField']['radioOptions']) == 0 )
                            unset( $theme['themeElements'][$te_num]['themeDataFields'][$tdf_num]['dataField']['radioOptions'] );
                    }

                    // Attach the is_link property to each of the theme_datatype entries
                    foreach ($te['themeDataType'] as $tdt_num => $tdt) {
if ($debug)
    print '       -- tdt_id: '.$tdt['id']."\n";
if ($debug)
    print '          -- cdt_id: '.$tdt['dataType']['id']."\n";

                        $child_datatype_id = $tdt['dataType']['id'];

                        if ( isset($datatree_array['linked_from'][$child_datatype_id]) && in_array($datatype_id, $datatree_array['linked_from'][$child_datatype_id]) )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['is_link'] = 0;

                        if ( isset($datatree_array['multiple_allowed'][$child_datatype_id]) && $datatree_array['multiple_allowed'][$child_datatype_id] == $datatype_id )
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 1;
                        else
                            $theme['themeElements'][$te_num]['themeDataType'][$tdt_num]['multiple_allowed'] = 0;
                    }

                    // Easier on twig if these arrays simply don't exist if nothing is in them...
                    if ( count($te['themeDataFields']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataFields'] );
                    if ( count($te['themeDataType']) == 0 )
                        unset( $theme['themeElements'][$te_num]['themeDataType'] );
                }

                $new_theme_array[$theme_id] = $theme;
            }

            unset( $datatype_data[$dt_num]['themes'] );
            $datatype_data[$dt_num]['themes'] = $new_theme_array;
        }

        // Organize by datatype id
        $formatted_datatype_data = array();
        foreach ($datatype_data as $num => $dt_data) {
            $dt_id = $dt_data['id'];

            $formatted_datatype_data[$dt_id] = $dt_data;
        }

//print '<pre>'.print_r($formatted_datatype_data, true).'</pre>'; //exit();

        $redis->set($redis_prefix.'.cached_datatype_'.$datatype_id, gzcompress(serialize($formatted_datatype_data)));
//$cached_datatype_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datatype_'.$datatype_id)));
        return $formatted_datatype_data;
    }


    /**
     * Runs a single database query to get all non-layout data for a given grandparent datarecord.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $grandparent_datarecord_id
     * @param boolean $force_rebuild
     *
     * @return array
     */
    protected function getDatarecordData($em, $grandparent_datarecord_id, $force_rebuild = false)
    {
/*
$debug = true;
$debug = false;
$timing = true;
$timing = false;

$t0 = $t1 = null;
if ($timing)
    $t0 = microtime(true);
*/
        // If datarecord data exists in memcached and user isn't demanding a fresh version, return that
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        if (!$force_rebuild) {
            $cached_datarecord_data = self::getRedisData(($redis->get($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id)));
            if ( $cached_datarecord_data != false && count($cached_datarecord_data) > 0 )
                return $cached_datarecord_data;
        }


        // Otherwise...get all non-layout data for a given grandparent datarecord
        $query = $em->createQuery(
           'SELECT
               dr, drm, dr_cb, dr_ub, p_dr, gp_dr,
               dt, dtm, dt_eif, dt_nf, dt_sf,
               drf, e_f, e_fm, e_f_cb,
               e_i, e_im, e_ip, e_ipm, e_is, e_ip_cb,
               e_b, e_iv, e_dv, e_lt, e_lvc, e_mvc, e_svc, e_dtv, rs, ro,
               e_b_cb, e_iv_cb, e_dv_cb, e_lt_cb, e_lvc_cb, e_mvc_cb, e_svc_cb, e_dtv_cb, rs_cb,
               df,
               cdr, cdr_dt, ldt, ldr, ldr_dt

            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.dataRecordMeta AS drm
            LEFT JOIN dr.createdBy AS dr_cb
            LEFT JOIN dr.updatedBy AS dr_ub
            LEFT JOIN dr.parent AS p_dr
            LEFT JOIN dr.grandparent AS gp_dr

            LEFT JOIN dr.dataType AS dt
            LEFT JOIN dt.dataTypeMeta AS dtm
            LEFT JOIN dtm.externalIdField AS dt_eif
            LEFT JOIN dtm.nameField AS dt_nf
            LEFT JOIN dtm.sortField AS dt_sf

            LEFT JOIN dr.dataRecordFields AS drf
            LEFT JOIN drf.file AS e_f
            LEFT JOIN e_f.fileMeta AS e_fm
            LEFT JOIN e_f.createdBy AS e_f_cb

            LEFT JOIN drf.image AS e_i
            LEFT JOIN e_i.imageMeta AS e_im
            LEFT JOIN e_i.parent AS e_ip
            LEFT JOIN e_ip.imageMeta AS e_ipm
            LEFT JOIN e_i.imageSize AS e_is
            LEFT JOIN e_ip.createdBy AS e_ip_cb

            LEFT JOIN drf.boolean AS e_b
            LEFT JOIN e_b.createdBy AS e_b_cb
            LEFT JOIN drf.integerValue AS e_iv
            LEFT JOIN e_iv.createdBy AS e_iv_cb
            LEFT JOIN drf.decimalValue AS e_dv
            LEFT JOIN e_dv.createdBy AS e_dv_cb
            LEFT JOIN drf.longText AS e_lt
            LEFT JOIN e_lt.createdBy AS e_lt_cb
            LEFT JOIN drf.longVarchar AS e_lvc
            LEFT JOIN e_lvc.createdBy AS e_lvc_cb
            LEFT JOIN drf.mediumVarchar AS e_mvc
            LEFT JOIN e_mvc.createdBy AS e_mvc_cb
            LEFT JOIN drf.shortVarchar AS e_svc
            LEFT JOIN e_svc.createdBy AS e_svc_cb
            LEFT JOIN drf.datetimeValue AS e_dtv
            LEFT JOIN e_dtv.createdBy AS e_dtv_cb
            LEFT JOIN drf.radioSelection AS rs
            LEFT JOIN rs.createdBy AS rs_cb
            LEFT JOIN rs.radioOption AS ro

            LEFT JOIN drf.dataField AS df

            LEFT JOIN dr.children AS cdr
            LEFT JOIN cdr.dataType AS cdr_dt

            LEFT JOIN dr.linkedDatarecords AS ldt
            LEFT JOIN ldt.descendant AS ldr
            LEFT JOIN ldr.dataType AS ldr_dt

            WHERE
                dr.grandparent = :grandparent_id
                AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND df.deletedAt IS NULL
                AND (e_i.id IS NULL OR e_i.original = 0)'
        )->setParameters(array('grandparent_id' => $grandparent_datarecord_id));

//print $query->getSQL();  exit();
        $datarecord_data = $query->getArrayResult();
//print '<pre>'.print_r($datarecord_data, true).'</pre>';  exit();

/*
if ($timing) {
    $t1 = microtime(true);
    $diff = $t1 - $t0;
    print 'getDatarecordData('.$grandparent_datarecord_id.')'."\n".'query execution in: '.$diff."\n";
}
*/
        // The entity -> entity_metadata relationships have to be one -> many from a database perspective, even though there's only supposed to be a single non-deleted entity_metadata object for each entity
        // Therefore, the preceeding query generates an array that needs to be slightly flattened in a few places
        foreach ($datarecord_data as $dr_num => $dr) {
            // Flatten datarecord_meta
            $drm = $dr['dataRecordMeta'][0];
            $datarecord_data[$dr_num]['dataRecordMeta'] = $drm;
            $datarecord_data[$dr_num]['createdBy'] = self::cleanUserData( $dr['createdBy'] );
            $datarecord_data[$dr_num]['updatedBy'] = self::cleanUserData( $dr['updatedBy'] );

            // Store which datafields are used for the datatype's external_id_datafield, name_datafield, and sort_datafield
            $external_id_field = null;
            $name_datafield = null;
            $sort_datafield = null;

            if ( isset($dr['dataType']['dataTypeMeta'][0]['externalIdField']['id']) )
                $external_id_field = $dr['dataType']['dataTypeMeta'][0]['externalIdField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['nameField']['id']) )
                $name_datafield = $dr['dataType']['dataTypeMeta'][0]['nameField']['id'];
            if ( isset($dr['dataType']['dataTypeMeta'][0]['sortField']['id']) )
                $sort_datafield = $dr['dataType']['dataTypeMeta'][0]['sortField']['id'];

            $datarecord_data[$dr_num]['externalIdField_value'] = '';
            $datarecord_data[$dr_num]['nameField_value'] = '';
            $datarecord_data[$dr_num]['sortField_value'] = '';

            // Don't want to store the datatype's meta entry
            unset( $datarecord_data[$dr_num]['dataType']['dataTypeMeta'] );

            // Need to store a list of child/linked datarecords by their respective datatype ids
            $child_datarecords = array();
            foreach ($dr['children'] as $child_num => $cdr) {
                $cdr_id = $cdr['id'];
                $cdr_dt_id = $cdr['dataType']['id'];

                // A top-level datarecord is listed as its own parent in the database...don't want to store it in the list of children
                if ( $cdr_id == $dr['id'] )
                    continue;

                if ( !isset($child_datarecords[$cdr_dt_id]) )
                    $child_datarecords[$cdr_dt_id] = array();
                $child_datarecords[$cdr_dt_id][] = $cdr_id;
            }
            foreach ($dr['linkedDatarecords'] as $child_num => $ldt) {
                $ldr_id = $ldt['descendant']['id'];
                $ldr_dt_id = $ldt['descendant']['dataType']['id'];

                if ( !isset($child_datarecords[$ldr_dt_id]) )
                    $child_datarecords[$ldr_dt_id] = array();
                $child_datarecords[$ldr_dt_id][] = $ldr_id;
            }
            $datarecord_data[$dr_num]['children'] = $child_datarecords;
            unset( $datarecord_data[$dr_num]['linkedDatarecords'] );


            // Flatten datafield_meta of each datarecordfield, and organize by datafield id instead of some random number
            $new_drf_array = array();
            foreach ($dr['dataRecordFields'] as $drf_num => $drf) {

                $df_id = $drf['dataField']['id'];
                unset( $drf['dataField'] );

                // Flatten file metadata and get rid of encrypt_key
                foreach ($drf['file'] as $file_num => $file) {
                    unset( $drf['file'][$file_num]['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    $fm = $file['fileMeta'][0];
                    $drf['file'][$file_num]['fileMeta'] = $fm;

                    // Get rid of all private/non-essential information in the createdBy association
                    $drf['file'][$file_num]['createdBy'] = self::cleanUserData( $drf['file'][$file_num]['createdBy'] );
                }

                // Flatten image metadata, get rid of both the thumbnail's and the parent's encrypt keys, and sort appropriately
                $sort_by_image_id = true;
                foreach ($drf['image'] as $image_num => $image) {
                    if ($image['parent']['imageMeta'][0]['displayorder'] != 0) {
                        $sort_by_image_id = false;
                        break;
                    }
                }

                $ordered_images = array();
                foreach ($drf['image'] as $image_num => $image) {
                    unset( $image['encrypt_key'] );
                    unset( $image['parent']['encrypt_key'] ); // TODO - should encrypt_key actually remain in the array?

                    unset( $image['imageMeta'] );   // This is a phantom meta entry created for this image's thumbnail
                    $im = $image['parent']['imageMeta'][0];
                    $image['parent']['imageMeta'] = $im;

                    // Get rid of all private/non-essential information in the createdBy association
                    $image['parent']['createdBy'] = self::cleanUserData( $image['parent']['createdBy'] );

                    if ($sort_by_image_id) {
                        // Store by parent id
                        $ordered_images[ $image['parent']['id'] ] = $image;
                    }
                    else {
                        // Store by display_order
                        $ordered_images[ $image['parent']['imageMeta']['displayorder'] ] = $image;
                    }
                }

                ksort($ordered_images);
                $drf['image'] = $ordered_images;

                // Scrub all user information from the rest of the array
                $keys = array('boolean', 'integerValue', 'decimalValue', 'longText', 'longVarchar', 'mediumVarchar', 'shortVarchar', 'datetimeValue');
                foreach ($keys as $typeclass) {
                    if ( count($drf[$typeclass]) > 0 ) {
                        $drf[$typeclass][0]['createdBy'] = self::cleanUserData( $drf[$typeclass][0]['createdBy'] );

                        // Store the value from this storage entity if it's the one being used for external_id/name/sort datafields
                        if ($external_id_field !== null && $external_id_field == $df_id) {
                            $datarecord_data[$dr_num]['externalIdField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($name_datafield !== null && $name_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['nameField_value'] = $drf[$typeclass][0]['value'];
                        }
                        if ($sort_datafield !== null && $sort_datafield == $df_id) {
                            // Need to ensure this value is a string so php sorting functions don't complain
                            if ($typeclass == 'datetimeValue')
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value']->format('Y-m-d');
                            else
                                $datarecord_data[$dr_num]['sortField_value'] = $drf[$typeclass][0]['value'];
                        }
                    }
                }

                // Organize radio selections by radio option id
                $new_rs_array = array();
                foreach ($drf['radioSelection'] as $rs_num => $rs) {
                    $rs['createdBy'] = self::cleanUserData( $rs['createdBy'] );

                    $ro_id = $rs['radioOption']['id'];
                    $new_rs_array[$ro_id] = $rs;
                }

                $drf['radioSelection'] = $new_rs_array;
                $new_drf_array[$df_id] = $drf;
            }

            unset( $datarecord_data[$dr_num]['dataRecordFields'] );
            $datarecord_data[$dr_num]['dataRecordFields'] = $new_drf_array;
        }

        // Organize by datarecord id...DO NOT even attenpt to make this array recursive...for now...
        $formatted_datarecord_data = array();
        foreach ($datarecord_data as $num => $dr_data) {
            $dr_id = $dr_data['id'];

            // These two values should default to the datarecord id if empty
            if ( $dr_data['nameField_value'] == '' )
                $dr_data['nameField_value'] = $dr_id;
            if ( $dr_data['sortField_value'] == '' )
                $dr_data['sortField_value'] = $dr_id;

            $formatted_datarecord_data[$dr_id] = $dr_data;
        }

        $redis->set($redis_prefix.'.cached_datarecord_'.$grandparent_datarecord_id, gzcompress(serialize($formatted_datarecord_data)));
        return $formatted_datarecord_data;
    }


    /**
     * Removes all private/non-essential user info from an array generated by self::getDatarecordData() or self::getDatatypeData()
     *
     * @param array $user_data
     *
     * @return array
     */
    protected function cleanUserData($user_data)
    {
        foreach ($user_data as $key => $value) {
            if ($key !== 'username' && $key !== 'email' && $key !== 'firstName' && $key !== 'lastName'/* && $key !== 'institution' && $key !== 'position'*/)
                unset( $user_data[$key] );
        }

        return $user_data;
    }


    /**
     * Builds and returns a list of all child and linked datatype ids related to the given datatype id.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $datatype_ids
     * @param boolean $include_links
     *
     * @return int[]
     */
    protected function getAssociatedDatatypes($em, $datatype_ids, $include_links = true)
    {
        // Locate all datatypes that are either children of or linked to the datatypes in $datatype_ids
        $results = array();
        if ($include_links) {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }
        else {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id
                FROM ODRAdminBundle:DataTree AS dt
                LEFT JOIN dt.dataTreeMeta AS dtm
                LEFT JOIN dt.ancestor AS ancestor
                LEFT JOIN dt.descendant AS descendant
                WHERE dtm.is_link = :is_link AND ancestor.id IN (:ancestor_ids)
                AND dt.deletedAt IS NULL AND dtm.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('is_link' => 0, 'ancestor_ids' => $datatype_ids) );
            $results = $query->getArrayResult();
        }

        // Flatten the resulting array...
        $child_datatype_ids = array();
        foreach ($results as $num => $result)
            $child_datatype_ids[] = $result['descendant_id'];

        // If child datatypes were found, also see if those child datatypes have children of their own
        if ( count($child_datatype_ids) > 0 )
            $child_datatype_ids = array_merge( $child_datatype_ids, self::getAssociatedDatatypes($em, $child_datatype_ids, $include_links) );

        // Return an array of the requested datatype ids and their children
        $associated_datatypes = array_unique( array_merge($child_datatype_ids, $datatype_ids) );
        return $associated_datatypes;
    }


    /**
     * Builds and returns a list of all child and linked datarecords of the given datarecord id.
     * Due to recursive interaction with self::getLinkedDatarecords(), this function doesn't attempt to store results in the cache.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param int[] $grandparent_ids
     *
     * @return int[]
     */
    protected function getAssociatedDatarecords($em, $grandparent_ids)
    {
        // Locate all datarecords that are children of the datarecords listed in $grandparent_ids
        $query = $em->createQuery(
           'SELECT dr.id AS id
            FROM ODRAdminBundle:DataRecord AS dr
            LEFT JOIN dr.grandparent AS grandparent
            WHERE grandparent.id IN (:grandparent_ids)
            AND dr.deletedAt IS NULL AND grandparent.deletedAt IS NULL'
        )->setParameters( array('grandparent_ids' => $grandparent_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $datarecord_ids = array();
        foreach ($results as $result)
            $datarecord_ids[] = $result['id'];

        // Get all children and datarecords linked to all the datarecords in $datarecord_ids
        $linked_datarecord_ids = self::getLinkedDatarecords($em, $datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $associated_datarecord_ids = array_unique( array_merge($grandparent_ids, $linked_datarecord_ids) );

        return $associated_datarecord_ids;
    }


    /**
     * Builds and returns a list of all datarecords linked to from the provided datarecord ids.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer[] $ancestor_ids
     *
     * @return integer[]
     */
    private function getLinkedDatarecords($em, $ancestor_ids)
    {
        // Locate all datarecords that are linked to from any datarecord listed in $datarecord_ids
        $query = $em->createQuery(
           'SELECT descendant.id AS descendant_id
            FROM ODRAdminBundle:LinkedDataTree AS ldt
            JOIN ldt.ancestor AS ancestor
            JOIN ldt.descendant AS descendant
            WHERE ancestor.id IN (:ancestor_ids)
            AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL AND descendant.deletedAt IS NULL'
        )->setParameters( array('ancestor_ids' => $ancestor_ids) );
        $results = $query->getArrayResult();

        // Flatten the results array
        $linked_datarecord_ids = array();
        foreach ($results as $result)
            $linked_datarecord_ids[] = $result['descendant_id'];

        // If there were datarecords found, get all of their associated child/linked datarecords
        $associated_datarecord_ids = array();
        if ( count($linked_datarecord_ids) > 0 )
            $associated_datarecord_ids = self::getAssociatedDatarecords($em, $linked_datarecord_ids);

        // Don't want any duplicate datarecord ids...
        $linked_datarecord_ids = array_unique( array_merge($linked_datarecord_ids, $associated_datarecord_ids) );

        return $linked_datarecord_ids;
    }
}
