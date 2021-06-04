<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service Test
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to how ODR stores data, MYSQL is unable to assist with filtering based on public status or
 * whether child/linked records match the search.  As such, ODR's search system has to duplicate a
 * lot of that functionality, and the resulting system is rather finicky.
 */

namespace ODR\OpenRepository\SearchBundle\Tests\Component\Service;

// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class SearchAPIServiceTest extends WebTestCase
{

    /**
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performSearch
     * @dataProvider provideSearchParams
     */
    public function testPerformSearch($search_params, $expected_grandparent_ids, $search_as_super_admin)
    {
        $client = static::createClient();

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $client->getContainer()->get('odr.search_api_service');
        /** @var SearchKeyService $search_key_service */
        $search_key_service = $client->getContainer()->get('odr.search_key_service');

        // Convert each array of search params into a search key, then run the search
        $search_key = $search_key_service->encodeSearchKey($search_params);
        $search_results = $search_api_service->performSearch(null, $search_key, array(), 0, true, $search_as_super_admin);

        $this->assertEqualsCanonicalizing( $expected_grandparent_ids, $search_results['grandparent_datarecord_list'] );
    }


    /**
     * @return array
     */
    public function provideSearchParams()
    {
        return [
            // Sanity check searches
            'RRUFF Reference: default search' => [
                array(
                    'dt_id' => 1
                ),
                range(1, 90),
                true
            ],
            'IMA List: default search' => [
                array(
                    'dt_id' => 2
                ),
                array(91,92,93,94,95,96,97),
                true
            ],
            'IMA List: default search, public datarecords only' => [
                array(
                    'dt_id' => 2
                ),
                array(92,93,94,95,96,97),
                false
            ],

            // simple regular searches
            'RRUFF Reference: authors containing "downs"' => [
                array(
                    'dt_id' => 1,
                    '1' => "downs"
                ),
                array(35,36,49,66,68),
                true
            ],
            'RRUFF Reference: authors containing "downs" and journal not containing "raman"' => [
                array(
                    'dt_id' => 1,
                    '1' => "downs",
                    '3' => "!raman"
                ),
                array(35,49,66,68),
                true
            ],

            'IMA List: mineral_names containing "b"' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                ),
                array(91,92,95),
                true
            ],
            'IMA List: mineral_names containing "b", public datarecords only' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                ),
                array(92,95),
                false
            ],
            'IMA List: tags containing "Grandfathered"' => [
                array(
                    'dt_id' => 2,
                    '28' => "19",
                ),
                array(92,93,96,97),
                true
            ],
            'IMA List: tags containing "Abiotic"' => [
                array(
                    'dt_id' => 2,
                    '28' => "41",
                ),
                array(92,93,94,97),
                true
            ],
            'IMA List: tags containing "Grandfathered OR Abiotic"' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,41",
                ),
                array(92,93,94,96,97),
                true
            ],
            'IMA List: tags containing "Grandfathered AND NOT Abiotic"' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,-41",
                ),
                array(96),
                true
            ],

            'RRUFF Sample: rruff_id contains "c"' => [
                array(
                    'dt_id' => 3,
                    '30' => "c",
                ),
                array(99,101,104,110,114,117,127,130,132,134,135,136),
                true
            ],

            // Searches involving child/linked datatypes
            'RRUFF Sample: samples where mineral_name contains "b"' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                ),
                array(98,124,126,122,121,108),
                true
            ],
            'RRUFF Sample: samples where mineral_name contains "b", public records only' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                ),
                array(124,126,122,121,108),
                false
            ],
            'RRUFF Sample: samples where Raman Spectra::wavelength contains "780"' => [
                array(
                    'dt_id' => 3,
                    '41' => "780",
                ),
                array(102,118,107,125,109,126,131,124,123,103,120,119,100,105),
                true
            ],
            'RRUFF Sample: samples where Raman Spectra::wavelength contains "780", public records only' => [
                array(
                    'dt_id' => 3,
                    '41' => "780",
                ),
                array(102,118,107,125,109,126,131,124,123,103,120,119,100,105),
                false
            ],
            'RRUFF Sample: samples where RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 3,
                    '1' => "downs",
                ),
                array(98,127,114,139,101,111,130,113,136,120,117,123,119,129,125,110,107,134,128,100,118,131,116,105,138,109,99,135,103,106,112),
                true
            ],
            'RRUFF Sample: samples where RRUFF Reference::Authors contains "downs", public records only' => [
                array(
                    'dt_id' => 3,
                    '1' => "downs",
                ),
                array(127,114,139,101,111,130,113,136,120,117,123,119,129,125,110,107,134,128,100,118,131,116,105,138,109,99,135,103,106,112),
                false
            ],

            'IMA List: mineral_name contains "b" and RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(91),
                true
            ],
            'IMA List: mineral_name contains "b" and RRUFF Reference::Authors contains "downs", public records only' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(),
                false
            ],
            'RRUFF Sample: IMA Mineral::mineral_name contains "b" and RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(98),
                true
            ],
            'RRUFF Sample: IMA Mineral::mineral_name contains "b" and RRUFF Reference::Authors contains "downs", public records only' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(),
                false
            ],

        ];
    }
}
