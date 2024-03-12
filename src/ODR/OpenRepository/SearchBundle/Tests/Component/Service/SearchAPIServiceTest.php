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
        exec('redis-cli flushall');
        $client = static::createClient();

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $client->getContainer()->get('odr.search_api_service');
        /** @var SearchKeyService $search_key_service */
        $search_key_service = $client->getContainer()->get('odr.search_key_service');

        // Convert each array of search params into a search key, then run the search
        $search_key = $search_key_service->encodeSearchKey($search_params);
        $grandparent_datarecord_list = $search_api_service->performSearch(
            null,         // don't want to hydrate Datatypes here, so this is null
            $search_key,
            array(),      // search testing is with either zero permissions, or super-admin permissions
            false,        // only want grandparent datarecord ids here
            array(),      // testing doesn't need a specific set of sort datafields...
            array(),      // ...or a specific sort order
            $search_as_super_admin
        );

        $this->assertEqualsCanonicalizing( $expected_grandparent_ids, $grandparent_datarecord_list );
    }

    /**
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performSearch
     * @dataProvider provideSearchParamsCompleteDatarecordList
     */
    public function testPerformSearchCompleteDatarecordList($search_params, $expected_datarecord_ids, $search_as_super_admin)
    {
        $client = static::createClient();

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $client->getContainer()->get('odr.search_api_service');
        /** @var SearchKeyService $search_key_service */
        $search_key_service = $client->getContainer()->get('odr.search_key_service');

        // Convert each array of search params into a search key, then run the search
        $search_key = $search_key_service->encodeSearchKey($search_params);
        $complete_datarecord_list = $search_api_service->performSearch(
            null,         // don't want to hydrate Datatypes here, so this is null
            $search_key,
            array(),      // search testing is with either zero permissions, or super-admin permissions
            true,         // want all child/linked descendants that match the search here
            array(),      // the complete datarecord list can't be sorted
            array(),
            $search_as_super_admin
        );

        $this->assertEqualsCanonicalizing( $expected_datarecord_ids, $complete_datarecord_list );
    }


    /**
     * @return array
     */
    public function provideSearchParams()
    {
        return [
            // ----------------------------------------
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
            'IMA List: default search, not logged in' => [
                array(
                    'dt_id' => 2
                ),
                array(92,93,94,95,96,97),
                false
            ],

            'RRUFF Reference: general search of "downs"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs',
                ),
                array(35,36,49,66,68),
                false
            ],
            'IMA List: general search of "downs"' => [
                array(
                    'dt_id' => 2,
                    'gen' => 'downs',
                ),
                array(94,97),
                false
            ],
            'IMA List: general search of "downs", including non-public records' => [
                array(
                    'dt_id' => 2,
                    'gen' => 'downs',
                ),
                array(91,94,97),
                true
            ],
            'RRUFF Sample: general search of "downs"' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs',
                ),
                array(
                    98,    // Abelsonite
                    101,111,113,114,117,119,120,123,127,129,130,136,139,    // Aegirine
                    99,100,103,105,106,107,109,110,112,116,118,125,128,131,134,135,138    // Anorthite
                ),
                true
            ],

            // ----------------------------------------
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

            // ----------------------------------------
            // Searches involving nulls and the empty string
            'IMA List: mineral_aliases is blank, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '19' => '""',
                ),
                array(91,93,95,96,97),
                true
            ],
            'IMA List: mineral_aliases is not blank, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '19' => '!""',
                ),
                array(92,94),
                true
            ],

            'Graph Test: records with files, with permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '!""',
                ),
                array(305,306,307,308,309,310,311,312,313,315,317,320,321),
                true
            ],
            'Graph Test: records with files, but without permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '!""',
                ),
                array(305,306,307,308,309,310,311,312,313,315,317,321),  // 320 has a non-public file they shouldn't know about
                false
            ],
            'Graph Test: records without files, with permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '""',
                ),
                array(318,319),
                true
            ],
            'Graph Test: records without files, without permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '""',
                ),
                array(318,320),  // 319 is non-public, and 320 has a non-public file they shouldn't know about
                false
            ],

            'Graph Test: files containing "csv"' => [
                array(
                    'dt_id' => 9,
                    '59' => 'csv',
                ),
                array(305,306,308,317,321),
                true
            ],
            'Graph Test: no files AND "csv"' => [
                array(
                    'dt_id' => 9,
                    '59' => '"" csv',
                ),
                array(),
                true
            ],

            'Graph Test: no files OR csv, with permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '"" OR csv',
                ),
                array(305,306,308,317,318,319,321),
                true
            ],
            'Graph Test: no files OR csv, without permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '"" OR csv',
                ),
                array(305,306,308,317,318,320),  // 319 is non-public, but does include 320 or 321 because they don't know about the file
                false
            ],

            'Graph Test: no files OR txt, without permissions' => [
                array(
                    'dt_id' => 9,
                    '59' => '"" OR txt',
                ),
                array(307,310,311,312,313,315,318,320,321),
                false
            ],

            // ----------------------------------------
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

            // ----------------------------------------
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

            // ----------------------------------------
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

            // ----------------------------------------
            // mixing general and advanced searches
            'RRUFF Reference: general search of "downs" and authors contains "d"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs',
                    '1' => 'd',
                ),
                array(35,36,49,66,68),    // should be same as "gen" = "downs", obviously
                false
            ],
            'RRUFF Reference: general search of "downs" and authors contains "f"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs',
                    '1' => 'f',
                ),
                array(36,49,66),
                false
            ],
            'RRUFF Reference: general search of "downs" and journal contains "mineral"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs',
                    '3' => 'mineral',
                ),
                array(35,49,66,68),
                false
            ],

            'IMA List: general search of "downs" and mineral_name contains "t", including non-public records' => [
                array(
                    'dt_id' => 2,
                    'gen' => 'downs',
                    '17' => "t",
                ),
                array(91,97),
                true
            ],

            'RRUFF Sample: general search of "downs" and authors contains "f", including non-public records' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs',
                    '1' => 'f',
                ),
                array(
                    98,    // Abelsonite...record 1 fulfills authors: "f", while record 35 fulfills gen: "downs"
                    101,111,113,114,117,119,120,123,127,129,130,136,139,    // Aegirine
                    99,100,103,105,106,107,109,110,112,116,118,125,128,131,134,135,138    // Anorthite
                ),
                true
            ],
            'RRUFF Sample: general search of "downs" and mineral_name contains "t", including non-public records' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs',
                    '17' => 't',
                ),
                array(
                    98,    // Abelsonite
//                    101,111,113,114,117,119,120,123,127,129,130,136,139,    // Aegirine
                    99,100,103,105,106,107,109,110,112,116,118,125,128,131,134,135,138    // Anorthite
                ),
                true
            ],

            // ----------------------------------------
            // More complicated general searches
            'RRUFF Reference: general search of "downs mineral"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs mineral',
                ),
                array(35,49,66,68),
                false
            ],
            'RRUFF Reference: general search of "\"downs mineral\""' => [
                array(
                    'dt_id' => 1,
                    'gen' => '"downs mineral"',
                ),
                array(),    // no field has "downs mineral" in it at the same time
                false
            ],
            'RRUFF Reference: general search of "\"downs hazen\""' => [
                array(
                    'dt_id' => 1,
                    'gen' => '"downs hazen"',
                ),
                array(),    // authors have "downs" and "hazen" individually, but not the string "downs hazen"
                false
            ],
