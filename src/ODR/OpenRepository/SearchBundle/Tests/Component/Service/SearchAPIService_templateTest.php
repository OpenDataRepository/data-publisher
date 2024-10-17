<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service fieldstats Test
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * TODO
 */

namespace ODR\OpenRepository\SearchBundle\Tests\Component\Service;

// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class SearchAPIService_templateTest extends WebTestCase
{

    /**
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performTemplateSearch
     * @dataProvider provideTemplateSearchParams
     */
    public function testPerformTemplateSearch($search_params, $expected_grandparent_ids, $search_as_super_admin)
    {
        exec('redis-cli flushall');
        $client = static::createClient();

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $client->getContainer()->get('odr.search_api_service');
        /** @var SearchKeyService $search_key_service */
        $search_key_service = $client->getContainer()->get('odr.search_key_service');

        // Convert each array of search params into a search key, then run the search
        $search_key = $search_key_service->encodeSearchKey($search_params);
        $grandparent_datarecord_list = $search_api_service->performTemplateSearch(
            $search_key,
            $search_as_super_admin
        );

        $this->assertEqualsCanonicalizing( $expected_grandparent_ids, $grandparent_datarecord_list );
    }

    /**
     * @return array
     */
    public function provideTemplateSearchParams()
    {
        return [
            // ----------------------------------------
            // Sanity check searches
            'IMA List: fieldstats' => [
                array(
                    "template_uuid" => '1060f986e136779ce23576189b4c',
                    "field_stats" => 'afa7e6cb5c77cc7994630e4a6faa',
                ),
                array(),    // TODO - this doesn't return anything convenient to test against...
                true
            ],
        ];
    }
}
