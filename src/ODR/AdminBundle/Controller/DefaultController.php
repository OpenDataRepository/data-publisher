<?php

/**
* Open Data Repository Data Publisher
* Default Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The Default controller handles the loading of the base template
* and AJAX handlers that the rest of the site uses.  It also
* handles the creation of the information displayed on the site's
* dashboard.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User;
// Forms
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;

class DefaultController extends ODRCustomController
{

    /**
    * Triggers the loading of base.html.twig, and sets up session cookies.
    * 
    * @param Request $request
    * 
    * @return Response
    */
    public function indexAction(Request $request)
    {
        // Grab the current user
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        $datatype_permissions = array();
        if ($user !== 'anon.') {
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];
        }


        // Render the base html for the page...$this->render() apparently creates a full Reponse object
//        $site_baseurl = $this->container->getParameter('site_baseurl');
        $html = $this->renderView(
            'ODRAdminBundle:Default:index.html.twig',
            array(
                'user' => $user,
                'user_permissions' => $datatype_permissions,

//                'site_baseurl' => $site_baseurl,
//                'search_slug' => $search_slug,
            )
        );

        $response = new Response($html);

        // Set the search cookie if it doesn't exist
        $cookies = $request->cookies;
        if ( !$cookies->has('prev_searched_datatype') ) {

            $top_level_datatypes = parent::getTopLevelDatatypes();

            $query = $em->createQuery(
               'SELECT dt, up.can_view_type AS can_view_type
                FROM ODRAdminBundle:DataType AS dt
                JOIN ODRAdminBundle:UserPermissions AS up WITH up.dataType = dt
                WHERE up.user = :user_id AND dt IN (:datatypes)
                AND dt.deletedAt IS NULL'
            )->setParameters( array('user_id' => $user->getId(), 'datatypes' => $top_level_datatypes) );
            $results = $query->getResult();

            foreach ($results as $num => $result) {
                /** @var DataType $datatype */
                $datatype = $result[0];
                $can_view_type = $result['can_view_type'];

                // Locate first top-level datatype that either is public or viewable by the user logging in
                if ( $datatype->isPublic() || $can_view_type == 1 ) {
                    $response->headers->setCookie(new Cookie('prev_searched_datatype', $datatype->getSearchSlug()));
                    break;
                }
            }
        }

        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }

    /**
    * Loads the dashboard blurbs about the most populous datatypes on the site.
    * 
    * @param Request $request
    * 
    * @return Response
    */
    public function dashboardAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Ensure user has correct set of permissions, since this is immediately called after login...
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            $user_permissions = parent::getUserPermissionsArray($em, $user->getId());
            $datatype_permissions = $user_permissions['datatypes'];

            // Grab the cached graph data
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            // Only want to create dashboard html graphs for top-level datatypes...
            $datatypes = parent::getTopLevelDatatypes();
