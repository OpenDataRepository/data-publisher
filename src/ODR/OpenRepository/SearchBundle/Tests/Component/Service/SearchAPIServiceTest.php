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
            'RRUFF Reference: default search, including non-public records' => [
                array(
                    'dt_id' => 1
                ),
                range(1, 90),
                true
            ],
            'IMA List: default search, including non-public records' => [
                array(
                    'dt_id' => 2
                ),
                array(91,92,93,94,95,96,97),
                true
            ],
            'IMA List: default search' => [
                array(
                    'dt_id' => 2
                ),
                array(92,93,94,95,96,97),
                false
            ],

            // simple regular searches
            'RRUFF Reference: authors containing "downs", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '1' => "downs"
                ),
                array(35,36,49,66,68),
                true
            ],
            'RRUFF Reference: authors containing "downs" and journal not containing "raman", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '1' => "downs",
                    '3' => "!raman"
                ),
                array(35,49,66,68),
                true
            ],

            'IMA List: mineral_names containing "b", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                ),
                array(91,92,95),
                true
            ],
            'IMA List: mineral_names containing "b"' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                ),
                array(92,95),
                false
            ],
            'IMA List: tags containing "Grandfathered", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19",
                ),
                array(92,93,96,97),
                true
            ],
            'IMA List: tags containing "Abiotic", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "41",
                ),
                array(92,93,94,97),
                true
            ],
            'IMA List: tags containing "Grandfathered OR Abiotic", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,41",
                ),
                array(92,93,94,96,97),
                true
            ],
            'IMA List: tags containing "Grandfathered AND NOT Abiotic", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,-41",
                ),
                array(96),
                true
            ],

            'RRUFF Sample: rruff_id contains "c", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '30' => "c",
                ),
                array(99,101,104,110,114,117,127,130,132,134,135,136),
                true
            ],

            // Searches involving child/linked datatypes
            'RRUFF Sample: samples where mineral_name contains "b", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                ),
                array(98,124,126,122,121,108),
                true
            ],
            'RRUFF Sample: samples where mineral_name contains "b"' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                ),
                array(124,126,122,121,108),
                false
            ],
            'RRUFF Sample: samples where Raman Spectra::wavelength contains "780", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '41' => "780",
                ),
                array(102,118,107,125,109,126,131,124,123,103,120,119,100,105),
                true
            ],
            'RRUFF Sample: samples where Raman Spectra::wavelength contains "780"' => [
                array(
                    'dt_id' => 3,
                    '41' => "780",
                ),
                array(102,118,107,125,109,126,131,124,123,103,120,119,100,105),
                false
            ],
            'RRUFF Sample: samples where RRUFF Reference::Authors contains "downs", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '1' => "downs",
                ),
                array(98,127,114,139,101,111,130,113,136,120,117,123,119,129,125,110,107,134,128,100,118,131,116,105,138,109,99,135,103,106,112),
                true
            ],
            'RRUFF Sample: samples where RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 3,
                    '1' => "downs",
                ),
                array(127,114,139,101,111,130,113,136,120,117,123,119,129,125,110,107,134,128,100,118,131,116,105,138,109,99,135,103,106,112),
                false
            ],

            'IMA List: mineral_name contains "b" and RRUFF Reference::Authors contains "downs", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(91),
                true
            ],
            'IMA List: mineral_name contains "b" and RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 2,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(),
                false
            ],
            'RRUFF Sample: IMA Mineral::mineral_name contains "b" and RRUFF Reference::Authors contains "downs", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(98),
                true
            ],
            'RRUFF Sample: IMA Mineral::mineral_name contains "b" and RRUFF Reference::Authors contains "downs"' => [
                array(
                    'dt_id' => 3,
                    '17' => "b",
                    '1' => "downs"
                ),
                array(),
                false
            ],

            // want "exact searches" with a space character to use "LIKE" instead of "="
            'RRUFF Reference: authors exactly matches "Effenberger H"' => [
                array(
                    'dt_id' => 1,
                    '1' => "\"Effenberger H\""
                ),
                array(4, 37, 86),    // term is an exact match for 4 and 37, but only a subset of 86
                false
            ],
            'RRUFF Sample: contains the phrase "sample description"' => [
                array(
                    'dt_id' => 3,
                    '36' => "\"sample description\""
                ),
                array(134,110,135),
                false
            ],
            'RRUFF Sample: does not contain the phrase "associated with"' => [
                array(
                    'dt_id' => 3,
                    '36' => "!\"associated with\""
                ),
                array(98,100,101,103,105,106,107,108,109,111,114,116,117,118,120,121,122,123,124,125,126,127,128,130,131,132,133,136,137,138,139,134,110,99,135,104),
                false
            ],
            'RRUFF Sample: does not contain the phrase "associated with" and does not contain "variety"' => [
                array(
                    'dt_id' => 3,
                    '36' => "!\"associated with\" !variety"
                ),
                array(98,101,103,105,106,107,108,109,111,114,117,118,120,121,122,123,124,126,127,128,130,131,132,133,136,137,138,139,134,110,99,135,104),
                false
            ],

            // searches for a single doublequote should be handled differently than paired quotes
            'RRUFF Sample: sample_descriptions containing "\""' => [
                array(
                    'dt_id' => 3,
                    '36' => "\""
                ),
                array(99,104,110,134,135),
                false
            ],
            'RRUFF Sample: sample_descriptions not containing "\""' => [
                array(
                    'dt_id' => 3,
                    '36' => "!\""
                ),
                array(98,100,101,102,103,105,106,107,108,109,111,112,113,114,115,116,117,118,119,120,121,122,123,124,125,126,127,128,129,130,131,132,133,136,137,138,139),
                false
            ],

            'RRUFF Sample: sample_descriptions containing "\"a"' => [
                array(
                    'dt_id' => 3,
                    '36' => "\"a"
                ),
                array(134,110),
                false
            ],
            'RRUFF Sample: sample_descriptions containing "\"description of"' => [
                array(
                    'dt_id' => 3,
                    '36' => "\"description of"
                ),
                array(99,104),
                false
            ],

            'RRUFF Sample: sample_descriptions containing "description\""' => [
                array(
                    'dt_id' => 3,
                    '36' => "description\""
                ),
                array(134,110,135),
                false
            ],
            'RRUFF Sample: sample_descriptions containing "of a sample\""' => [
                array(
                    'dt_id' => 3,
                    '36' => "of a sample\""
                ),
                array(99,104),
                false
            ],

            // single doublequotes followed by a space
            'RRUFF Sample: sample_descriptions containing "z OR \""' => [
                array(
                    'dt_id' => 3,
                    '36' => "z OR \""
                ),
                array(102,115,127,132,134,110,99,135,104),
                false
            ],
            'RRUFF Sample: sample_descriptions containing "\"" OR "z"' => [
                array(
                    'dt_id' => 3,
                    '36' => "\" OR z"
                ),
                array(102,115,127,132,134,110,99,135,104),
                false
            ],

            // no closing doublequote means this should search for "\"" AND "sample" AND "description"
            'RRUFF Sample: sample_descriptions containing "\" sample description"' => [
                array(
                    'dt_id' => 3,
                    '36' => "\" sample description"
                ),
                array(134,110,99,135,104),
                false
            ],
            // closing doublequote means this should search for the phrase " sample description"
            'RRUFF Sample: sample_descriptions containing "\" sample description\""' => [
                array(
                    'dt_id' => 3,
                    '36' => "\" sample description\""
                ),
                array(134,110,135),
                false
            ],

            // string tokens in a number field should return no results
            'IMA List: mineral_id contains "abc"' => [
                array(
                    'dt_id' => 2,
                    '16' => 'abc'
                ),
                array(),
                false
            ],
            'IMA List: mineral_id contains "7 abc", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '16' => '7 abc'
                ),
                array(91,94,96),    // the "7" token should end up matching mineral_ids 777, 788, and 790
                true
            ],
            'IMA List: mineral_id contains "7 abc"' => [
                array(
                    'dt_id' => 2,
                    '16' => '7 abc'
                ),
                array(94,96),    // the mineral_id 777 is non-public, so it won't be in a search done without permissions
                false
            ],

            // a sequence of unmatched operators should get ignored
            'IMA List: mineral_id contains "<will be autogenerated>"' => [
                // gets initially processed to...array("<", "&&", "will", "&&", "be", "&&", "autogenerated", "&&", ">")
                // then the non-numeric parts get dropped...array("<", "&&", "&&", ">")
                // then the unmatched logical operators get dropped...array("<", ">")
                // then the trailing operators get dropped...array()
                array(
                    'dt_id' => 2,
                    '16' => '<will be autogenerated>'
                ),
                array(),
                false
            ],
        ];
    }
}
