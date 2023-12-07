<?php

/**
 * Open Data Repository Data Publisher
 * SearchCache Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Inserts all records of a datatype into a pheanstalk queue so they can be cached in the background.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataType;
// Exceptions
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Component\HttpFoundation\Request;


class SearchCacheController extends Controller
{

    /**
     * Pre-caches records so search is faster
     *
     */
    public function preCacheRecordsAction($datatype_id, Request $request)
    {
        // ----------------------------------------
        // Grab necessary stuff for pheanstalk...
        $pheanstalk = $this->get('pheanstalk');

        // print $site_baseurl;exit();
        // Get Datatype
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var DataType $datatype */
        $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
        if ($datatype == null)
            throw new ODRNotFoundException('Datatype');

        // Get a count of records
        // Retrieve what should be the first and only datarecord...
        $results = $em->getRepository('ODRAdminBundle:DataRecord')->findBy(
            array(
                'dataType' => $datatype->getId()
            )
        );

        // ----------------------------------------
        // Create one beanstalk job per datarecord
        foreach ($results as $num => $result) {
            // Get URL for Record
            // search_theme_id 0 is default
            // Generate a search key based on datatype id
            /*
             * {"dt_id":"77","sort_by":[{"sort_df_id":"195","sort_dir":"asc"}],"199":"Fe"}
             */
            $search_params = array("dt_id" => $datatype_id);

            /** @var SearchKeyService $search_key_service */
            $search_key_service = $this->container->get('odr.search_key_service');
            $search_key = $search_key_service->encodeSearchKey($search_params);

            // var url = '{{ path('odr_search_render', { 'search_theme_id': search_theme_id, 'search_key': search_key, 'offset': '1' } ) }}';
            // path: /search/display/{search_theme_id}/{search_key}/{offset}/{intent}
            $url = $this->generateUrl(
                // 'odr_search_render',
                'odr_display_view',
                array(
                    'datarecord_id' => $result->getId(),
                    'search_theme_id' => 0,
                    'search_key' => $search_key,
                    'offset' => 1
                ),
                false
            );

            // If wordpress integrated
            $site_baseurl = $this->container->getParameter('site_baseurl');
            if($this->container->getParameter('odr_wordpress_integrated')) {
                $site_baseurl = $this->container->getParameter('wordpress_site_baseurl');
            }

            $url = $site_baseurl . '/' . $datatype->getSearchSlug() . "#" . $url;

            $payload = json_encode(
                array(
                    'url' => $url,
                )
            );

            // Record pre-cache for interface display speed
            // Uses puppeteer to preload public version in chrome
            $pheanstalk->useTube('odr_record_precache')->put($payload);
            print "Record (" . $num . ') - ' . $result->getId() . ' - ' . $url . '<br />';

        }
        print count($results) . " records added to queue"; exit();
    }
}
