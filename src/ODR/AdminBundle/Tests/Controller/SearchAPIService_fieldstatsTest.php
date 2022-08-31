<?php

/**
 * Open Data Repository Data Publisher
 * Search API Service Test, field_stats
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Tests specific to template searching and fieldstats
 */

namespace ODR\OpenRepository\SearchBundle\Tests\Component\Service;

// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchKeyService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class SearchAPIServiceTest_fieldstatsTest extends WebTestCase
{
    /**
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performTemplateSearch
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
        $search_results = $search_api_service->performTemplateSearch($search_key, array(), $search_as_super_admin);

        $this->assertEqualsCanonicalizing( $expected_grandparent_ids, $search_results['grandparent_datarecord_list'] );
    }

    /*
     * select mdt.id as mdt_id, mdt.grandparent_id, mdt.unique_id, dt.id as dt_id, df.id as df_id, df.template_field_uuid, dr.id as dr_id, e.value
     * from odr_data_type mdt
     * left join odr_data_fields mdf on mdf.data_type_id = mdt.id
     * left join odr_data_fields df on df.master_datafield_id = mdf.id
     * left join odr_data_fields_meta dfm on dfm.data_field_id = df.id
     * left join odr_data_type dt on df.data_type_id = dt.id
     * left join odr_data_record_fields drf on drf.data_field_id = df.id
     * left join odr_data_record dr on drf.data_record_id = dr.id
     * left join odr_long_varchar e on e.data_record_fields_id = drf.id
     * where mdt.is_master_type = 1 AND df.template_field_uuid = "08088a9"
     * and mdt.deletedat is null and mdf.deletedat is null
     * and dt.deletedat is null and df.deletedat is null and dfm.deletedat is null
     * and drf.deletedat is null and dr.deletedat is null and e.deletedat is null
     * order by mdt.id, mdf.id, dt.id, df.id;
     */

    /**
     * @return array
     */
    public function provideSearchParams()
    {
        return [
            // Sanity check searches
            'Properties: Dataset Name includes "ch", including non-public records' => [
                array(
                    'template_uuid' => '2ea627b',
                    'fields' => array(
                        array(
                            'template_field_uuid' => '08088a9',
                            'value' => 'ch',
                        )
                    ),
                ),
                array(172722,178273,178928),
                true
            ],
            'Properties: Longitude > 0, including non-public records' => [
                array(
                    'template_uuid' => '2ea627b',    // Properties
                    'fields' => array(
                        array(
                            'template_field_uuid' => 'c70af540928e93e1fe277d1dd46d',    // Fieldwork Location NEW -> Longitude
                            'value' => '> 0',    // should return 3 results
                        )
                    ),
                ),
                array(178732,178946,178953),
                true
            ],
            'Person: Contact Email includes "test", including non-public records' => [
                array(
                    'template_uuid' => 'ce17e42',
                    'fields' => array(
                        array(
                            'template_field_uuid' => 'e3dcbc9',
                            'value' => 'test',
                        )
                    ),
                ),
                array(162502,168859,174617,174869,174852,178962,177010),
                true
            ],

            'Institution: general search of "institution", including non-public records' => [
                array(
                    'template_uuid' => '870a2f7',
                    'general' => 'institution',
                ),
                array(165422,175999,176006,176014,176022),
                true
            ],
            'Person: general search of "institution", including non-public records' => [
                array(
                    'template_uuid' => 'ce17e42',
                    'general' => 'institution',
                ),
                array(176013,176021),
                true
            ],

            'Properties: general search of "institution", including non-public records' => [
                array(
                    'template_uuid' => '2ea627b',
                    'general' => 'mineralogy',
                    'fields' => array(
                        array(
                            'template_field_uuid' => '08088a9',
                            'value' => 'database'
                        )
                    )
                ),
                array(178273,178689),
                true
            ],
        ];
    }


    /**
     * @covers \ODR\OpenRepository\SearchBundle\Component\Service\SearchAPIService::performTemplateSearch
     */
    public function testFieldStats()
    {
        $client = static::createClient();

        /** @var SearchAPIService $search_api_service */
        $search_api_service = $client->getContainer()->get('odr.search_api_service');
        /** @var SearchKeyService $search_key_service */
        $search_key_service = $client->getContainer()->get('odr.search_key_service');

        $search_params = array(
            "template_uuid" => '2ea627b',    // Properties
//                "field_stats" => 'a8002dc54ef1b3a27517b6e893b2',
            "field_stats" => '979523a',
        );

        // Convert each array of search params into a search key, then run the search
        $search_key = $search_key_service->encodeSearchKey($search_params);
        $results = $search_api_service->performTemplateSearch($search_key, array(), true);

        $this->assertArrayHasKey('records', $results);

        $labels = $results['labels'];
        $records = $results['records'];

        // Translate the two provided arrays into a a slightly different format
        $data = array();
        foreach ($records as $dt_id => $df_list) {
            foreach ($df_list as $df_id => $dr_list) {
                foreach ($dr_list as $dr_id => $item_list) {
                    foreach ($item_list as $num => $item_uuid) {
                        $item_name = $labels[$item_uuid];
                        if (!isset($data[$item_name])) {
                            $data[$item_name] = array(
                                'count' => 0,
                                'uuid' => $item_uuid
                            );
                        }

                        $data[$item_name]['count']++;
                    }
                }
            }
        }

        $this->assertArrayHasKey('Characterizing Environments for Habitability and Biosignatures', $data);
        $this->assertEquals(12, $data['Characterizing Environments for Habitability and Biosignatures']['count']);
    }
}
