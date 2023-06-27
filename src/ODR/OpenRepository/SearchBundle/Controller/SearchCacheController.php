<?php

/**
 * Open Data Repository Data Publisher
 * Remote Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Holds functions that help users set up and configure the "ODR Remote Search" javascript module,
 * intended for use by a 3rd party website.
 *
 * These controller actions help the user select the (subset of) datatype/datafields that they're
 * interested in searching from their own website...a second piece of javascript is then generated
 * that configures the module, allowing it to build the base64 search keys that ODR's search system
 * expects.
 *
 * People can then enter search terms on the 3rd party website, and get redirected to the equivalent
 * search results page on ODR.
 */

namespace ODR\OpenRepository\SearchBundle\Controller;

use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\DatatreeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
use ODR\AdminBundle\Component\Utility\UserUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Symfony
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');
        $api_key = $this->container->getParameter('beanstalk_api_key');
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
            $datarecord_id = $result->getId();

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

            // Do we deal with ElasticSearch now?
            // $url = $this->generateUrl('odr_api_get_dataset_record', array(
                // 'version' => 'v3', // $version,
                // 'record_uuid' => $result->getUniqueId()
            // ), false);

            // 'master_datatype_uuid' => $datatype->getMasterDataType()->getUniqueId(),
            // Insert the new job into the queue
            // $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    'url' => $url,
                    // 'api_url' => $api_url
                )
            );

            $pheanstalk->useTube('odr_record_precache')->put($payload);
            print "Record (" . $num . ') - ' . $result->getId() . ' - ' . $url . '<br />';

        }
        print count($results) . " records added to queue"; exit();
    }
}
