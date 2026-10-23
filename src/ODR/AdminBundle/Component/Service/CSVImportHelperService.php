<?php

/**
 * Open Data Repository Data Publisher
 * CSVImport Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Verifying that a CSV file won't violate uniquness constraints is complicated/irritating, and it's
 * considerably easier to test code that's in a service instead of in a controller...
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
// Services
use ODR\AdminBundle\Component\Utility\ODRCsvReader as CsvReader;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Other
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;


class CSVImportHelperService
{

    /**
     * CSVImportHelperService constructor
     *
     * @param EntityManager $em
     * @param DatarecordInfoService $datarecord_info_service
     * @param DatatreeInfoService $datatree_info_service
     * @param SearchService $search_service
     * @param SortService $sort_service
     * @param string $odr_tmp_directory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManager $em,
        private readonly DatarecordInfoService $datarecord_info_service,
        private readonly DatatreeInfoService $datatree_info_service,
        private readonly SearchService $search_service,
        private readonly SortService $sort_service,
        private readonly string $odr_tmp_directory,
        private readonly LoggerInterface $logger
    ) {

    }


    /**
     * Extracts data from the POST request sent to {@link CSVImportController::startvalidateAction()},
     * so that the rest of the functions in this service can do their job more easily.
     *
     * @param array $post
     * @param array $file_headers can be empty
     * @param string $import_type 'top-level' or 'child' or 'link' or 'child-link'
     *
     * @return array
     */
    public function getUniquenessCheckData($post, $file_headers, $import_type)
    {
        $data = [
            'file_headers' => $file_headers,
            'import_type' => $import_type,

            'grandparent_datatype_id' => null,
            'parent_datatype_id' => null,
            'target_datatype_id' => intval( $post['target_datatype_id'] ),

            'grandparent_external_id_field' => null,
            'parent_external_id_field' => null,
            'target_external_id_field' => null,

            'grandparent_external_id_column' => null,
            'parent_external_id_column' => null,
            'target_external_id_column' => null,

            'grandparent_dr_lookup' => [],
            'parent_dr_lookup' => [],
            'target_dr_lookup' => [],
        ];

        // ----------------------------------------
        // Going to need this
        $repo_datatype = $this->em->getRepository('ODR\AdminBundle\Entity\DataType');

        // Don't need to check whether stuff is valid here
        if ( isset($post['grandparent_datatype_id']) && $post['grandparent_datatype_id'] !== '' ) {
            $data['grandparent_datatype_id'] = intval( $post['grandparent_datatype_id'] );

            /** @var DataType $grandparent_dt */
            $grandparent_dt = $repo_datatype->find( $data['grandparent_datatype_id'] );
            if ( !is_null($grandparent_dt->getExternalIdField()) ) {
                $data['grandparent_external_id_field'] = $grandparent_dt->getExternalIdField()->getId();

                if ( $import_type === 'child-link' ) {
                    // In this case, the grandparent_external_id_column will be its own entry in the post
                    $data['grandparent_external_id_column'] = intval( $post['grandparent_external_id_column'] );
                }
//                else {
//                    // ...otherwise, the column will theoretically be in the datafield mapping
//                    $key = array_search($data['grandparent_external_id_field'], $post['datafield_mapping']);
//                    if ($key !== false)
//                        $data['grandparent_external_id_column'] = $key;
//                }
            }
        }

        if ( isset($post['parent_datatype_id']) && $post['parent_datatype_id'] !== '' ) {
            $data['parent_datatype_id'] = intval( $post['parent_datatype_id'] );

            /** @var DataType $parent_dt */
            $parent_dt = $repo_datatype->find( $data['parent_datatype_id'] );
            if ( !is_null($parent_dt->getExternalIdField()) ) {
                $data['parent_external_id_field'] = $parent_dt->getExternalIdField()->getId();

                if ( $import_type === 'child' || $import_type === 'link' || $import_type === 'child-link' ) {
                    // In these cases, the parent_external_id_column will be its own entry in the post
                    $data['parent_external_id_column'] = intval( $post['parent_external_id_column'] );
                }
                else {
                    // ...otherwise, the column will theoretically be in the datafield mapping
                    $key = array_search($data['parent_external_id_field'], $post['datafield_mapping']);
                    if ($key !== false)
                        $data['parent_external_id_column'] = $key;
                }
            }
        }

        if ( isset($post['target_datatype_id']) && $post['target_datatype_id'] !== '' ) {
            $data['target_datatype_id'] = intval( $post['target_datatype_id'] );

            /** @var DataType $target_dt */
            $target_dt = $repo_datatype->find( $data['target_datatype_id'] );
            if ( !is_null($target_dt->getExternalIdField()) ) {
                $data['target_external_id_field'] = $target_dt->getExternalIdField()->getId();

                if ( $import_type === 'link' || $import_type === 'child-link' ) {
                    // In these cases, the target_external_id_column will be its own entry in the post
                    $data['target_external_id_column'] = intval( $post['target_external_id_column'] );
                }
                else {
                    // ...otherwise, the column will theoretically be in the datafield mapping
                    $key = array_search($data['target_external_id_field'], $post['datafield_mapping']);
                    if ($key !== false)
                        $data['target_external_id_column'] = $key;
                }
            }
        }

        // Want to also create a more direct mapping from the post data, where the column_num of a
        //  column in the CSV file that is marked as unique points to a datafield_id
        $data['unique_mapping'] = [];
        if ( isset($post['unique_columns']) ) {
            foreach ($post['unique_columns'] as $column_num => $num) {
                if ($post['datafield_mapping'][$column_num] === 'new')
                    $data['unique_mapping'][$column_num] = 'new_'.$column_num;
                else
                    $data['unique_mapping'][$column_num] = intval($post['datafield_mapping'][$column_num]);
            }
        }
        // Can't send linked imports through self::getExistingUniqueValues()
//        else if ( $data['import_linked_datarecords'] ) {
//            //
//            $data['unique_mapping'] = array(
//                $data['top_level_external_id_column'] => $data['top_level_external_id_field'],
//                $data['child_external_id_column'] => $data['child_external_id_field'],
//            );
//        }


        // $sort_service->sortDatarecordsByDatafield() returns an array where (dr_id => sort_value)
        // ...the flipped version of this can be used to quickly lookup datarecords based on the
        //  (hopefully unique) values in the CSV File
        if ( !is_null($data['grandparent_external_id_field']) ) {
            $dr_lookup = $this->sort_service->sortDatarecordsByDatafield( $data['grandparent_external_id_field'] );
            $data['grandparent_dr_lookup'] = array_flip($dr_lookup);
        }
        if ( !is_null($data['parent_external_id_field']) ) {
            $dr_lookup = $this->sort_service->sortDatarecordsByDatafield( $data['parent_external_id_field'] );
            $data['parent_dr_lookup'] = array_flip($dr_lookup);
        }
        if ( !is_null($data['target_external_id_field']) ) {
            $dr_lookup = $this->sort_service->sortDatarecordsByDatafield( $data['target_external_id_field'] );
            $data['target_dr_lookup'] = array_flip($dr_lookup);
        }

        // TODO - is anything else needed/useful?

        return $data;
    }


    /**
     * The first step in determining whether the CSV file violates uniqueness constraints is to
     * load all existing values in the relevant datafields...
     *
     * NOTE: this is not used for imports that link records together
     *
     * @param array $data {@see self::getUniquenessCheckData()}
     *
     * @return array
     */
    public function getExistingUniqueValues($data)
    {
        // Don't do anything if called on a 'link' or 'child-link' import
        if ( $data['import_type'] === 'link' || $data['import_type'] === 'child-link' )
            return [];

        // Going to build an array of the existing values in each unique datafield that's getting
        //  imported into
        $existing_values = [];

        // If importing into a child datatype, then need to be able to lookup the id of a top-level
        //  record based on the id of a child record
        $parent_lookup = [];
        if ( $data['import_type'] === 'child' )
            $parent_lookup = $this->search_service->getCachedDatarecordList( $data['target_datatype_id'] );

        // Load the existing values for all existing datafields that are being imported into
        foreach ($data['unique_mapping'] as $column_num => $df_id) {
            if ( is_numeric($df_id) ) {
                // $sort_service->sortDatarecordsByDatafield() returns an array where (dr_id => sort_value)
                // ...this happens to make it quite convenient for building a list of existing values
                //  for text/number-type datafields
                $dr_list = $this->sort_service->sortDatarecordsByDatafield($df_id);

                // For each existing datarecord...
                foreach ($dr_list as $dr_id => $current_value) {
                    if ( $data['import_type'] === 'top-level' ) {
                        // This is a top-level datarecord...
                        if ( !isset($existing_values[$dr_id]) )
                            $existing_values[$dr_id] = [];

                        $existing_values[$dr_id][$df_id] = [
                            'line' => 0,
                            'value' => $current_value,
                        ];
                    }
                    else /*if ( $data['import_type'] === 'child' )*/ {
                        // This is a child datarecord...multiple records of a child datatype are
                        //  allowed to have the same unique value, so long as they have different
                        //  parent records
                        $parent_dr_id = $parent_lookup[$dr_id];
                        if ( !isset($existing_values[$parent_dr_id]) )
                            $existing_values[$parent_dr_id] = [];
                        if ( !isset($existing_values[$parent_dr_id][$dr_id]) )
                            $existing_values[$parent_dr_id][$dr_id] = [];

                        $existing_values[$parent_dr_id][$dr_id][$df_id] = [
                            'line' => 0,
                            'value' => $current_value,
                        ];
                    }
                }
            }
        }

        return $existing_values;
    }


    /**
     * The second step in determining whether the CSV file violates uniqueness constraints is to
     * apply all changes from the CSV file...
     *
     * NOTE: this is not used for imports that link records together
     *
     * @param array $data {@see self::getUniquenessCheckData()}
     * @param CsvReader $reader this needs to be pointing to the beginning of the csv file
     * @param array $future_values {@see self::getExistingUniqueValues()}
     *
     * @return array
     */
    public function getFutureUniqueValues($data, $reader, &$future_values)
    {
        // Don't do anything if called on a 'link' or 'child-link' import
        if ( $data['import_type'] === 'link' || $data['import_type'] === 'child-link' )
            return [];

        // The array from self::getUniquenessCheckData() is more focused on being descriptive than
        //  directly simplifying this check
        $top_level_external_id_column = $top_level_external_id_field = $top_level_lookup = null;
        $child_external_id_column = $child_external_id_field = $child_lookup = null;

        $is_top_level_import = false;
        if ( $data['import_type'] === 'top-level' ) {
            // If the import is into a top-level datatype, then the target_external_id_column in the
            //  file is relevant
            $is_top_level_import = true;

            if ( !is_null($data['target_external_id_column']) ) {
                $top_level_external_id_column = $data['target_external_id_column'];
                $top_level_external_id_field = $data['target_external_id_field'];
                $top_level_lookup = $data['target_dr_lookup'];
            }
        }
        else {
            // If the import is into a child datatype, then these two columns in the file are relevant
            if ( !is_null($data['parent_external_id_column']) ) {
                $top_level_external_id_column = $data['parent_external_id_column'];
                $top_level_external_id_field = $data['parent_external_id_field'];
                $top_level_lookup = $data['parent_dr_lookup'];
            }
            if ( !is_null($data['target_external_id_column']) ) {
                $child_external_id_column = $data['target_external_id_column'];
                $child_external_id_field = $data['target_external_id_field'];
                $child_lookup = $data['target_dr_lookup'];
            }
        }

        // Going to try to detect duplicate entries in the CSV file here, if possible
        $errors = [];

        // The csv file might end up creating new datarecords...they also need to be checked
        $new_dr_num = 0;
        $new_child_dr_num = 0;

        // Don't need to reset the CSV Reader object
        $line_num = 0;
        foreach ($reader as $row) {
            $line_num++;
            // Skip header row
            if ( $line_num === 1 )
                continue;

            // Need to locate the top-level datarecord id that this row points to, if possible
            $dr_id = null;
            $dr_id_value = null;
            if ( !is_null($top_level_external_id_column) ) {
                $dr_id_value = $row[$top_level_external_id_column];

                if ( isset($top_level_lookup[$dr_id_value]) )
                    $dr_id = $top_level_lookup[$dr_id_value];
            }
            // If a top-level datarecord wasn't found, then assign it a "new" id
            if ( is_null($dr_id) ) {
                $new_dr_num++;
                $dr_id = 'new_dr_'.$new_dr_num;

                if ( !is_null($top_level_external_id_column) ) {
                    // When importing into a datatype that has an external id, any new value/dr_id
                    //  pair should get spliced back into the lookup array
                    $top_level_lookup[$dr_id_value] = $dr_id;
                }
            }


            // If importing into a child datatype, then should also attempt to locate its external id
            $child_dr_id = null;
            $child_dr_id_value = null;
            if ( !is_null($child_external_id_column) ) {
                $child_dr_id_value = $row[$child_external_id_column];

                if ( isset($child_lookup[$child_dr_id_value]) )
                    $child_dr_id = $child_lookup[$child_dr_id_value];
            }
            // If a child/linked datarecord wasn't found, then assign it a "new" id
            if ( !$is_top_level_import && is_null($child_dr_id) ) {
                $new_child_dr_num++;
                $child_dr_id = 'new_cdr_'.$new_child_dr_num;

                if ( !is_null($child_external_id_column) ) {
                    // When importing into a child datatype that has an external id, any new
                    //  value/dr_id pair should get spliced back into the lookup array
                    $child_lookup[$child_dr_id_value] = $child_dr_id;
                }
            }


            // Only care about the columns from the CSV file that are going to unique datafields...
            foreach ($data['unique_mapping'] as $column_num => $df_id) {
                $value = trim( (string) $row[$column_num] );

                if ( $is_top_level_import ) {
                    // Overwrite any existing value for this datafield, or just create a new one
                    //  if it doesn't exist
                    if ( !isset($future_values[$dr_id]) )
                        $future_values[$dr_id] = [];

                    // Need to look for duplicate entries in the external id column of the csv file
                    if ( $df_id === $top_level_external_id_field
                        && isset($future_values[$dr_id][$df_id]['line'])
                        && $future_values[$dr_id][$df_id]['line'] !== 0
                    ) {
                        // ...if these duplicates were left alone, then the CSVImport worker would
                        //  receive more than one row of data to update the same record...due to
                        //  randomization of priority on background jobs, there's no guarantee which
                        //  row of data the record would end up using after the import finished
                        $message = 'The external id value "'.$value.'" in the column "'.$data['file_headers'][$column_num].'" on line '.$line_num.' is a duplicate of line '.$future_values[$dr_id][$df_id]['line'];
                        $errors[] = [
                            'level' => 'Error',
                            'category' => 'Duplicate values in CSV File',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => $message,
                            ],
                        ];
                    }
                    else {
                        // Otherwise, store the line_num/value so it can be checked later on
                        $future_values[$dr_id][$df_id] = [
                            'line' => $line_num,
                            'value' => $value
                        ];
                    }
                }
                else {
                    // This is a child datatype

                    // Overwrite any existing value for this datafield, or just create a new one
                    //  if it doesn't exist
                    if ( !isset($future_values[$dr_id]) )
                        $future_values[$dr_id] = [];
                    if ( !isset($future_values[$dr_id][$child_dr_id]) )
                        $future_values[$dr_id][$child_dr_id] = [];

                    // Need to look for duplicate entries in the external id column of the csv file
                    if ( $df_id === $child_external_id_field
                        && isset($future_values[$dr_id][$child_dr_id][$df_id]['line'])
                        && $future_values[$dr_id][$child_dr_id][$df_id]['line'] !== 0
                    ) {
                        // ...if these duplicates were left alone, then the CSVImport worker would
                        //  receive more than one row of data to update the same record...due to
                        //  randomization of priority on background jobs, there's no guarantee which
                        //  row of data the record would end up using after the import finished
                        $message = 'The external id value "'.$value.'" in the column "'.$data['file_headers'][$column_num].'" on line '.$line_num.' is a duplicate of line '.$future_values[$dr_id][$child_dr_id][$df_id]['line'];
                        $errors[] = [
                            'level' => 'Error',
                            'category' => 'Duplicate values in CSV File',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => $message,
                            ],
                        ];
                    }
                    else {
                        // Otherwise, store the line_num/value so it can be checked later on
                        $future_values[$dr_id][$child_dr_id][$df_id] = [
                            'line' => $line_num,
                            'value' => $value
                        ];
                    }
                }
            }

        }

        return $errors;
    }


    /**
     * After self::getExistingUniqueValues() and self::getFutureUniqueValues() have been run, the
     * third and final step in determining whether the CSV file violates uniqueness constraints is
     * to attempt to find duplicates inside $future_values...
     *
     * NOTE: this is not used for imports that link records together
     *
     * @param array $data {@see self::getUniquenessCheckData())
     * @param array $future_values {@see self::getFutureUniqueValues()}
     *
     * @return array
     */
    public function findUniquenessErrors($data, $future_values)
    {
        // Don't do anything if called on a 'link' or 'child-link' import
        if ( $data['import_type'] === 'link' || $data['import_type'] === 'child-link' )
            return [];

        // Going to attempt to detect instances where the values in the csv file would violate
        //  uniqueness constraints
        $errors = [];

        // This function needs to flip the unique mapping so it's (df_id => column_num) instead
        $unique_columns = array_flip( $data['unique_mapping'] );

        if ( $data['import_type'] === 'top-level' ) {
            // This is a top-level datatype
            $seen_values = [];
            foreach ($future_values as $dr_id => $df_data) {
                foreach ($df_data as $df_id => $csv_data) {
                    // NOTE - doing it this way allows newly created top-level datarecords to have
                    //  blank values in otherwise unique fields
                    $line_num = $csv_data['line'];
                    $value = $csv_data['value'];

                    if ( !isset($seen_values[$df_id][$value]) ) {
                        // Haven't seen this value before, keep looking
                        $seen_values[$df_id][$value] = [
                            'prev_line' => $line_num,
                            'prev_dr_id' => $dr_id,
                        ];
                    }
                    else {
                        // Have seen this value before, complain
                        $column_num = $unique_columns[$df_id];

                        // The error message needs to change based on whether it's a duplicate of an
                        //  existing datarecord or not
                        $prev_line_num = $seen_values[$df_id][$value]['prev_line'];
                        $prev_dr_id = $seen_values[$df_id][$value]['prev_dr_id'];
                        $prev_dr_value = array_search($prev_dr_id, $data['parent_dr_lookup']);

                        $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" on line '.$line_num.' is a duplicate of line '.$prev_line_num;
                        if ( is_numeric($prev_dr_id) )
                            $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" already exists in Datarecord '.$prev_dr_id.' "'.$prev_dr_value.'"';

                        $errors[] = [
                            'level' => 'Error',
                            'category' => 'Duplicate values in CSV File',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => $message,
                            ],
                        ];
                    }
                }
            }
        }
        else {
            // This is a child datatype
            foreach ($future_values as $parent_dr_id => $child_dr_ids) {
                // The values in the child records must be unique within the parent datatype
                $seen_values = [];
                foreach ($child_dr_ids as $child_dr_id => $df_data) {
                    foreach ($df_data as $df_id => $csv_data) {
                        $line_num = $csv_data['line'];
                        $value = $csv_data['value'];

                        //
                        if ( !isset($seen_values[$df_id][$value]) ) {
                            // Haven't seen this value before, keep looking
                            $seen_values[$df_id][$value] = [
                                'prev_line' => $line_num,
                                'prev_cdr_id' => $child_dr_id,
                            ];
                        }
                        else {
                            // Have seen this value before, complain
                            $column_num = $unique_columns[$df_id];

                            // The error message needs to change based on whether it's a duplicate
                            //  of an existing child datarecord or not
                            $prev_line_num = $seen_values[$df_id][$value]['prev_line'];
                            $prev_cdr_id = $seen_values[$df_id][$value]['prev_cdr_id'];

                            $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" on line '.$line_num.' is a duplicate of line '.$prev_line_num;
                            if ( is_numeric($prev_cdr_id) )
                                $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" already exists in Datarecord '.$prev_cdr_id;

                            $errors[] = [
                                'level' => 'Error',
                                'category' => 'Duplicate values in CSV File',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => $message,
                                ],
                            ];
                        }
                    }
                }
            }
        }

        return $errors;
    }


    /**
     * Determines whether the csv file for this CSVImport will create multiple child/linked records
     * in a relation that only allows single child/linked records.
     *
     * @param array $data {@see self::getUniquenessCheckData()}
     * @param CsvReader $reader this needs to be pointing to the beginning of the csv file
     *
     * @return array
     */
    public function findDatarecordNumberErrors($data, $reader)
    {
        // Don't do anything if not importing into a child/linked datatype
        if ( $data['import_type'] === 'top-level' )
            return [];

        $descendant_datatype_id = $data['target_datatype_id'];
        $ancestor_datatype_id = $data['parent_datatype_id'];

        // Don't need to do anything if the parent datarecord allows multiple child/linked descendants
        $datatree_array = $this->datatree_info_service->getDatatreeArray();
        if ( isset($datatree_array['multiple_allowed'][$descendant_datatype_id])
            && in_array($ancestor_datatype_id, $datatree_array['multiple_allowed'][$descendant_datatype_id])
        ) {
            return [];
        }

        // Otherwise, need to determine whether the import will create multiple descendant records
        //  when there should only be at most one
        $errors = [];

        // May need the names of the ancestor/descendant datatypes for error reporting
        /** @var DataType $descendant_datatype */
        $descendant_datatype = $this->em->getRepository('ODR\AdminBundle\Entity\DataType')->find($descendant_datatype_id);
        /** @var DataType $ancestor_datatype */
        $ancestor_datatype = $this->em->getRepository('ODR\AdminBundle\Entity\DataType')->find($ancestor_datatype_id);


        // ----------------------------------------
        // Need to load the existing parent/child relations...
        $rel_type_adj = null;
        $existing_records = [];
        if ( $data['import_type'] == 'child' ) {
            $rel_type_adj = 'child';
            $tmp = $this->search_service->getCachedDatarecordList($descendant_datatype_id);

            // Need to flip the array so it's in (parent_id => child_id) format...since only a single
            //  descendant is allowed, no data will be lost by doing this
            $existing_records = array_flip($tmp);
        }
        else {
            $rel_type_adj = 'linked';
            $tmp = $this->search_service->getCachedDatarecordList($descendant_datatype_id, false, true);

            // The array needs to be flipped, but it's not trivial because multiple ancestors can
            //  link to the descendant record
            foreach ($tmp as $descendant_dr_id => $parents) {
                foreach ($parents as $ancestor_dr_id => $val) {
                    // Since only a single descendant is allowed, no data will be lost by doing this
                    $existing_records[$ancestor_dr_id] = $descendant_dr_id;
                }
            }
        }

        // The ancestor datatype is guaranteed to have an external id field/values at this point
        $ancestor_lookup = $data['parent_dr_lookup'];
        $ancestor_external_id_column = $data['parent_external_id_column'];
        $ancestor_external_id_field_name = $ancestor_datatype->getExternalIdField()->getFieldName();

        // The descendant datatype will have an external id field if it's a remote datatype, but a
        //  child datatype isn't guaranteed to have one
        $descendant_lookup = [];
        if ( !is_null($data['target_external_id_column']) )
            $descendant_lookup = $data['target_dr_lookup'];
        $descendant_external_id_column = $data['target_external_id_column'];

        // Where there is no external id for a child datatype, the importer will overwrite existing
        //  child datarecords...there needs to be a check for whether the csv file attempts to
        //  overwrite the same child datarecord twice, however
        $overwritten_children = [];


        // ----------------------------------------
        // Read the csv file to see whether it ends up creating more than one descendant for any
        //  given ancestor
        $line_num = 0;
        foreach ($reader as $row) {
            // Skip header row
            $line_num++;
            if ( $line_num === 1 )
                continue;

            // Attempt to locate the ancestor datarecord...
            $ancestor_external_id_value = $row[$ancestor_external_id_column];
            if ( !isset($ancestor_lookup[$ancestor_external_id_value]) ) {
                // ...if it doesn't exist, csvvalidateAction() will warn the user, and csvworkerAction()
                //  will throw a background error so the row of data is effectively ignored

                // Therefore, don't need to continue looking at this row of data
                continue;
            }
            // The ancestor datarecord is guaranteed to exist after this point
            $ancestor_dr_id = $ancestor_lookup[$ancestor_external_id_value];


            // Attempt to locate the descendant datarecord...
            if ( is_null($descendant_external_id_column) ) {
                // ...this is a single-allowed child datatype without an external id field

                // If the ancestor datarecord already has a child record...
                if ( isset($existing_records[$ancestor_dr_id]) ) {
                    if ( !isset($overwritten_children[$ancestor_dr_id]) ) {
                        // ...then csvworkerAction() will end up loading/overwriting the child record
                        // The user should be warned, but the import should not be completely blocked
                        $message = 'The Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'" already has a child Datarecord...running the import will overwrite the existing Datarecord instead of creating a new one';
                        if ( $existing_records[$ancestor_dr_id] === '' )
                            $message = 'The csv file is already creating a child datarecord for the parent Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'"';

                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Potential overwrites',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => $message,
                            ],
                        ];

                        // Store that a child will be overwritten for this ancestor
                        $overwritten_children[$ancestor_dr_id] = 1;
                    }
                    else {
                        // If this point is reached, the csv file has a duplicate entry for this
                        //  ancestor...should throw an error instead of a warning, since there's no
                        //  telling what the child record will end up with
                        $errors[] = [
                            'level' => 'Error',
                            'category' => 'Duplicate values in CSV File',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'The Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'" is listed multiple times in the csv file',
                            ],
                        ];
                    }
                }
                else {
                    // The ancestor datarecord does not currently have a child...the importer will
                    //  end up creating one
                    $existing_records[$ancestor_dr_id] = '';
                }
            }
            else {
                // ...this is a single-allowed child datatype with an external id field, or a remote
                //  linked datatype (which is guaranteed to have an external id at this point)

                // If the descendant datarecord exists...
                $descendant_external_id_value = $row[$descendant_external_id_column];
                $descendant_dr_id = null;
                if ( isset($descendant_lookup[$descendant_external_id_value]) )
                    $descendant_dr_id = $descendant_lookup[$descendant_external_id_value];

                // Not having a descendant datarecord at this time isn't an error...csvvalidateAction()
                //  will eventually create an error if it should be (e.g. non-existent remote record)

                if ( !isset($existing_records[$ancestor_dr_id]) ) {
                    // This ancestor record does not currently have a descendant...the import can
                    //  create/link at most one descendant for this ancestor
                    if ( is_null($descendant_dr_id) )
                        $existing_records[$ancestor_dr_id] = '';
                    else
                        $existing_records[$ancestor_dr_id] = $descendant_dr_id;
                }
                else if ( $existing_records[$ancestor_dr_id] !== $descendant_dr_id ) {
                    // The ancestor currently has a descendant, but the csv file is referencing a
                    //  different (or non-existent) record...the constraint will be violated if the
                    //  importer is allowed to continue
                    $errors[] = [
                        'level' => 'Error',
                        'category' => 'Database constraint violations',
                        'body' => [
                            'line_num' => $line_num,
                            'message' => 'The relationship between the ancestor Datatype "'.$ancestor_datatype->getShortName().'" and its '.$rel_type_adj.' Datatype "'.$descendant_datatype->getShortName().'" only permits a single descendant, but line '.$line_num.' in the csv file will create another descendant for the ancestor Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'"'
                        ],
                    ];
                }

                // Don't need to check for duplicates here...
                // If the user was importing into a child datatype, self::getFutureUniqueValues()
                //  would've already found and complained about any duplicates in the file
                // If the user is importing into a linked datatype, then duplicates in the file
                //  don't actually matter...CSVImportController::csvlinkworkerAction() will merely
                //  ensure that the link exists more than once

                // Otherwise, the csv file matches the current ancestor/descendant pair...no problem, yet
            }
        }

        return $errors;
    }


    /**
     * Originally the actual validation of each line of a CSVImport was inside its controller, but
     * it's easier to debug if it's off in a service...
     *
     * @param array $post
     * @param array $new_tags
     * @param array $validation_params
     *
     * @return array
     */
    public function validateImportFileLine($post, &$new_tags, $validation_params)
    {
        $repo_datafield = $this->em->getRepository('ODR\AdminBundle\Entity\DataFields');
        $repo_datatype = $this->em->getRepository('ODR\AdminBundle\Entity\DataType');
        $repo_fieldtype = $this->em->getRepository('ODR\AdminBundle\Entity\FieldType');

        // ----------------------------------------
        $import_type = $post['import_type'];

        $user_id = $post['user_id'];
        /** @var ODRUser $user */
        $user = $this->em->getRepository('ODR\OpenRepository\UserBundle\Entity\User')->find($user_id);

        $column_names = $post['column_names'];
        $line_num = $post['line_num'];
        $line = $post['line'];

        // Have to pull these separately because they might not exist
        $datafield_mapping = [];
        if ( isset($post['datafield_mapping']) )
            $datafield_mapping = $post['datafield_mapping'];
        $fieldtype_mapping = [];
        if ( isset($post['fieldtype_mapping']) )
            $fieldtype_mapping = $post['fieldtype_mapping'];
        $column_delimiters = [];
        if ( isset($post['column_delimiters']) )
            $column_delimiters = $post['column_delimiters'];
        $hierarchy_delimiters = [];
        if ( isset($post['hierarchy_delimiters']) )
            $hierarchy_delimiters = $post['hierarchy_delimiters'];

/*
        // TODO - these aren't used in validations?
        $unique_columns = [];
        if ( isset($post['unique_columns']) )
            $unique_columns = $post['unique_columns'];
        $synch_columns = array();
        if ( isset($post['synch_columns']) )
            $synch_columns = $post['synch_columns'];
*/


        // Makes some sense to reuse this function here
        $uniqueness_check_data = self::getUniquenessCheckData($post, [], $import_type);
//        var_dump($uniqueness_check_data);

        /** @var null|Datatype $grandparent_datatype */
        /** @var null|Datatype $parent_datatype */
        /** @var Datatype $target_datatype */
        $grandparent_datatype = $parent_datatype = $target_datatype = null;
        if ( !is_null($uniqueness_check_data['grandparent_datatype_id']) )
            $grandparent_datatype = $repo_datatype->find( $uniqueness_check_data['grandparent_datatype_id'] );
        if ( !is_null($uniqueness_check_data['parent_datatype_id']) )
            $parent_datatype = $repo_datatype->find( $uniqueness_check_data['parent_datatype_id'] );
        if ( !is_null($uniqueness_check_data['target_datatype_id']) )
            $target_datatype = $repo_datatype->find( $uniqueness_check_data['target_datatype_id'] );

        /** @var null|DataFields $grandparent_external_id_field */
        /** @var null|DataFields $parent_external_id_field */
        /** @var null|DataFields $target_external_id_field */
        $grandparent_external_id_field = $parent_external_id_field = $target_external_id_field = null;
        if ( !is_null($uniqueness_check_data['grandparent_external_id_field']) )
            $grandparent_external_id_field = $repo_datafield->find( $uniqueness_check_data['grandparent_external_id_field'] );
        if ( !is_null($uniqueness_check_data['parent_external_id_field']) )
            $parent_external_id_field = $repo_datafield->find( $uniqueness_check_data['parent_external_id_field'] );
        if ( !is_null($uniqueness_check_data['target_external_id_field']) )
            $target_external_id_field = $repo_datafield->find( $uniqueness_check_data['target_external_id_field'] );

        $grandparent_external_id_column = $parent_external_id_column = $target_external_id_column = null;
        if ( !is_null($uniqueness_check_data['grandparent_external_id_column']) )
            $grandparent_external_id_column = $uniqueness_check_data['grandparent_external_id_column'];
        if ( !is_null($uniqueness_check_data['parent_external_id_column']) )
            $parent_external_id_column = $uniqueness_check_data['parent_external_id_column'];
        if ( !is_null($uniqueness_check_data['target_external_id_column']) )
            $target_external_id_column = $uniqueness_check_data['target_external_id_column'];


        // ----------------------------------------
        // Going to return any errors in an array
        $errors = [];

        // Attempt to locate the relevant records if external id field values are in use
        $grandparent_dr_val = $parent_dr_val = $target_dr_val = null;

        /** @var null|DataRecord $grandparent_dr */
        /** @var null|DataRecord $parent_dr */
        /** @var null|DataRecord $target_dr */
        $grandparent_dr = $parent_dr = $target_dr = null;
        if ( !is_null($grandparent_external_id_field)
            && !is_null($grandparent_external_id_column)
            && $import_type === 'child-link'
        ) {
            $grandparent_dr_val = $line[$grandparent_external_id_column];
            $grandparent_dr = $this->datarecord_info_service->getDatarecordByExternalId($grandparent_external_id_field, $grandparent_dr_val);
            if ($grandparent_dr == null) {
                $errors[] = [
                    'level' => 'Warning',
                    'category' => 'Missing grandparent datarecords',
                    'body' => [
                        'line_num' => $line_num,
                        'message' => 'The value "'.$grandparent_dr_val.'" in column "'.$column_names[$grandparent_external_id_column].'" is supposed to match the external ID of a grandparent Datarecord in "'.$grandparent_datatype->getShortName().'", but no such Datarecord exists...this row will be ignored',
                    ],
                ];
            }
        }

        if ( !is_null($parent_external_id_field)
            && !is_null($parent_external_id_column)
            && ($import_type === 'child' || $import_type === 'link' || $import_type === 'child-link')
        ) {
            $parent_dr_val = $line[$parent_external_id_column];
            if ( $import_type === 'child' || $import_type === 'link' ) {
                // In both these cases, the datatype will be top-level
                $parent_dr = $this->datarecord_info_service->getDatarecordByExternalId($parent_external_id_field, $parent_dr_val);
                if ($parent_dr == null) {
                    // ...in these two situations, the row of data will be completely ignored
                    $errors[] = [
                        'level' => 'Warning',
                        'category' => 'Missing datarecords',
                        'body' => [
                            'line_num' => $line_num,
                            'message' => 'The value "'.$parent_dr_val.'" in column "'.$column_names[$parent_external_id_column].'" is supposed to match the external ID of a parent Datarecord in "'.$parent_datatype->getShortName().'", but no such Datarecord exists...this row will be ignored',
                        ],
                    ];
                }
            }
            else {  // import_type === 'child-link'
                // In this case, the datatype will be a child of the top-level/grandparent datatype
                // No point trying to locate it if the grandparent doesn't exist
                if ( !is_null($grandparent_dr) ) {
                    $parent_dr = $this->datarecord_info_service->getChildDatarecordByExternalId($parent_external_id_field, $parent_dr_val, $grandparent_external_id_field, $grandparent_dr_val);
                    if ($parent_dr == null) {
                        // ...in this situation, the system will create the missing child record
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Missing child records',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'The value "'.$parent_dr_val.'" in column "'.$column_names[$parent_external_id_column].'" is supposed to match the external ID of a parent Datarecord in "'.$parent_datatype->getShortName().'", but no such Datarecord exists.',
                            ],
                        ];
                    }
                }
            }
        }

        if ( !is_null($target_external_id_field)
            && !is_null($target_external_id_column)
            // import_type doesn't matter here...
        ) {
            $target_dr_val = $line[$target_external_id_column];
            // ...but where to look up the datarecord does depend on import_type
            if ( $import_type === 'top-level' || $import_type === 'link' || $import_type === 'child-link' )
                $target_dr = $this->datarecord_info_service->getDatarecordByExternalId($target_external_id_field, $target_dr_val);
            else // $import_type === 'child'
                $target_dr = $this->datarecord_info_service->getChildDatarecordByExternalId($target_external_id_field, $target_dr_val, $parent_external_id_field, $parent_dr_val);

            if ( $target_dr == null
                && ($import_type === 'link' || $import_type === 'child-link')
            ) {
                // A missing target datarecord is only a problem when trying to link to it...but it's
                //  a fatal error instead of a possible issue
                $errors[] = [
                    'level' => 'Error',
                    'category' => 'Missing link datarecords',
                    'body' => [
                        'line_num' => $line_num,
                        'message' => 'The value "'.$target_dr_val.'" in column "'.$column_names[$target_external_id_column].'" is supposed to match the external ID of a Datarecord in the Datatype "'.$target_datatype->getShortName().'", but no such Datarecord exists',
                    ],
                ];
            }
        }


        // ----------------------------------------
        // Attempt to validate each line of data against the desired datafield/fieldtype mapping
        foreach ($datafield_mapping as $column_num => $datafield_id) {
            $value = trim( (string) $line[$column_num] );
            $length = mb_strlen($value, "utf-8");

            // Get typeclass of what this data will be imported into
            $allow_multiple_uploads = true;
            /** @var FieldType $fieldtype */
            $fieldtype = null;
            if ($datafield_id == 'new') {
                // Datafield hasn't been created yet, grab which fieldtype it's supposed to be from the mapping
                $fieldtype = $repo_fieldtype->find( $fieldtype_mapping[$column_num] );
            }
            else {
                // Datafield already exists, grab fieldtype from datafield
                $datafield = $repo_datafield->find($datafield_id);
                $fieldtype = $datafield->getFieldType();

                // Also store whether the datafield has been set to only allow a single file/image upload
                if ( $datafield->getAllowMultipleUploads() == false )
                    $allow_multiple_uploads = false;
            }

            // Check for errors specifically related to this fieldtype
            $typeclass = $fieldtype->getTypeClass();
            switch ($typeclass) {
                case "Boolean":
                    // TODO
                    break;

                case "File":
                case "Image":
                    if ( $value !== '' && isset($column_delimiters[$column_num]) ) {
                        // Due to validation in CSVImportController::startvalidateAction(), this will
                        //  exist when the datafield is a file/image
                        $upload_dir = $this->odr_tmp_directory.'/user_'.$user->getId().'/csv_storage/';

                        // Grab a list of the files already uploaded to this datafield
                        $already_uploaded_files = [];
                        if ( !is_null($target_dr) ) {
                            $query_str =
                               'SELECT em.originalFileName
                                FROM ODRAdminBundle:'.$typeclass.' AS e
                                JOIN ODRAdminBundle:'.$typeclass.'Meta AS em WITH em.'.strtolower($typeclass).' = e
                                WHERE e.dataRecord = :datarecord AND e.dataField = :datafield ';
                            if ($typeclass == 'Image')
                                $query_str .= 'AND e.original = 1 ';
                            $query_str .= 'AND e.deletedAt IS NULL AND em.deletedAt IS NULL';

                            $query = $this->em->createQuery($query_str)->setParameters(
                                [
                                    'datarecord' => $target_dr->getId(),
                                    'datafield' => $datafield_id
                                ]
                            );
                            $results = $query->getArrayResult();

                            foreach ($results as $tmp => $result)
                                $already_uploaded_files[] = $result['originalFileName'];
                        }

                        // Grab all filenames listed in the field
                        $filenames = explode( $column_delimiters[$column_num], $value );
                        $total_file_count = count($filenames) + count($already_uploaded_files);
                        foreach ($filenames as $filename) {
                            // Don't attempt to upload files with no name
                            $filename = trim($filename);
                            if ($filename === '')
                                continue;

                            // Determine whether the file is already uploaded to the server
                            $already_uploaded = false;
                            if ( in_array($filename, $already_uploaded_files) )
                                $already_uploaded = true;

                            if ($already_uploaded) {
                                // The File/Image has already been uploaded to this datafield
                                // Regardless of whether the upload ignores the file/image or
                                //  replaces the existing file/image, there will be no net change
                                //  in the number of files/images uploaded
                                $total_file_count--;
                            }

                            if ( !file_exists($upload_dir.$filename) ) {
                                // File/Image does not exist in the upload directory
                                $errors[] = [
                                    'level' => 'Warning',
                                    'category' => 'Missing filenames',
                                    'body' => [
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" references a '.$typeclass.' "'.$filename.'", but that '.$typeclass.' has not been uploaded to the server.',
                                    ]
                                ];
                            }
                            else {
                                // File/Image exists, ensure it has a valid mimetype
                                $validation_params = $validation_params[ strtolower($typeclass) ];

                                $uploaded_file = new SymfonyFile($upload_dir.$filename);
                                if ( count($validation_params['mimeTypes']) > 0 && !in_array($uploaded_file->getMimeType(), $validation_params['mimeTypes']) ) {
                                    $errors[] = [
                                        'level' => 'Error',
                                        'category' => 'Invalid upload files',
                                        'body' => [
                                            'line_num' => $line_num,
                                            'message' => 'The '.$typeclass.' "'.$filename.'" listed in column "'.$column_names[$column_num].'" is not a valid file for the '.$typeclass.' fieldtype.',
                                        ]
                                    ];
                                }
                            }
                        }

                        // Also check to ensure that uploading a file/image won't violate the state of the "allow multiple uploads" checkbox
                        if ( !$allow_multiple_uploads && $total_file_count > 1 ) {
                            $errors[] = [
                                'level' => 'Error',
                                'category' => 'Database constraint violations',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => 'The column "'.$column_names[$column_num].'" is marked as only allowing a single '.$typeclass.' upload, but it would have '.$total_file_count.' '.$typeclass.'s uploaded after this CSV Import.',
                                ]
                            ];
                        }
                    }
                    break;

                case "IntegerValue":
                    if ( !ValidUtility::isValidInteger($value) ) {
                        // Warn about invalid characters in an integer conversion
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'": the value "'.$value.'" is not a valid integer value, and will not be imported'
                            ]
                        ];
                    }
                    break;
                case "DecimalValue":
                    if ( !ValidUtility::isValidDecimal($value) ) {
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'": the value "'.$value.'" is not a valid decimal value, and will not be imported'
                            ],
                        ];
                    }
                    break;

                case "DatetimeValue":
                    // TODO - make this use ValidUtility?  it also doesn't exactly match the actual import logic...
                    $pattern = '/^(\d{1,4})$/'; // string consists solely of one to four digits
                    if ( preg_match($pattern, $value) == 1 ) {
                        $errors[] = [
                            'level' => 'Error',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", which is not a valid Datetime value',
                            ],
                        ];
                    }
                    else {
                        try {
                            $tmp = new \DateTime($value);
                        }
                        catch (\Exception) {
                            $errors[] = [
                                'level' => 'Error',
                                'category' => 'Invalid values',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" has the value "'.$value.'", which is not a valid Datetime value',
                                ],
                            ];
                        }
                    }
                    break;

                case "ShortVarchar":
                    if ( !ValidUtility::isValidShortVarchar($value) ) {
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a ShortVarchar field can only store up to 32 characters',
                            ],
                        ];
                    }
                    break;
                case "MediumVarchar":
                    if ( !ValidUtility::isValidMediumVarchar($value) ) {
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a MediumVarchar field can only store up to 64 characters',
                            ],
                        ];
                    }
                    break;
                case "LongVarchar":
                    if ( !ValidUtility::isValidLongVarchar($value) ) {
                        $errors[] = [
                            'level' => 'Warning',
                            'category' => 'Invalid values',
                            'body' => [
                                'line_num' => $line_num,
                                'message' => 'Column "'.$column_names[$column_num].'" is '.$length.' characters long, but a LongVarchar field can only store up to 255 characters',
                            ],
                        ];
                    }
                    break;
                case "LongText":
                    /* nothing to validate? */
                    break;

                case "Radio":
                    // Don't attempt to validate an empty string...it just means there are no options selected
                    if ( $value == '' )
                        break;

                    if ( isset($column_delimiters[$column_num]) ) {
                        // Due to validation in CSVImportController::startvalidateAction(), this will
                        //  exist when the datafield is a multiple select/radio...it won't exist for
                        //  a single select/radio

                        // Check length of each option?
                        $options = explode( $column_delimiters[$column_num], $value );
                        foreach ($options as $option) {
                            $option = trim($option);
                            $option_length = mb_strlen($option, "utf-8");
                            if ( $option_length == 0 ) {
                                $errors[] = [
                                    'level' => 'Warning',
                                    'category' => 'Invalid radio option names',
                                    'body' => [
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" would create a blank radio option during import...',
                                    ],
                                ];
                            }
                            else if ( $option_length > 255 ) {
                                $errors[] = [
                                    'level' => 'Warning',
                                    'category' => 'Invalid radio option names',
                                    'body' => [
                                        'line_num' => $line_num,
                                        'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                    ],
                                ];
                            }
                        }
                    }
                    else {
                        // Check length of option
                        if ($length > 255) {
                            $errors[] = [
                                'level' => 'Warning',
                                'category' => 'Invalid radio option names',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" has a Radio Option that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                ],
                            ];
                        }
                    }
                    break;

                case "Tag":
                    // Don't attempt to validate an empty string...it just means there are no tags selected
                    if ( $value == '' )
                        break;

                    // The delimiter should always exist
                    $tags = explode($column_delimiters[$column_num], $value);

                    // Explode the column's value to extract every single individual tag
                    $all_tags = [];
                    foreach ($tags as $num => $tag_name) {
                        $tag_name = trim($tag_name);

                        if ( isset($hierarchy_delimiters[$column_num]) ) {
                            $exploded_tags = explode( $hierarchy_delimiters[$column_num], $tag_name );
                            foreach ($exploded_tags as $num => $exploded_tag_name) {
                                $exploded_tag_name = trim($exploded_tag_name);
                                $all_tags[$exploded_tag_name] = 1;
                            }
                        }
                        else {
                            $all_tags[$tag_name] = 1;
                        }
                    }

                    // Ensure all of the tags mentioned in this field are neither blank nor
                    //  longer than 255 characters
                    foreach ($all_tags as $tag_name => $num) {
                        if ( trim($tag_name) === '' ) {
                            $errors[] = [
                                'level' => 'Warning',
                                'category' => 'Invalid tag names',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" would create at least one blank tag during import...',
                                ],
                            ];
                        }
                        else if ( mb_strlen($tag_name, "utf-8") > 255 ) {
                            $errors[] = [
                                'level' => 'Warning',
                                'category' => 'Invalid tag names',
                                'body' => [
                                    'line_num' => $line_num,
                                    'message' => 'Column "'.$column_names[$column_num].'" has a Tag that is '.$length.' characters long, but the maximum length allowed is 255 characters',
                                ],
                            ];
                        }
                    }

                    // Store the list of potential new tags for persisting back into the
                    // tracked job entity
                    $tags_in_column = $new_tags[$column_num];
                    foreach ($tags as $num => $tag_name) {
                        $tag_name = trim($tag_name);
                        $tags_in_column[$tag_name] = 1;
                    }
                    $new_tags[$column_num] = $tags_in_column;

                    // NOTE - not able to determine whether the column is attempting to "select"
                    //  a mid-level tag without knowing the complete tag structure beforehand...
                    break;
            }
        }

        return $errors;
    }
}