/*
            'RRUFF Reference: general search of "abelsonite"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'abelsonite',
                ),
                array(1,35,63,83),
                false
            ],
            'RRUFF Reference: general search of "american"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'american',
                ),
                array(2,6,7,8,9,17,25,27,31,32,35,43,49,58,60,61,64,66,68,71,72,75,79,81,82,83),
                false
            ],
*/
            'RRUFF Reference: general search of "abelsonite OR american"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'abelsonite OR american',
                ),
                array(
                    1,35,63,83,
                    2,6,7,8,9,17,25,27,31,32,43,49,58,60,61,64,66,68,71,72,75,79,81,82,
                ),
                false
            ],

            'IMA List: general search of "downs mineral"' => [
                array(
                    'dt_id' => 2,
                    'gen' => 'downs mineral',
                ),
                array(94,97),
                false
            ],
/*
            'RRUFF Reference: general search of "532"' => [
                array(
                    'dt_id' => 1,
                    'gen' => '532',
                ),
                array(24),
                false
            ],
            'IMA List: general search of "532"' => [
                array(
                    'dt_id' => 2,
                    'gen' => '532',
                ),
                array(94),    // Aegirine
                false
            ],
            'RRUFF Sample: general search of "532"' => [
                array(
                    'dt_id' => 3,
                    'gen' => '532',
                ),
                array(
                    // Samples of Aegirine
                    101,114,117,127,130,136,
                    // Samples with 532 spectra
                    98,100,102,103,105,107,109,115,116,118,
                    124,125,126,131,133,137,
                    // Samples of Aegirine with 532 spectra
                    111,113,119,120,123,129,139,
                ),
                false
            ],
*/
            'RRUFF Sample: general search of "downs OR 532", including non-public records' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs OR 532',
                ),
                array(
                    // Abelsonite
                    98,
                    // Aegirine
                    101,111,113,114,117,119,120,123,127,129,
                    130,136,139,
                    // Anorthite
                    99,100,103,105,106,107,109,110,112,116,
                    118,125,128,131,134,135,138,
                    // Adelite
                    102,115,
                    // Bournonite
                    124,126,
                    // Amesite
                    133,137,
                ),
                true
            ],
            'RRUFF Sample: general search of "downs 532", including non-public records' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs 532',
                ),
                array(
                    // Samples need to have "downs" somewhere, and have "532" somewhere

                    // Samples of Abelsonite, with 532 wavelength
                    98,
                    // Samples of Aegirine, with 532 wavelength
                    111,113,119,120,123,129,139,
                    // Samples of Anorthite, with 532 wavelength
                    100,103,105,107,109,116,118,125,131,

                    // (The remaining) Samples of Aegirine, with 532 from pages in rruff reference
                    101,114,117,127,130,136,
                ),
                true
            ],

            // ----------------------------------------
            // Searches to catch issues caused by a situation where C links to B, B links to A, and C also links to A
            'RRUFF Sample: authors contains "ross"' => [
                array(
                    'dt_id' => 3,
                    '1' => 'ross',    // results in 73 and 77
                ),
                array(
                    // Aegirine
                    101,111,113,114,117,119,120,123,127,129,
                    130,136,139,
                    // Anorthite
                    99,100,103,105,106,107,109,110,112,116,
                    118,125,128,131,134,135,138,

                    // 107 also links to 77
                ),
                false
            ],
            'RRUFF Sample: general search of "ross"' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'ross',    // results in 73 and 77 from references, and 99, 106, 128 from rruff sample
                ),
                array(
                    // Aegirine
                    101,111,113,114,117,119,120,123,127,129,
                    130,136,139,
                    // Anorthite
                    99,100,103,105,106,107,109,110,112,116,
                    118,125,128,131,134,135,138,

                    // 107 also links to 77
                ),
                false
            ],
            'RRUFF Sample: general search of "asdf"' => [
                array(
                    'dt_id' => 3,
                    '1' => 'asdf',
                ),
                array(),
                false
            ],

            // ----------------------------------------
            // Searches where a descendant returns no results
            'RRUFF Reference: search for non-public records' => [
                array(
                    'dt_id' => 1,
                    'dt_1_pub' => 0,
                ),
                array(),    // should return no results
                true
            ],

            'IMA List: search for minerals with non-public references' => [
                array(
                    'dt_id' => 2,
                    'dt_1_pub' => 0,
                ),
                array(),    // should return no results, because all references are public
                true
            ],
            'IMA List: search for minerals with non-public references and mineral_display_name !== ""' => [
                array(
                    'dt_id' => 2,
                    'dt_1_pub' => 0,
                    '18' => '!""',
                ),
                array(),    // should also return no results, despite the other part of the search matching all minerals
                true
            ],

            'RRUFF Sample: search for minerals with non-public references and mineral_display_name !== ""' => [
                array(
                    'dt_id' => 2,
                    'dt_1_pub' => 0,
                    '18' => '!""',
                ),
                array(),    // should also return no results, despite the other part of the search returning results
                true
            ],
            'RRUFF Sample: search for minerals with non-public references and rruff_id !== ""' => [
                array(
                    'dt_id' => 2,
                    'dt_1_pub' => 0,
                    '30' => '!""',
                ),
                array(),    // should also return no results, despite the other part of the search returning results
                true
            ],
        ];
    }

    /**
     * @return array
     */
    public function provideSearchParamsCompleteDatarecordList()
    {
        return [
            // ----------------------------------------
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
                range(1, 97),    // all RRUFF Reference records, plus the 7 IMA List records
                true
            ],
            'RRUFF Sample: default search, including non-public records' => [
                array(
                    'dt_id' => 3
                ),
                range(1, 295),    // all RRUFF Reference records, plus the 7 IMA List records, plus the 42 RRUFF Sample records, plus the 156 Raman Spectra records
                true
            ],
            'RRUFF Sample: wavelength = "999"' => [
                array(
                    'dt_id' => 3,
                    '41' => '999',
                ),
                array(),
                true
            ],

            'RRUFF Reference: authors containing "downs", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '1' => "downs"
                ),
                array(35,36,49,66,68),
                true
            ],
            'IMA List: authors containing "downs", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '1' => "downs"
                ),
                array(
                    35,36,49,66,68,    // from RRUFF Reference
                    91,94,97           // from IMA List
                ),
                true
            ],
            'RRUFF Sample: authors containing "downs", including non-public records' => [
                array(
                    'dt_id' => 3,
                    '1' => "downs"
                ),
                array(
                    // from RRUFF Reference
                    35,36,49,66,68,
                    // from IMA List
                    91,94,97,
                    // from RRUFF Sample
                    98,127,114,139,101,111,130,113,136,120,
                    117,123,119,129,125,110,107,134,128,100,
                    118,131,116,105,138,109,99,135,103,106,
                    112,
                    // from Raman Spectra
                    140,250,265,283,143,151,156,161,171,178,
                    181,183,184,185,196,199,203,213,219,230,
                    240,260,261,266,267,269,271,272,279,286,
                    287,282,284,187,141,146,147,152,153,160,
                    164,169,170,172,173,182,193,200,205,209,
                    211,223,228,233,237,238,239,248,249,252,
                    289,174,243,159,176,180,192,208,210,215,
                    224,227,231,235,247,256,258,270,275,288,
                    293,295,194,291,177,290,148,276,264,294,
                    245,263,226,234,166,204,232,175,278,197,
                    218,149,154,157,158,163,195,201,206,207,
                    216,220,221,225,236,241,262,268,280,285,
                ),
                true
            ],

            'RRUFF Reference: general search of "downs"' => [
                array(
                    'dt_id' => 1,
                    'gen' => 'downs',
                ),
                array(35,36,49,66,68),
                false
            ],
            'IMA List: general search of "downs"' => [
                array(
                    'dt_id' => 2,
                    'gen' => 'downs',
                ),
                array(
                    // from IMA List, Abelsonite (91) is non-public
                    /*91,*/94,97,
                    // from RRUFF Reference, all references linked to by Aegirine (94) and Anorthite (97)
                    3,7,8,10,15,16,20,21,24,27,
                    28,29,30,31,32,33,36,38,39,40,
                    42,43,44,45,46,47,48,49,50,51,
                    52,53,55,58,60,61,64,65,66,68,
                    69,70,71,72,73,76,77,78,79,81,
                    82,85,88,90,
                ),
                false
            ],
            'RRUFF Sample: general search of "downs"' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs',
                ),
                array(
                    // from IMA List, Abelsonite (91) is non-public
                    /*91,*/94,97,
                    // from RRUFF Reference, all references linked to by Aegirine (94) and Anorthite (97)
                    3,7,8,10,15,16,20,21,24,27,
                    28,29,30,31,32,33,36,38,39,40,
                    42,43,44,45,46,47,48,49,50,51,
                    52,53,55,58,60,61,64,65,66,68,
                    69,70,71,72,73,76,77,78,79,81,
                    82,85,88,90,
                    // from RRUFF Sample, (98) is Abelsonite's sample
                    /*98,*/127,114,139,101,111,130,113,136,120,
                    117,123,119,129,125,110,107,134,128,100,
                    118,131,116,105,138,109,99,135,103,106,
                    112,
                    // from Raman Spectra
                    /*140,250,*/265,283,143,151,156,161,171,178,    // (140) and (250) are Abelsonite's spectra
                    181,183,184,185,196,199,203,213,219,230,
                    240,260,261,266,267,269,271,272,279,286,
                    287,282,284,187,141,146,147,152,153,160,
                    164,169,170,172,173,182,193,200,205,209,
                    211,223,228,233,237,238,239,248,249,252,
                    289,174,243,159,176,180,192,208,210,215,
                    224,227,231,235,247,256,258,270,275,288,
                    293,295,194,291,177,290,148,276,264,294,
                    245,263,226,234,166,204,232,175,278,197,
                    218,149,154,157,158,163,195,201,206,207,
                    216,220,221,225,236,241,262,268,280,285,
                ),
                false
            ],

            'RRUFF Sample: general search of "downs" and wavelength = "532"' => [
                array(
                    'dt_id' => 3,
                    'gen' => 'downs',
                    '41' => '532',
                ),
                array(
                    // from IMA List, Abelsonite (91) is non-public
                    /*91,*/94,97,
                    // from RRUFF Reference, all references linked to by Aegirine (94) and Anorthite (97)
                    3,7,8,10,15,16,20,21,24,27,
                    28,29,30,31,32,33,36,38,39,40,
                    42,43,44,45,46,47,48,49,50,51,
                    52,53,55,58,60,61,64,65,66,68,
                    69,70,71,72,73,76,77,78,79,81,
                    82,85,88,90,
                    // from RRUFF Sample...the commented ones belong to minerals other than Aegirine/Anorthite, or have no Raman spectra
                    /*98,*/100,/*102,*/103,105,107,109,111,113,/*115,*/
                    116,118,119,120,123,/*124,*/125,/*126,*/129,131,
                    /*133,*//*137,*/139,
                    // from Raman Spectra...the commented ones belong to minerals other than Aegirine/Anorthite
                    /*140,*//*150,*//*155,*/156,/*165,*//*167,*/175,176,/*179,*/204,
                    218,/*222,*/234,236,243,249,263,/*273,*/276,/*281,*/
                    282,283,290,291,294,
                ),
                false
            ],
        ];
    }
}
