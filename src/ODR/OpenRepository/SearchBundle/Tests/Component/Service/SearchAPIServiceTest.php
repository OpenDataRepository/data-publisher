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
        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            $this->markTestSkipped('Wrong database');

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
        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            $this->markTestSkipped('Wrong database');

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
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performSearch
     * @dataProvider provideInverseSearchParams
     */
    public function testInverseSearch($search_params, $expected_grandparent_ids, $search_as_super_admin)
    {
        exec('redis-cli flushall');
        $client = static::createClient();
        if ( $client->getContainer()->getParameter('database_name') !== 'odr_theta_2' )
            $this->markTestSkipped('Wrong database');

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
                array(91,92,93,94,95,96,97,322),
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
            'IMA List: general search of "downs", not including descendants' => [
                array(
                    'dt_id' => 2,
                    'gen_lim' => 'downs',
                ),
                array(),    // None of the fields directly belonging to IMA have "downs" in them, so there will be no results
                false
            ],
            'IMA List: general search of "downs", including descendants' => [
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
            'RRUFF Reference: article title containing "abelsonite", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "abelsonite"
                ),
                array(1,35,63,83),
                true
            ],
            'RRUFF Reference: article title containing "structure", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "structure",
                ),
                array(1,4,6,7,10,12,23,25,35,46,65,69,72,82,85,87,89),
                true
            ],
            'RRUFF Reference: article title containing "abelsonite" AND "structure", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "abelsonite structure"
                ),
                array(1,35),
                true
            ],
            'RRUFF Reference: article title containing "abelsonite" OR "structure" (variant 1), including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "abelsonite OR structure",
                ),
                array(1,4,6,7,10,12,23,25,35,46,63,65,69,72,82,83,85,87,89),
                true
            ],
            'RRUFF Reference: article title containing "abelsonite" OR "structure" (variant 2), including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "abelsonite || structure"
                ),
                array(1,4,6,7,10,12,23,25,35,46,63,65,69,72,82,83,85,87,89),
                true
            ],
            'RRUFF Reference: article title containing "abelsonite" OR "structure" (variant 3), including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "abelsonite, structure",
                ),
                array(1,4,6,7,10,12,23,25,35,46,63,65,69,72,82,83,85,87,89),
                true
            ],
            'RRUFF Reference: article title containing the lteral string "abelsonite, the", including non-public records' => [
                array(
                    'dt_id' => 1,
                    '2' => "\"abelsonite, the\"",
                ),
                array(35),
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
            'IMA List: records where the tag "Grandfathered" is selected, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19",
                ),
                array(92,93,96,97),
                true
            ],
            'IMA List: records where the tag "Abiotic" is selected, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "41",
                ),
                array(92,93,94,97),
                true
            ],
            'IMA List: records where the tags "Grandfathered" and "Abiotic" are both selected, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,41",    // field is set to merge_by_AND by default, so no prefix needed
                ),
                array(92,93,97),
                true
            ],
            'IMA List: records with the tag "Grandfathered" and AT LEAST ONE OF the tags "Abiotic" (yes, only one tag in the category), including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,~41",    // ...however, one of them having the prefix shouldn't change the results, as it's in the "AT LEAST ONE OF" category by itself
                ),
                array(92,93,97),
                true
            ],
            'IMA List: records with AT LEAST ONE OF the tags "Grandfathered" or "Abiotic", including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "~19,~41",    // ...both of them need to have the prefix to do a proper merge_by_OR
                ),
                array(92,93,94,96,97),
                true
            ],
            'IMA List: records where the tag "Grandfathered" selected and the tag "Abiotic" is not selected, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "19,-41",
                ),
                array(96),
                true
            ],

            'IMA List: records where the tag "Fleischers Glossary 2008" is selected, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '28' => "16",
                ),
                array(92,93,94,96,97),
                true
            ],

            // these two general searches need to hit the biotic/abiotic tag names
            'IMA List: records with the string "biotic", including non-public records' => [
                array(
                    'dt_id' => 2,
                    'gen' => "biotic",    // will also match "abiotic"
                ),
                array(91,92,93,94,96,97,),
                true
            ],
            'IMA List: records with the string "\"biotic\"", including non-public records' => [
                array(
                    'dt_id' => 2,
                    'gen' => "\"biotic\"",
                ),
                array(91,96),
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
                array(91,93,95,96,97,322),
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

            'IMA List: records with dates, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '64' => '!""'
                ),
                array(91,92,95),
                true
            ],
            'IMA List: records without dates, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '64' => '""'
                ),
                array(93,94,96,97,322),    // NOTE: 93 was set to have a date, then cleared...the database stores the "empty" value as 9999-12-31
                true
            ],

            'IMA List: records with date on or after 1805-06-30, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '64_s' => '1805-06-30'
                ),
                array(91,92,95),    // 92 is included because 'starting from 1805-06-30' includes the date
                true
            ],
            'IMA List: records with date before 2017-10-01, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '64_e' => '2017-10-01'
                ),
                array(91,92/*,95*/),    // 95 is excluded because 'ending before 2017-10-01' excludes the date
                                        // NOTE: this is only due to an adjustment by SearchKeyService::convertSearchKeyToCriteria()...ODR currently only stores the date, not the time
                true
            ],
            'IMA List: records with date between 1805-06-30 and 2017-10-01, including non-public records' => [
                array(
                    'dt_id' => 2,
                    '64_s' => '1805-06-30',
                    '64_e' => '2017-10-01',
                ),
                array(91,92,95),    // 95 is included because users expect to include the later date when both exist
                true
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
            // inequality gotchas
            'RRUFF Reference: article_title containing "<"' => [
                array(
                    'dt_id' => 1,
                    '2' => '<'
                ),
                array(56),
                false
            ],
            'RRUFF Reference: article_title containing ">"' => [
                array(
                    'dt_id' => 1,
                    '2' => '>'
                ),
                array(56),
                false
            ],

            'RRUFF Reference: article_title containing "<i>"' => [
                array(
                    'dt_id' => 1,
                    '2' => '<i>'
                ),
                array(56),   // this test is the entire point of this block...need to confirm that the parser can guess it's seeing HTML tags
                false
            ],

            'RRUFF Reference: article_title containing "<i"' => [
                array(
                    'dt_id' => 1,
                    '2' => '<i'    // this should trigger the conventional inequality stuff...though the test is of limited value since the underlying field is a string
                ),
                array(
                    6,53,54,60,69,75,83,84,85,88,   // starts with 'a'
                    26,                             // starts with 'b'
                    2,5,10,21,28,35,49,57,59,73,    // starts with 'c'
                    40,52,80,                       // starts with 'd'
                    44,48,77,                       // starts with 'e'
                    14,15,20,27,45,                 // starts with 'f'
                                                    // starts with 'g'
                    8,11,                           // starts with 'h'
                    78,                             // starts with '['
                ),
                false
            ],
            'RRUFF Reference: article_title containing "<i >g"' => [
                array(
                    'dt_id' => 1,
                    '2' => '<i >g'
                ),
                array(8,11),    // this should only get references with an article title that start with an 'h' or 'H'...but not trigger the title containing '<i>'
                false
            ],
            'RRUFF Reference: article_title containing ">g <i"' => [
                array(
                    'dt_id' => 1,
                    '2' => '>g <i'
                ),
                array(8,11),
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

            'IMA List: search for minerals without a reference' => [
                array(
                    'dt_id' => 2,
                    '1' => '""',
                ),
                array(322),    // should return one result, the only IMA mineral without a linked reference
                true
            ],
            'IMA List: search for minerals with author == "downs" OR minerals without a reference' => [
                array(
                    'dt_id' => 2,
                    '1' => 'downs OR ""',
                ),
                array(91,94,97,322),    // should return the three minerals referred to by "downs" and the only IMA mineral without a linked reference
                true
            ],
            'IMA List: search for minerals with author == "downs" AND minerals without a reference' => [
                array(
                    'dt_id' => 2,
                    '1' => 'downs AND ""',
                ),
                array(),    // should return nothing because it's impossible to match
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
                    'dt_id' => 3,
                    'dt_1_pub' => 0,
                    '18' => '!""',
                ),
                array(),    // should also return no results, despite the other part of the search returning results
                true
            ],
            'RRUFF Sample: search for minerals with non-public references and rruff_id !== ""' => [
                array(
                    'dt_id' => 3,
                    'dt_1_pub' => 0,
                    '30' => '!""',
                ),
                array(),    // should also return no results, despite the other part of the search returning results
                true
            ],

            // ----------------------------------------
            // IMPORTANT: while you might expect these next two tests to behave similarly to
            //  the two that are were run on the IMA List, you would be sorely mistaken.

            // ODR quasi-intentionally obsfucates searching in situations in which there are multiple
            // "paths" to reach a descendant...
            // e.g. "Samples" links to "Mineral", "Mineral" links to "References", "Samples" also links to "References"
            // ...the search sidebar UI would need to be modified to display a hierarchy and inform
            //  the user why it's different, and SearchAPIService::getSearchArrays() would have to
            //  create/store two copies of the "Reference" records, SearchAPIService::mergeSearchResults()
            //  would have to differentiate which copy of the records matched the search query, and
            //  the merging would also have to differentiate between the different sets of records
            //  ...that's obviously a serious pain in the ass to code, and that's before you have to
            //  explain to a user who isn't *expecting* such a drastic distinction why they have to
            //  jump through hoops instead of having it just work.
            'RRUFF Sample: reference author == ""' => [
                // Without the ability to differentiate between which "path" you want, this is
                //  actually asking for the rruff samples which aren't linked to a reference, or
                //  for the rruff samples linked to a mineral that aren't linked to a reference

                // This clearly isn't something that's terribly useful to know, but this is the price
                //  paid for allowing queries like '"RRUFF Sample: reference author == "downs"' to
                //  return results from both "paths" so the query works as *expected*.  Whee.
                array(
                    'dt_id' => 3,
                    '1' => '""',
                ),
                array_merge(
                    array_diff(
                        range(98,139),  // the rruff samples range from 98 to 139...
                        array(107,126)  // ...but 107 and 126 won't match the query since they're the only ones with references of their own
                                        // NOTE: 126 is a Bournonite sample, but has a ref from Abelsonite specifically to make an inverse search test work
                    ),
                    array(323)         // ...also need the rruff sample 323, since it links to the mineral 322 which has no references
                ),
                true
            ],
            'RRUFF Sample: search for samples where minerals with author == "downs" AND minerals without a reference' => [
                array(
                    'dt_id' => 3,
                    '1' => 'downs AND ""',
                ),
                array(),    // should return nothing because it's impossible to match
                true
            ],


            // ----------------------------------------
            'AMCSD test: silly precision search' => [
                array(
                    'dt_id' => 16,
                    '66' => '(5.6984,)',
                ),
                array(324),
                true
            ],

            'AMCSD test: search with one range' => [
                array(
                    'dt_id' => 16,
                    '66' => '(>2.81 < 2.83,)',    // want records with an x between 2.81 and 2.83, no constraint on y
                ),
                array(325,326,328),
                true
            ],
            'AMCSD test: search with two ranges' => [
                array(
                    'dt_id' => 16,
                    '66' => '(>2.81 < 2.83,)|(>5.63 <5.65,)',    // want records with 1) an x between 2.81 and 2.83 and 2) an x between 5.63 and 5.65
                ),
                array(326),
                true
            ],

            'AMCSD test: search with both x/y that should work' => [
                array(
                    'dt_id' => 16,
                    '66' => '(>5.6 <5.65,>30)',
                ),
                array(325,326,327),
                true
            ],
            'AMCSD test: search with both x/y that should return nothing' => [
                array(
                    'dt_id' => 16,
                    '66' => '(>5.6 <5.65,>50)',
                ),
                array(),    // the y value is between 30 and 35 for the previous matches, so nothing should match here
                true
            ],
            'AMCSD test: search with separate x/y' => [
                array(
                    'dt_id' => 16,
                    '66' => '(>5.6 <5.65,)|(,>50)',
                ),
                array(325,326,327),    // all three have a different point with a y value of 100, so they should all match again
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
                array_merge( range(1, 97), array(322) ),    // all RRUFF Reference records, plus the 8 IMA List records
                true
            ],
            'RRUFF Sample: default search, including non-public records' => [
                array(
                    'dt_id' => 3
                ),
                array_merge( range(1, 295), array(322,323) ),    // all RRUFF Reference records, plus the 8 IMA List records, plus the 43 RRUFF Sample records, plus the 156 Raman Spectra records
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

    /**
     * @return array
     */
    public function provideInverseSearchParams()
    {
        /*
         * These tests are for an "Inverse" search...the underlying database has these relations:
         * RRUFF Sample
         *  - IMA Mineral (linked to RRUFF Sample)
         *     - RRUFF Reference (linked to IMA Mineral)
         *  - RRUFF Reference (linked to RRUFF Sample)
         *  - Raman Spectra (child of RRUFF Sample)
         *
         * ...but an "inverse" search runs the search with these relations instead:
         * RRUFF Reference
         *  - IMA Mineral (links to RRUFF Reference)
         *     - RRUFF Sample (links to IMA Mineral)
         *        - Raman Spectra (child of RRUFF Sample)
         *  - RRUFF Sample (links to RRUFF Reference)
         *     - Raman Spectra (child of RRUFF Sample)
         *
         * ...with the "inverse" flag set, the search system should properly set up the various arrays
         * so the actual logic doesn't even know the difference.
         *
         * That being said, the further "away" from the source that you get (e.g. searching for
         * references based on sample wavelength)...the returned results will quickly start requiring
         * extended investigation to figure out why they actually match.
         */

        return [
            // ----------------------------------------
            'RRUFF Reference: inverse search towards RRUFF Reference, including non-public records' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 1,    // targetting RRUFF Reference should work
                ),
                range(1, 90),
                true
            ],
            'RRUFF Reference: inverse search towards IMA List, including non-public records' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 2,    // targetting IMA List should still return all references
                ),
                range(1, 90),
                true
            ],

            'RRUFF Reference: inverse search, references with the mineral_name "Bournonite"' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 2,    // target IMA list
                    '18' => "Bournonite",
                ),
                array(5,13,22,26,34,41,80),
                true
            ],
            'RRUFF Reference: inverse search, references with the rruff_id "R050111"' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 3,    // target RRUFF Sample
                    '30' => "R050111",
                ),
                array(5,13,22,26,34,41,80),    // should be no difference from previous...this sample doesn't link to a reference
                true
            ],
            'RRUFF Reference: inverse search, references with the rruff_id "R050364"' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 3,    // target RRUFF Sample
                    '30' => "R050364",
                ),
                array(5,13,22,26,34,41,80, 1),  // should have the Bournonite references, plus the one linked to directly by this sample
                                                // NOTE: 126 is a Bournonite sample, but has a ref from Abelsonite specifically to make this inverse search test work
                true
            ],

            'RRUFF Reference: inverse search, references with a mineral_name "Abelsonite" and a wavelength "532"' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 3,    // targetting RRUFF Sample should also get IMA List
                    '18' => "Abelsonite",
                    '41' => "532",
                ),
                array(1,9,35,63,83, 77),    // the five Abelsonite references, plus dr_id 77 because "R050104" (dr_id 107) directly links to it...
                                            // ...the reason being that R050104 has a 532 spectra, so "R050104" matches, so dr_id 77 matches
                true
            ],
            'RRUFF Reference: inverse search, references with a article_title of "Abelsonite" and a wavelength "532"' => [
                array(
                    'dt_id' => 1,
                    'inverse' => 3,    // targetting RRUFF Sample should also get IMA List
                    '2' => "Abelsonite",
                    '41' => "532",
                ),
                array(1,35,63,83),  // should only have the four references that directly mention "abelsonite", despite "532" matching pretty much every RRUFF Sample...
                                    // ...9 and 77 shouldn't match due to the article_title
                true
            ],
        ];
    }
}
