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
use ODR\AdminBundle\Entity\DataType;
// Exceptions
// Services
use ODR\OpenRepository\SearchBundle\Component\Service\SearchService;
// Other
use Ddeboer\DataImport\Reader\CsvReader;
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;


class CSVImportHelperService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatatreeInfoService
     */
    private $dti_service;

    /**
     * @var SearchService
     */
    private $search_service;

    /**
     * @var SortService
     */
    private $sort_service;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CSVImportHelperService constructor
     *
     * @param EntityManager $entity_manager
     * @param DatatreeInfoService $dti_service
     * @param SearchService $search_service
     * @param SortService $sort_service
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatatreeInfoService $dti_service,
        SearchService $search_service,
        SortService $sort_service,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dti_service = $dti_service;
        $this->search_service = $search_service;
        $this->sort_service = $sort_service;
        $this->logger = $logger;
    }


    /**
     * Extracts data from the POST request sent to CSVImportController::startvalidateAction(), so
     * that the rest of the functions in this service can do their job more easily.
     *
     * @param array $post
     * @param array $file_headers
     *
     * @return array
     */
    public function getUniquenessCheckData($post, $file_headers)
    {
        $data = array(
            'file_headers' => $file_headers,

            'import_datatype_id' => intval($post['datatype_id']),
            'parent_datatype_id' => null,

            'import_into_top_level' => false,
            'import_into_child_datatype' => false,
            'import_linked_datarecords' => false,

            'top_level_external_id_field' => null,
            'child_external_id_field' => null,
            'top_level_external_id_column' => null,
            'child_external_id_column' => null,

            'top_level_lookup' => array(),
            'child_lookup' => array(),
        );

        // Going to need the datatype that's getting imported into
        /** @var DataType $dt */
        $dt = $this->em->getRepository('ODRAdminBundle:DataType')->find( $data['import_datatype_id'] );

        if ( !isset($post['parent_datatype_id']) ) {
            // Importing into a top-level datatype
            $data['import_into_top_level'] = true;

            // Save the external ID field if the datatype has one
            if ( !is_null($dt->getExternalIdField()) ) {
                $data['top_level_external_id_field'] = $dt->getExternalIdField()->getId();

                // Find the column that the external ID field is mapped to in the import file, if possible
                $key = array_search($data['top_level_external_id_field'], $post['datafield_mapping']);
                if ( $key !== false )
                    $data['top_level_external_id_column'] = $key;
            }
        }
        else {
            // Not importing into a top-level datatype
            $data['parent_datatype_id'] = intval($post['parent_datatype_id']);

            if ( $dt->getParent()->getId() === $data['parent_datatype_id'] ) {
                // Importing into a child datatype
                $data['import_into_child_datatype'] = true;

                // Save the external ID field of this child datatype, if possible
                if ( !is_null($dt->getExternalIdField()) ) {
                    $data['child_external_id_field'] = $dt->getExternalIdField()->getId();

                    // Find the column that the child datatype's external ID field is mapped to in
                    //  the import file, if possible
                    $key = array_search($data['child_external_id_field'], $post['datafield_mapping']);
                    if ( $key !== false )
                        $data['child_external_id_column'] = $key;
                }

                // Save the external ID field of this child datatype's parent
                if ( !is_null($dt->getParent()->getExternalIdField()) ) {
                    $data['top_level_external_id_field'] = $dt->getParent()->getExternalIdField()->getId();

                    // Find the column that the parent datatype's external ID field is mapped to in
                    //  the import file...the CSV importer only allows importing into a child datatype
                    //  when the parent has an external id field
                    $data['top_level_external_id_column'] = intval( $post['parent_external_id_column'] );
                }
            }
            else {
                // Importing as a linked datatype
                $data['import_linked_datarecords'] = true;
                $data['top_level_external_id_column'] = intval( $post['parent_external_id_column'] );
                $data['child_external_id_column'] = intval( $post['remote_external_id_column'] );

                // When importing as a linked datatype, $dt is the remote datatype...the CSVImport
                //  process has already ensured both of the relevant datatypes have external id fields
                if ( !is_null($dt->getExternalIdField()) )
                    $data['child_external_id_field'] = $dt->getExternalIdField()->getId();

                /** @var DataType $ancestor_dt */
                $ancestor_dt = $this->em->getRepository('ODRAdminBundle:DataType')->find( $data['parent_datatype_id'] );
                if ( !is_null($ancestor_dt->getExternalIdField()) )
                    $data['top_level_external_id_field'] = $ancestor_dt->getExternalIdField()->getId();
            }
        }

        // Want to also create a more direct mapping from the post data, where the column_num of a
        //  column in the CSV file that is marked as unique points to a datafield_id
        $data['unique_mapping'] = array();
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
        if ( !is_null($data['top_level_external_id_field']) ) {
            $dr_lookup = $this->sort_service->sortDatarecordsByDatafield( $data['top_level_external_id_field'] );
            $data['top_level_lookup'] = array_flip($dr_lookup);
        }
        if ( !is_null($data['child_external_id_field']) ) {
            $dr_lookup = $this->sort_service->sortDatarecordsByDatafield( $data['child_external_id_field'] );
            $data['child_lookup'] = array_flip($dr_lookup);
        }

        // TODO - is anything else needed/useful?

        return $data;
    }


    /**
     * The first step in determining whether the CSV file violates uniqueness constraints is to
     * load all existing values in the relevant datafields...
     *
     * NOTE: this doesn't work for checking imports into linked datatypes
     *
     * @param array $data @see self::getUniquenessCheckData()
     *
     * @return array
     */
    public function getExistingUniqueValues($data)
    {
        // Going to build an array of the existing values in each unique datafield that's getting
        //  imported into
        $existing_values = array();

        // If importing into a child datatype, then need to be able to lookup the id of a top-level
        //  record based on the id of a child record
        $parent_lookup = array();
        if ( $data['import_into_child_datatype'] )
            $parent_lookup = $this->search_service->getCachedSearchDatarecordList($data['import_datatype_id']);

        // Load the existing values for all existing datafields that are being imported into
        foreach ($data['unique_mapping'] as $column_num => $df_id) {
            if ( is_numeric($df_id) ) {
                // $sort_service->sortDatarecordsByDatafield() returns an array where (dr_id => sort_value)
                // ...this happens to make it quite convenient for building a list of existing values
                //  for text/number-type datafields
                $dr_list = $this->sort_service->sortDatarecordsByDatafield($df_id);

                // For each existing datarecord...
                foreach ($dr_list as $dr_id => $current_value) {
                    if ( $data['import_into_top_level'] ) {
                        // This is a top-level datarecord...
                        if ( !isset($existing_values[$dr_id]) )
                            $existing_values[$dr_id] = array();

                        $existing_values[$dr_id][$df_id] = array(
                            'line' => 0,
                            'value' => $current_value,
                        );
                    }
                    else /*if ( $data['import_into_child_datatype'] )*/ {
                        // This is a child datarecord...multiple records of a child datatype are
                        //  allowed to have the same unique value, so long as they have different
                        //  parent records
                        $parent_dr_id = $parent_lookup[$dr_id];
                        if ( !isset($existing_values[$parent_dr_id]) )
                            $existing_values[$parent_dr_id] = array();
                        if ( !isset($existing_values[$parent_dr_id][$dr_id]) )
                            $existing_values[$parent_dr_id][$dr_id] = array();

                        $existing_values[$parent_dr_id][$dr_id][$df_id] = array(
                            'line' => 0,
                            'value' => $current_value,
                        );
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
     * NOTE: this doesn't work for checking imports into linked datatypes
     *
     * @param array $data @see self::getUniquenessCheckData()
     * @param CsvReader $reader this needs to be pointing to the beginning of the csv file
     * @param array $future_values @see self::getExistingUniqueValues()
     *
     * @return array
     */
    public function getFutureUniqueValues($data, $reader, &$future_values)
    {
        // Going to try to detect duplicate entries in the CSV file here, if possible
        $errors = array();

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
            if ( !is_null($data['top_level_external_id_column']) ) {
                $dr_id_column = $data['top_level_external_id_column'];
                $dr_id_value = $row[$dr_id_column];

                if ( isset($data['top_level_lookup'][$dr_id_value]) )
                    $dr_id = $data['top_level_lookup'][$dr_id_value];
            }
            // If a top-level datarecord wasn't found, then assign it a "new" id
            if ( is_null($dr_id) ) {
                $new_dr_num++;
                $dr_id = 'new_dr_'.$new_dr_num;

                if ( !is_null($data['top_level_external_id_column']) ) {
                    // When importing into a datatype that has an external id, any new value/dr_id
                    //  pair should get spliced back into the lookup array
                    $data['top_level_lookup'][$dr_id_value] = $dr_id;
                }
            }


            // Also need to locate the child/remote datarecord id that this row points to, if possible
            $child_dr_id = null;
            $child_dr_id_value = null;
            if ( !is_null($data['child_external_id_column']) ) {
                $child_dr_id_column = $data['child_external_id_column'];
                $child_dr_id_value = $row[$child_dr_id_column];

                if ( isset($data['child_lookup'][$child_dr_id_value]) )
                    $child_dr_id = $data['child_lookup'][$child_dr_id_value];
            }
            // If a child/linked datarecord wasn't found, then assign it a "new" id
            if ( $data['import_into_child_datatype'] && is_null($child_dr_id) ) {
                $new_child_dr_num++;
                $child_dr_id = 'new_cdr_'.$new_child_dr_num;

                if ( !is_null($data['child_external_id_column']) ) {
                    // When importing into a child datatype that has an external id, any new
                    //  value/dr_id pair should get spliced back into the lookup array
                    $data['child_lookup'][$child_dr_id_value] = $child_dr_id;
                }
            }


            // Only care about the columns from the CSV file that are going to unique datafields...
            foreach ($data['unique_mapping'] as $column_num => $df_id) {
                $value = trim( $row[$column_num] );

                if ( $data['import_into_top_level'] ) {
                    // This is a top-level datatype

                    // Overwrite any existing value for this datafield, or just create a new one
                    //  if it doesn't exist
                    if ( !isset($future_values[$dr_id]) )
                        $future_values[$dr_id] = array();

                    // Need to look for duplicate entries in the external id column of the csv file
                    if ( $df_id === $data['top_level_external_id_field']
                        && isset($future_values[$dr_id][$df_id]['line'])
                        && $future_values[$dr_id][$df_id]['line'] !== 0
                    ) {
                        // ...if these duplicates were left alone, then the CSVImport worker would
                        //  receive more than one row of data to update the same record...due to
                        //  randomization of priority on background jobs, there's no guarantee which
                        //  row of data the record would end up using after the import finished
                        $message = 'The external id value "'.$value.'" in the column "'.$data['file_headers'][$column_num].'" on line '.$line_num.' is a duplicate of line '.$future_values[$dr_id][$df_id]['line'];
                        $errors[] = array(
                            'level' => 'Error',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => $message,
                            ),
                        );
                    }
                    else {
                        // Otherwise, store the line_num/value so it can be checked later on
                        $future_values[$dr_id][$df_id] = array(
                            'line' => $line_num,
                            'value' => $value
                        );
                    }
                }
                else {
                    // This is a child datatype

                    // Overwrite any existing value for this datafield, or just create a new one
                    //  if it doesn't exist
                    if ( !isset($future_values[$dr_id]) )
                        $future_values[$dr_id] = array();
                    if ( !isset($future_values[$dr_id][$child_dr_id]) )
                        $future_values[$dr_id][$child_dr_id] = array();

                    // Need to look for duplicate entries in the external id column of the csv file
                    if ( $df_id === $data['child_external_id_field']
                        && isset($future_values[$dr_id][$child_dr_id][$df_id]['line'])
                        && $future_values[$dr_id][$child_dr_id][$df_id]['line'] !== 0
                    ) {
                        // ...if these duplicates were left alone, then the CSVImport worker would
                        //  receive more than one row of data to update the same record...due to
                        //  randomization of priority on background jobs, there's no guarantee which
                        //  row of data the record would end up using after the import finished
                        $message = 'The external id value "'.$value.'" in the column "'.$data['file_headers'][$column_num].'" on line '.$line_num.' is a duplicate of line '.$future_values[$dr_id][$child_dr_id][$df_id]['line'];
                        $errors[] = array(
                            'level' => 'Error',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => $message,
                            ),
                        );
                    }
                    else {
                        // Otherwise, store the line_num/value so it can be checked later on
                        $future_values[$dr_id][$child_dr_id][$df_id] = array(
                            'line' => $line_num,
                            'value' => $value
                        );
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
     * NOTE: this doesn't work for checking imports into linked datatypes
     *
     * @param array $data @see self::getUniquenessCheckData()
     * @param array $future_values @see self::getFutureUniqueValues()
     *
     * @return array
     */
    public function findUniquenessErrors($data, $future_values)
    {
        // Going to attempt to detect instances where the values in the csv file would violate
        //  uniqueness constraints
        $errors = array();

        // This function needs to flip the unique mapping so it's (df_id => column_num) instead
        $unique_columns = array_flip( $data['unique_mapping'] );

        if ( $data['import_into_top_level'] ) {
            // This is a top-level datatype
            $seen_values = array();
            foreach ($future_values as $dr_id => $df_data) {
                foreach ($df_data as $df_id => $csv_data) {
                    // NOTE - doing it this way allows newly created top-level datarecords to have
                    //  blank values in otherwise unique fields
                    $line_num = $csv_data['line'];
                    $value = $csv_data['value'];

                    if ( !isset($seen_values[$df_id][$value]) ) {
                        // Haven't seen this value before, keep looking
                        $seen_values[$df_id][$value] = array(
                            'prev_line' => $line_num,
                            'prev_dr_id' => $dr_id,
                        );
                    }
                    else {
                        // Have seen this value before, complain
                        $column_num = $unique_columns[$df_id];

                        // The error message needs to change based on whether it's a duplicate of an
                        //  existing datarecord or not
                        $prev_line_num = $seen_values[$df_id][$value]['prev_line'];
                        $prev_dr_id = $seen_values[$df_id][$value]['prev_dr_id'];
                        $prev_dr_value = array_search($prev_dr_id, $data['top_level_lookup']);

                        $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" on line '.$line_num.' is a duplicate of line '.$prev_line_num;
                        if ( is_numeric($prev_dr_id) )
                            $message = 'The field "'.$data['file_headers'][$column_num].'" is supposed to be unique, but the value "'.$value.'" already exists in Datarecord '.$prev_dr_id.' "'.$prev_dr_value.'"';

                        $errors[] = array(
                            'level' => 'Error',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => $message,
                            ),
                        );
                    }
                }
            }
        }
        else {
            // This is a child datatype
            foreach ($future_values as $parent_dr_id => $child_dr_ids) {
                // The values in the child records must be unique within the parent datatype
                $seen_values = array();
                foreach ($child_dr_ids as $child_dr_id => $df_data) {
                    foreach ($df_data as $df_id => $csv_data) {
                        $line_num = $csv_data['line'];
                        $value = $csv_data['value'];

                        //
                        if ( !isset($seen_values[$df_id][$value]) ) {
                            // Haven't seen this value before, keep looking
                            $seen_values[$df_id][$value] = array(
                                'prev_line' => $line_num,
//                                'prev_dr_id' => $parent_dr_id,
                                'prev_cdr_id' => $child_dr_id,
                            );
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

                            $errors[] = array(
                                'level' => 'Error',
                                'body' => array(
                                    'line_num' => $line_num,
                                    'message' => $message,
                                ),
                            );
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
     * @param array $data @see self::getUniquenessCheckData()
     * @param CsvReader $reader this needs to be pointing to the beginning of the csv file
     *
     * @return array
     */
    public function findDatarecordNumberErrors($data, $reader)
    {
        // Don't do anything if not importing into a child/linked datatype
        if ( $data['import_into_top_level'] )
            return array();

        $descendant_datatype_id = $data['import_datatype_id'];
        $ancestor_datatype_id = $data['parent_datatype_id'];

        // Don't do anything if the parent datarecord allows multiple child/linked descendants
        $datatree_array = $this->dti_service->getDatatreeArray();
        if ( isset($datatree_array['multiple_allowed'][$descendant_datatype_id])
            && in_array($ancestor_datatype_id, $datatree_array['multiple_allowed'][$descendant_datatype_id])
        ) {
            return array();
        }

        // Otherwise, need to determine whether the import will create multiple descendant records
        //  when there should only be at most one
        $errors = array();

        // May need the names of the ancestor/descendant datatypes for error reporting
        /** @var DataType $descendant_datatype */
        $descendant_datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($descendant_datatype_id);
        /** @var DataType $ancestor_datatype */
        $ancestor_datatype = $this->em->getRepository('ODRAdminBundle:DataType')->find($ancestor_datatype_id);


        // ----------------------------------------
        // Need to load the existing parent/child relations...
        $rel_type_adj = null;
        $existing_records = array();
        if ( $data['import_into_child_datatype'] ) {
            $rel_type_adj = 'child';
            $tmp = $this->search_service->getCachedSearchDatarecordList($descendant_datatype_id);

            // Need to flip the array so it's in (parent_id => child_id) format...since only a single
            //  descendant is allowed, no data will be lost by doing this
            $existing_records = array_flip($tmp);
        }
        else {
            $rel_type_adj = 'linked';
            $tmp = $this->search_service->getCachedSearchDatarecordList($descendant_datatype_id, true);

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
        $ancestor_lookup = $data['top_level_lookup'];
        $ancestor_external_id_column = $data['top_level_external_id_column'];
        $ancestor_external_id_field_name = $ancestor_datatype->getExternalIdField()->getFieldName();

        // The descendant datatype will have an external id field if it's a remote datatype, but a
        //  child datatype isn't guaranteed to have one
        $descendant_lookup = array();
        if ( !is_null($data['child_external_id_column']) )
            $descendant_lookup = $data['child_lookup'];
        $descendant_external_id_column = $data['child_external_id_column'];

        // Where there is no external id for a child datatype, the importer will overwrite existing
        //  child datarecords...there needs to be a check for whether the csv file attempts to
        //  overwrite the same child datarecord twice, however
        $overwritten_children = array();


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

                        $errors[] = array(
                            'level' => 'Warning',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => $message,
                            ),
                        );

                        // Store that a child will be overwritten for this ancestor
                        $overwritten_children[$ancestor_dr_id] = 1;
                    }
                    else {
                        // If this point is reached, the csv file has a duplicate entry for this
                        //  ancestor...should throw an error instead of a warning, since there's no
                        //  telling what the child record will end up with
                        $errors[] = array(
                            'level' => 'Error',
                            'body' => array(
                                'line_num' => $line_num,
                                'message' => 'The Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'" is listed multiple times in the csv file',
                            ),
                        );
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
                    $errors[] = array(
                        'level' => 'Error',
                        'body' => array(
                            'line_num' => $line_num,
                            'message' => 'The relationship between the ancestor Datatype "'.$ancestor_datatype->getShortName().'" and its '.$rel_type_adj.' Datatype "'.$descendant_datatype->getShortName().'" only permits a single descendant, but line '.$line_num.' in the csv file will create another descendant for the ancestor Datarecord with the "'.$ancestor_external_id_field_name.'" value "'.$ancestor_external_id_value.'"'
                        ),
                    );
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
}
