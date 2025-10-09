<?php

/**
 * Open Data Repository Data Publisher
 * Fieldtype Migration Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Fieldtype migration was originally not a big deal, but once changing that property for template
 * fields became a requirement...
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use ODR\AdminBundle\Entity\DataFields;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
// Events
// Services
use ODR\AdminBundle\Component\Utility\ValidUtility;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class FieldtypeMigrationService
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var CacheService
     */
    private $cache_service;

    /**
     * @var EventDispatcherInterface
     */
    private $event_dispatcher;

    // NOTE - $event_dispatcher is an instance of \Symfony\Component\Event\EventDispatcher in prod mode,
    //  and an instance of \Symfony\Component\Event\Debug\TraceableEventDispatcher in dev mode

    /**
     * @var Logger
     */
    private $logger;


    /**
     * FieldtypeMigrationService constructor
     *
     * @param EntityManager $entity_manager
     * @param CacheService $cache_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        CacheService $cache_service,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->cache_service = $cache_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;
    }


    /**
     * Returns a list of datarecord ids (and optionally their current values) that will have their
     * values changed if they get migrated to the given typeclass...this one handles migrating
     * Paragraph/Long/Medium varchars to Long/Medium/Short varchars.
     *
     * @param DataFields $datafield
     * @param string $new_typeclass
     * @param bool $return_values If true, then also return the values which will be truncated
     * @return array
     */
    public function ReportOnShorterTextConvert($datafield, $new_typeclass, $return_values = false)
    {
        $datafield_id = $datafield->getId();
        if ( $datafield->getIsMasterField() )
            throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnShorterTextConvert() called on master datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0x51ae1528);

        // This should only get called when coming from text fields
        $mapping = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $old_typeclass = $datafield->getFieldType()->getTypeClass();
        switch ($old_typeclass) {
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
                break;
            default:
                throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnShorterTextConvert() called on the '.$old_typeclass.'datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0x51ae1528);
        }

        $new_length = 0;
        if ( $new_typeclass == 'ShortVarchar' )
            $new_length = 32;
        else if ( $new_typeclass == 'MediumVarchar' )
            $new_length = 64;
        else if ( $new_typeclass == 'LongVarchar' )
            $new_length = 255;
        else
            throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnShorterTextConvert() called with the new typeclass '.$new_typeclass, 0x51ae1528);

        $query = '';
        if ( !$return_values ) {
            // Migration only cares about the records with values that won't "fit" into shorter
            //  typeclasses
            $query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.' AND LENGTH(e.value) > '.$new_length.'
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
        }
        else {
            // Reports want a link to the record's edit page and the before/after values
            $query =
               'SELECT gdr.id AS gdr_id, dr.id AS dr_id, e.value AS old_value, SUBSTR(e.value, 1, '.$new_length.') AS new_value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
        }
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query);

        $data = array();
        if ( !$return_values ) {
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $data[] = $dr_id;
            }
        }
        else {
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $gdr_id = $result['gdr_id'];
                $old_value = $result['old_value'];
                $new_value = $result['new_value'];

                $data[$dr_id] = array('gdr_id' => $gdr_id, 'old_value' => $old_value, 'new_value' => $new_value);
            }
        }

        return $data;
    }


    /**
     * Returns a list of datarecord ids (and optionally their current values) that will have their
     * values changed if they get migrated to an integer value.
     *
     * @param DataFields $datafield
     * @param bool $return_values If true, then also return the values which will be truncated
     * @return array
     */
    public function ReportOnIntegerConvert($datafield, $return_values = false)
    {
        $datafield_id = $datafield->getId();
        if ( $datafield->getIsMasterField() )
            throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnIntegerConvert() called on master datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0xe15e7ebf);

        // This should only get called when coming from text or decimal fields
        $mapping = array(
            'DecimalValue' => 'odr_decimal_value',
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $old_typeclass = $datafield->getFieldType()->getTypeClass();
        switch ($old_typeclass) {
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
            case 'DecimalValue':
                break;
            default:
                throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnIntegerConvert() called on the '.$old_typeclass.'datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0xe15e7ebf);
        }

        $conn = $this->em->getConnection();
        if ( !$return_values ) {
            // Migration only cares about the records with values that can't be converted to integer
            $query =
               'SELECT dr.id AS dr_id
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.' AND NOT REGEXP_LIKE(e.value, "'.ValidUtility::INTEGER_MIGRATE_REGEX.'")
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            // Need to double-escape the backslashes for mysql
            $query = str_replace("\\", "\\\\", $query);
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $data[] = $dr_id;
            }

            return $data;
        }
        else {
            // Reports want a link to the record's edit page and the before/after values...but we
            //  need two queries here...

            // ...the first to get all values in the field...
            $query =
               'SELECT gdr.id AS gdr_id, dr.id AS dr_id, e.value AS value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            $results = $conn->executeQuery($query);

            $data = array();
            foreach ($results as $result) {
                $gdr_id = $result['gdr_id'];
                $dr_id = $result['dr_id'];
                $old_value = $result['value'];

                $data[$dr_id] = array('gdr_id' => $gdr_id, 'old_value' => $old_value, 'new_value' => '', 'pass' => '');
            }

            // ...and the second to get the values that mysql can cast without throwing an exception
            $query =
               'SELECT gdr.id AS gdr_id, dr.id AS dr_id, CAST(e.value AS SIGNED) AS new_value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.' AND REGEXP_LIKE(e.value, "'.ValidUtility::INTEGER_MIGRATE_REGEX.'")
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            // Need to double-escape the backslashes for mysql
            $query = str_replace("\\", "\\\\", $query);
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $new_value = $result['new_value'];

                $data[$dr_id]['new_value'] = $new_value;
                $data[$dr_id]['pass'] = 1;
            }

            return $data;
        }
    }


    /**
     * Returns a list of datarecord ids (and optionally their current values) that will have their
     * values changed if they get migrated to an integer value.
     *
     * @param DataFields $datafield
     * @param bool $return_values If true, then also return the values which will be truncated
     * @return array
     */
    public function ReportOnDecimalConvert($datafield, $return_values = false)
    {
        $datafield_id = $datafield->getId();
        if ( $datafield->getIsMasterField() )
            throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnDecimalConvert() called on master datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0x01248acb);

        // This should only get called when coming from text fields
        $mapping = array(
            'ShortVarchar' => 'odr_short_varchar',
            'MediumVarchar' => 'odr_medium_varchar',
            'LongVarchar' => 'odr_long_varchar',
            'LongText' => 'odr_long_text',
        );

        $old_typeclass = $datafield->getFieldType()->getTypeClass();
        switch ($old_typeclass) {
            case 'LongText':
            case 'LongVarchar':
            case 'MediumVarchar':
            case 'ShortVarchar':
                break;
            default:
                throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnDecimalConvert() called on the '.$old_typeclass.'datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0x01248acb);
        }

        $conn = $this->em->getConnection();
        if ( !$return_values ) {
            // ----------------------------------------
            // Back when ODR was first designed, it used a beanstalkd queue to end up processing each
            //  individual value...in March 2022 (04b13dd), the migration got changed to attempt to
            //  use INSERT INTO...SELECT statements to greatly speed things up.

            // This worked until the need to convert values with tolerances...such as "5.260(2)"...
            //  into decimals.  The INSERT INTO...SELECT statements use mysql's CAST() function, which
            //  throws warnings on values which aren't numeric...and the warnings get automatically
            //  "upgraded" into errors, which kills the entire migration immediately.

            // Migration only cares about the records with values that can't be converted to decimal
            $query =
               'SELECT dr.id AS dr_id, gdr.id AS dr_id, e.value AS value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND NOT REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_A.'")
                AND NOT REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_B.'")
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            // Need to double-escape the backslashes for mysql
            $query = str_replace("\\", "\\\\", $query);
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $data[] = $dr_id;
            }

            return $data;
        }
        else {
            // Reports want a link to the record's edit page and the before/after values...but we
            //  need two queries here...

            // ...the first to get all values in the field...
            $query =
               'SELECT gdr.id AS gdr_id, dr.id AS dr_id, e.value AS value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            $results = $conn->executeQuery($query);

            $data = array();
            foreach ($results as $result) {
                $gdr_id = $result['gdr_id'];
                $dr_id = $result['dr_id'];
                $old_value = $result['value'];

                $data[$dr_id] = array('gdr_id' => $gdr_id, 'old_value' => $old_value, 'new_value' => '', 'pass' => '');
            }

            // Going to use CAST() repeatedly to get other data...
            // IMPORTANT: changes made here must also be transferred to WorkerController::migrateAction()
            $query =
               'SELECT dr.id AS dr_id, gdr.id AS gdr_id, CAST(SUBSTR(e.value, 1, 255) AS DOUBLE) AS new_value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_A.'")
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL
                AND df.deletedAt IS NULL';
            // Need to double-escape the backslashes for mysql
            $query = str_replace("\\", "\\\\", $query);
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $new_value = $result['new_value'];

                $data[$dr_id]['new_value'] = $new_value;
                $data[$dr_id]['pass'] = 1;
            }

            // IMPORTANT: changes made here must also be transferred to WorkerController::migrateAction()
            $query =
               'SELECT dr.id AS dr_id, gdr.id AS gdr_id, CAST(SUBSTR(e.value, 1, LOCATE("(",e.value)-1) AS DOUBLE) AS new_value
                FROM odr_data_record AS gdr
                JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
                JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
                JOIN odr_data_fields df ON drf.data_field_id = df.id
                JOIN '.$mapping[$old_typeclass].' AS e ON  e.data_record_fields_id = drf.id
                WHERE df.id = '.$datafield_id.'
                AND REGEXP_LIKE(e.value, "'.ValidUtility::DECIMAL_MIGRATE_REGEX_B.'")
                AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
                AND drf.deletedAt IS NULL AND e.deletedAt IS NULL';
            // Need to double-escape the backslashes for mysql
            $query = str_replace("\\", "\\\\", $query);
            $results = $conn->executeQuery($query);

            foreach ($results as $result) {
                $dr_id = $result['dr_id'];
                $new_value = $result['new_value'];

                if ( $data[$dr_id]['new_value'] !== '' )
                    continue;

                $data[$dr_id]['new_value'] = $new_value;
                $data[$dr_id]['pass'] = 2;
            }

            return $data;
        }
    }


    /**
     * Returns a list of datarecord ids that have multiple radio options currently selected, because
     * they would lose all but one selection when converted to a single radio/select.
     *
     * @param DataFields $datafield
     * @return array
     */
    public function ReportOnSingleRadioConvert($datafield)
    {
        $datafield_id = $datafield->getId();
        if ( $datafield->getIsMasterField() )
            throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnSingleRadioConvert() called on master datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0xabe873f5);

        $old_typeclass = $datafield->getFieldType()->getTypeClass();
        $old_typename = $datafield->getFieldType()->getTypeName();
        switch ($old_typename) {
            case 'Multiple Radio':
            case 'Multiple Select':
                break;
            default:
                throw new ODRBadRequestException('FieldtypeMigrationService::ReportOnSingleRadioConvert() called on the '.$old_typeclass.'datafield '.$datafield_id.' "'.$datafield->getFieldName().'"', 0xabe873f5);
        }

        // Unlike the other Convert functions in this service, there are no values to return
        $conn = $this->em->getConnection();

        $query =
           'SELECT dr.id AS dr_id, gdr.id AS gdr_id, ro.id AS ro_id, rs.selected
            FROM odr_data_record AS gdr
            JOIN odr_data_record AS dr ON dr.grandparent_id = gdr.id
            JOIN odr_data_record_fields AS drf ON drf.data_record_id = dr.id
            JOIN odr_data_fields df ON drf.data_field_id = df.id
            JOIN odr_radio_selection AS rs ON rs.data_record_fields_id = drf.id
            JOIN odr_radio_options AS ro ON rs.radio_option_id = ro.id
            WHERE df.id = '.$datafield_id.'
            AND gdr.deletedAt IS NULL AND dr.deletedAt IS NULL
            AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL
            AND df.deletedAt IS NULL';
        $results = $conn->executeQuery($query);

        $data = array();
        foreach ($results as $result) {
            $gdr_id = $result['gdr_id'];
            $dr_id = $result['dr_id'];
            $ro_id = $result['ro_id'];
            $selected = $result['selected'];

            if ( !isset($data[$dr_id]) )
                $data[$dr_id] = array('gdr_id' => $gdr_id, 'ro_list' => array());

            if ( $selected == 1 )
                $data[$dr_id]['ro_list'][$ro_id] = 1;
        }

        return $data;
    }
}