//print_r($datatypes);

            $dashboard_order = array();
            $dashboard_headers = array();
            $dashboard_graphs = array();
            foreach ($datatypes as $num => $datatype_id) {
                // Don't display dashboard stuff that the user doesn't have permission to see
                if ( !(isset($datatype_permissions[ $datatype_id ]) && isset($datatype_permissions[ $datatype_id ][ 'dt_view' ])) ) {
//print 'no permissions for datatype '.$datatype_id."\n";
                    continue;
                }

                // No caching in dev environment
                $bypass_cache = false;
                if ($this->container->getParameter('kernel.environment') === 'dev')
                    $bypass_cache = true;

                $data = parent::getRedisData(($redis->get($redis_prefix.'.dashboard_'.$datatype_id)));
                if ($data == false || $bypass_cache)
                    $data = self::getDashboardHTML($em, $datatype_id);

                $total = $data['total'];
                $header = $data['header'];
                $graph = $data['graph'];

                $dashboard_order[$datatype_id] = $total;
                $dashboard_headers[$datatype_id] = $header;
                $dashboard_graphs[$datatype_id] = $graph;
            }

            // Sort by number of datarecords
            arsort($dashboard_order);

            $header_str = '';
            $graph_str = '';
            $count = 0;
            foreach ($dashboard_order as $datatype_id => $total) {
                // Only display the top 8 datatypes with the most datarecords
                $count++;
                if ($count > 8)
                    continue;

                $header_str .= $dashboard_headers[$datatype_id];
                $graph_str .= $dashboard_graphs[$datatype_id];
            }

            // Finally, render the main dashboard page
            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Default:dashboard.html.twig',
                array(
                    'dashboard_headers' => $header_str,
                    'dashboard_graphs' => $graph_str,
                )
            );
            $return['d'] = array('html' => $html);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x1883779 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Recalculates the dashboard blurb for a specified datatype.
     *
     * @param \Doctrine\ORM\EntityManager $em
     * @param integer $datatype_id             Which datatype is having its dashboard blurb rebuilt.
     *
     * @return array
     */
    private function getDashboardHTML($em, $datatype_id)
    {
        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        $datatype_name = $datatype->getShortName();

        // Temporarily disable the code that prevents the following query from returning deleted rows
        $em->getFilters()->disable('softdeleteable');
        $query = $em->createQuery(
           'SELECT dr.id AS datarecord_id, dr.created AS created, dr.deletedAt AS deleted, dr.updated AS updated
            FROM ODRAdminBundle:DataRecord AS dr
            JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
            WHERE dr.dataType = :datatype AND dr.provisioned = false'
        )->setParameters( array('datatype' => $datatype_id) );
        $results = $query->getArrayResult();
        $em->getFilters()->enable('softdeleteable');    // Re-enable it


        // Build the array of date objects so datarecords created/deleted in the past 6 weeks can be counted
//        $current_date = new \DateTime();
        $cutoff_dates = array();
        for ($i = 1; $i < 7; $i++) {
            $tmp_date = new \DateTime();
            $str = 'P'.($i*7).'D';

            $cutoff_dates[($i-1)] = $tmp_date->sub(new \DateInterval($str));
        }

        $total_datarecords = 0;
        $values = array();

        //
        $values['created'] = array();
        $values['updated'] = array();
        for ($i = 0; $i < 6; $i++) {
            $values['created'][$i] = 0;
            $values['updated'][$i] = 0;
        }

        // 
        foreach ($results as $num => $result) {
//            $datarecord_id = $result['datarecord_id'];
            $create_date = $result['created'];
            $delete_date = $result['deleted'];
            if ($delete_date == '')
                $delete_date = null;
            $modify_date = $result['updated'];

            // Don't count deleted datarecords towards the total number of datarecords for this datatype
            if ($delete_date == null) {
                $total_datarecords++;
            }

            // If this datarecord was created in the past 6 weeks, store which week it was created in
            for ($i = 0; $i < 6; $i++) {
                if ($create_date > $cutoff_dates[$i]) {
                    $values['created'][$i]++;
                    break;
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date != null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($delete_date > $cutoff_dates[$i]) {
                        $values['created'][$i]--;
                        break;
                    }
                }
            }

            // If this datarecord was deleted in the past 6 weeks, store which week it was deleted in
            if ($delete_date == null) {
                for ($i = 0; $i < 6; $i++) {
                    if ($modify_date > $cutoff_dates[$i]) {
                        $values['updated'][$i]++;
                        break;
                    }
                }
            }

        }

//print $datatype_name."\n";
//print_r($values);

        //
        $created = $values['created'];
        $updated = $values['updated'];

        // Calculate the total added/deleted since six weeks ago
        $total_created = 0;
        $total_updated = 0;
        for ($i = 0; $i < 6; $i++) {
            $total_created += $created[$i];
            $total_updated += $updated[$i];
        }

        $value_str = $created[5].':'.$updated[5];
        for ($i = 4; $i >= 0; $i--) {
            $value_str .= ','.$created[$i].':'.$updated[$i];
        }

        $created_str = '';
        if ( $total_created < 0 )
            $created_str = abs($total_created).' deleted';
        else
            $created_str = $total_created.' created';

        $updated_str = $total_updated.' modified';


        // Render the actual html
        $templating = $this->get('templating');
        $header = $templating->render(
            'ODRAdminBundle:Default:dashboard_header.html.twig',
            array(
		'search_slug' => $datatype->getSearchSlug(),
                'datatype_id' => $datatype_id,
                'total_datarecords' => $total_datarecords,
                'datatype_name' => $datatype_name,
            )
        );
        $graph = $templating->render(
            'ODRAdminBundle:Default:dashboard_graph.html.twig',
            array(
                'datatype_name' => $datatype_name,
                'created_str' => $created_str,
                'updated_str' => $updated_str,
                'value_str' => $value_str,
            )
        );

        $data = array(
            'total' => $total_datarecords,
            'header' => $header,
            'graph' => $graph,
        );

        // Grab memcached stuff
        $redis = $this->container->get('snc_redis.default');;
        // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        // Store the dashboard data in memcached
        // TODO Figure out how to set an lifetime using PREDIS
        $redis->set($redis_prefix.'.dashboard_'.$datatype_id, gzcompress(serialize($data))); // Cache this dashboard entry for upwards of one day
        $redis->expire($redis_prefix.'.dashboard_'.$datatype_id, 1*24*60*60); // Cache this dashboard entry for upwards of one day
        // $redis->set($redis_prefix.'.dashboard_'.$datatype_id, gzcompress(serialize($data)), 1*24*60*60); // Cache this dashboard entry for upwards of one day

        // 
        return $data;
    }


    /**
    * TODO - sitemap function
    * 
    * @param Request $request
    * 
    * @return Response TODO
    */
    public function buildsitemapAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $sitemapindex = null;
        $sitemap = null;

        try {
            // Basics
            $baseurl = $this->container->getParameter('site_baseurl');
            $sitemap_url = $baseurl.'/uploads/sitemap/';
            $sitemap_path = './uploads/sitemap/';

            // Protocol states no more than 50k URLs per sitemap file, and sitemap file no bigger than 10MB...restrict to 40k URLs per file to be safe?
            $url_limit = 40000;
            $num_urls = 0;
            $sitemap_file_count = 1;

            // Setup the sitemapindex file
            $sitemapindex = fopen($sitemap_path.'sitemapindex.xml', 'w');
            if ($sitemapindex === false) {
                // Shouldn't be an issue?
                throw new \Exception('Could not open file at "'.$sitemap_path.'sitemapindex.xml"');
            }

            // Write opening sitemapindex data
            fwrite($sitemapindex, '<?xml version="1.0" encoding="UTF-8"?>');
            fwrite($sitemapindex, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');

            // Write first sitemap file into sitemapindex
            fwrite($sitemapindex, '<sitemap>');
            fwrite($sitemapindex, '<loc>'.$sitemap_url.'sitemap_1.xml</loc>');
            // also need a <lastmod> tag?
            fwrite($sitemapindex, '</sitemap>');

            // Open first sitemap file
            $sitemap = fopen($sitemap_path.'sitemap_'.$sitemap_file_count.'.xml', 'w');
            if ($sitemap === false) {
                // Shouldn't be an issue?
                throw new \Exception('Could not open file at "'.$sitemap_path.'sitemap_'.$sitemap_file_count.'.xml"');
            }
            // Write first lines to sitemap file
            fwrite($sitemap, '<?xml version="1.0" encoding="UTF-8"?>');
            fwrite($sitemap, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');

            // ------------------------------
            // Necessary objects...
            $em = $this->getDoctrine()->getManager();
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            // Grab all top-level datatypes on the site
            $descendants = array();
            $datatrees = $repo_datatree->findAll();
            foreach ($datatrees as $datatree) {
                if ($datatree->getIsLink() == 0)
                    $descendants[] = $datatree->getDescendant()->getId();
            }

            $datatypes = array();
            $tmp_datatypes = $repo_datatype->findAll();
            foreach ($tmp_datatypes as $tmp) {
                if (!in_array($tmp->getId(), $descendants))
                    $datatypes[] = $tmp;
            }

            foreach ($datatypes as $datatype) {
                // Write that datatype's shortresultlist? link as a url in the sitemap file
                $url = $this->generateUrl( 'odr_api_map_datatype', array('datatype_id' => $datatype->getId(), 'datatype_name' => $datatype->getXmlShortName()) );
                fwrite($sitemap, '<url>');
                fwrite($sitemap, '<loc>'.$baseurl.$url.'</loc>');
                // also need a <lastmod> tag?
                fwrite($sitemap, '</url>');
                $num_urls++;

                // Grab the name datafield out here
                $name_field = $datatype->getNameField();
                if ($name_field === null) {
                    //
                    throw new \Exception('The sitemap can\'t be built because DataType '.$datatype->getId().' "'.$datatype->getShortName().'" does not have a name field selected!');
                }
                $type_class = $name_field->getFieldType()->getTypeClass();

                // Write the urls of all the datarecords of that datatype as links in the sitemap file
                $datarecords = $repo_datarecord->findByDataType($datatype);
                foreach ($datarecords as $datarecord) {
                    // Ensure we don't go over the url limit
                    if ($num_urls >= $url_limit) {
                        $num_urls = 0;
                        $sitemap_file_count++;

                        // Close out current sitemap file and open a new one
                        fwrite($sitemap, '</urlset>');
                        fclose($sitemap);
                        $sitemap = fopen($sitemap_path.'sitemap_'.$sitemap_file_count.'.xml', 'w');
                        if ($sitemap === false) {
                            // Shouldn't be an issue?
                            throw new \Exception('Could not open file at "'.$sitemap_path.'sitemap_'.$sitemap_file_count.'.xml"');
                        }

                        // Write a new entry in the sitemapindex file
                        fwrite($sitemapindex, '<sitemap>');
                        fwrite($sitemapindex, '<loc>'.$sitemap_url.'sitemap_'.$sitemap_file_count.'.xml</loc>');
                        // also need a <lastmod> tag?
                        fwrite($sitemapindex, '</sitemap>');
                    }

                    // Grab the name field that identifies the record
                    $drf = $repo_datarecordfields->findOneBy( array('dataRecord' => $datarecord->getId(), 'dataField' => $name_field->getId()) );
//                    $obj = parent::loadFromDataRecordField($drf, $type_class);
//                    $field_name = $obj->getValue();
                    $field_name = $drf->getAssociatedEntity()->getValue();

                    // Write that datarecord's link as a url in the sitemap file
                    $url = $this->generateUrl( 'odr_api_map_datarecord', array('datarecord_id' => $datarecord->getId(), 'datarecord_name' => $field_name) );
                    fwrite($sitemap, '<url>');
                    fwrite($sitemap, '<loc>'.$baseurl.$url.'</loc>');
                    // also need a <lastmod> tag?
                    fwrite($sitemap, '</url>');
                    $num_urls++;
                }
            }
            // ------------------------------

            // Write closing sitemap data
            fwrite($sitemap, '</urlset>');
            fclose($sitemap);

            // Write closing sitemapindex data
            fwrite($sitemapindex, '</sitemapindex>');
            fclose($sitemapindex);
        }
        catch (\Exception $e) {
            // Close the open files on error..shouldn't matter, but...
            fclose($sitemapindex);
            fclose($sitemap);

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18677679 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }

}
