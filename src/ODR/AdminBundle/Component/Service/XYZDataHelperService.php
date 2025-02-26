<?php

/**
 * Open Data Repository Data Publisher
 * XYZData Helper Service
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * XYZ Data...like file, image, radio, and tag fields...has multiple "entries" per datarecord/datafield
 * pair.  Unlike the other fieldtypes though, XYZData typically wants to modify a bunch of its
 * "entries" at the same time, and which ones to modify typically depends on what already exists.
 *
 * As such, it's better to have the logic off in its own service.
 */

namespace ODR\AdminBundle\Component\Service;

// Entities
use Doctrine\ORM\EntityManager;
use ODR\AdminBundle\Component\Utility\ValidUtility;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\XYZData;
use ODR\OpenRepository\UserBundle\Entity\User as ODRUser;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class XYZDataHelperService
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var EntityCreationService
     */
    private $entity_create_service;

    /**
     * @var EntityMetaModifyService
     */
    private $entity_modify_service;

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
     * XYZDataHelperService constructor.
     *
     * @param EntityManager $entity_manager
     * @param EntityCreationService $entity_creation_service
     * @param EntityMetaModifyService $entity_meta_modify_service
     * @param EventDispatcherInterface $event_dispatcher
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        EntityCreationService $entity_creation_service,
        EntityMetaModifyService $entity_meta_modify_service,
        EventDispatcherInterface $event_dispatcher,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->entity_create_service = $entity_creation_service;
        $this->entity_modify_service = $entity_meta_modify_service;
        $this->event_dispatcher = $event_dispatcher;
        $this->logger = $logger;
    }


    /**
     * Modifies the XYZData entities for a given datarecord/datafield pair to have the given value.
     *
     * This is a sort of wrapper function in front of the actual updating...there could be dozens
     * or hundreds of XYZData entries for a given datarecord/datafield pair, and the given value
     * could create/modify/delete any number of them.
     *
     * IMPORTANT: $created is not optional.  It's required to ensure that dozens/hundreds of XYZData
     * entities are created/modified "at the same time"...otherwise tracking doesn't work correctly.
     *
     * @param ODRUser $user
     * @param DataRecord $datarecord
     * @param DataFields $datafield
     * @param \DateTime $created
     * @param string $value
     * @param bool $replace_all If true, then delete every XYZData entity prior to saving
     *
     * @return bool true if changes were made, false otherwise
     */
    public function updateXYZData($user, $datarecord, $datafield, $created, $value, $replace_all = false)
    {
        $exception_source = 0x9c006bfd;

        $expected_num_columns = count( explode(',', $datafield->getXyzDataColumnNames()) );

        $new_data = array();

        if ( $value !== '' ) {
            $points = explode('|', $value);
            $count = 1;
            foreach ($points as $point) {
                $pieces = explode(',', substr($point, 1, -1));
                $x_value = null;

                if ( !isset($pieces[0]) )
                    throw new ODRBadRequestException('Missing x_value for point #'.$count, $exception_source);
                else if ( !ValidUtility::isValidDecimal($pieces[0]) )
                    throw new ODRBadRequestException('Invalid x_value for point #'.$count, $exception_source);
                else {
                    $x_value = strval(floatval($pieces[0]));
                    $new_data[$x_value]['x_value'] = $x_value;
                }

                if ( $expected_num_columns > 1 ) {
                    if ( !isset($pieces[1]) )
                        throw new ODRBadRequestException('Missing y_value for point #'.$count, $exception_source);
                    else if ( !ValidUtility::isValidDecimal($pieces[1]) )
                        throw new ODRBadRequestException('Invalid y_value for point #'.$count, $exception_source);
                    else
                        $new_data[$x_value]['y_value'] = strval(floatval($pieces[1]));
                }

                if ( $expected_num_columns > 2 ) {
                    if ( !isset($pieces[2]) )
                        throw new ODRBadRequestException('Missing z_value for point #'.$count, $exception_source);
                    else if ( !ValidUtility::isValidDecimal($pieces[2]) )
                        throw new ODRBadRequestException('Invalid z_value for point #'.$count, $exception_source);
                    else
                        $new_data[$x_value]['z_value'] = strval(floatval($pieces[2]));
                }

                $count++;
            }
        }

        // Now that the new data is valid, it makes sense to get the existing data...
        /** @var XYZData[] $xyz_data_values */
        $xyz_data_values = $this->em->getRepository('ODRAdminBundle:XYZData')->findBy(
            array(
                'dataRecord' => $datarecord->getId(),
                'dataField' => $datafield->getId(),
            )
        );

        $xyz_lookup = array();
        $old_data = array();
        foreach ($xyz_data_values as $xyz_data) {
            // This field should always have an x_value...use it as the key of the array
            $x_value = strval($xyz_data->getXValue());
            $old_data[$x_value]['x_value'] = $x_value;
            $xyz_lookup[$x_value] = $xyz_data;

            if ( $expected_num_columns > 1 )
                $old_data[$x_value]['y_value'] = strval($xyz_data->getYValue());
            if ( $expected_num_columns > 2 )
                $old_data[$x_value]['z_value'] = strval($xyz_data->getZValue());
        }


        // ----------------------------------------
        // Go through both old and new arrays to determine if there's any difference
        $entries_to_create = $entries_to_modify = $entries_to_delete = array();
        foreach ($old_data as $x_value => $data) {
            if ( !isset($new_data[$x_value]) || $replace_all ) {
                $entries_to_delete[$x_value] = 1;
                unset( $old_data[$x_value] );
            }
        }

        if ( !empty($new_data) ) {
            foreach ($new_data as $x_value => $data) {
                if ( !isset($old_data[$x_value]) ) {
                    $entries_to_create[$x_value] = array();
                    if ( isset($data['y_value']) )
                        $entries_to_create[$x_value]['y_value'] = $data['y_value'];
                    if ( isset($data['z_value']) )
                        $entries_to_create[$x_value]['z_value'] = $data['z_value'];
                    unset( $new_data[$x_value] );
                }
            }

            // At this point, old and new data should have an identical set of x_value keys...
            foreach ($old_data as $x_value => $data) {
                $old_y_value = $old_z_value = null;
                $new_y_value = $new_z_value = null;

                if ( $expected_num_columns > 1 ) {
                    $old_y_value = $data['y_value'];
                    $new_y_value = $new_data[$x_value]['y_value'];
                }

                if ( $expected_num_columns > 2 ) {
                    $old_z_value = $data['z_value'];
                    $new_z_value = $new_data[$x_value]['z_value'];
                }

                if ( !($old_y_value == $new_y_value && $old_z_value == $new_z_value) ) {
                    $entries_to_modify[$x_value] = array();
                    if ( !is_null($new_y_value) )
                        $entries_to_modify[$x_value]['y_value'] = $new_y_value;
                    if ( !is_null($new_z_value) )
                        $entries_to_modify[$x_value]['z_value'] = $new_z_value;
                }
            }
        }


        // ----------------------------------------
        // Unlike other entities, there could be dozens/hundreds of XYZData entries for a given
        //  datarecordfield entry...creating/modifying a pile of them could easily require multiple
        //  seconds to save, which would break tracking and field history

        // Rather than also save a "tracking_id" or "transaction_id", it's simpler to force the caller
        //  to provide a DateTime object...with the hope that they reuse that object when creating
        //  or updating multiple XYZData entries
        $created_date = $created;

        // Use the three arrays of changes to modify the database
        $created = $modified = $deleted = false;

        // ----------------------------------------
        // Deleting entries comes first because of $replace_all...
        foreach ($entries_to_delete as $x_value => $data) {
            $deleted = true;

            $entity = $xyz_lookup[$x_value];
            $this->em->remove($entity);
        }
        // ...need to flush if anything got deleted
        if ( $deleted )
            $this->em->flush();

        // ----------------------------------------
        // New entries can then get created
        $batch_values = array();
        foreach ($entries_to_create as $x_value => $data) {
            $created = true;

            if ($expected_num_columns == 1 )
                $batch_values[] = array('x' => $x_value);
            else if ($expected_num_columns == 2 )
                $batch_values[] = array('x' => $x_value, 'y' => $data['y_value']);
            else if ($expected_num_columns == 3 )
                $batch_values[] = array('x' => $x_value, 'y' => $data['y_value'], 'z' => $data['z_value']);
        }

        if ( !empty($batch_values) ) {
            $this->entity_create_service->createXYZValue_batch($user, $datarecord, $datafield, $created_date, $batch_values);
            // TODO - should PostUpdateEvent fire?  it's only listened to by render plugins...
        }

        // ...don't need to flush here, createXYZValue_batch() does it to maintain lock integrity

        // ----------------------------------------
        // Existing entries can then get modified
        foreach ($entries_to_modify as $x_value => $data) {
            $modified = true;

            $entity = $xyz_lookup[$x_value];
            $props = array('x_value' => $x_value);
            if ( isset($data['y_value']) )
                $props['y_value'] = $data['y_value'];
            if ( isset($data['z_value']) )
                $props['z_value'] = $data['z_value'];

            $this->entity_modify_service->updateXYZData($user, $entity, $created_date, $props, true);
            // TODO - should PostUpdateEvent fire?  it's only listened to by render plugins...
        }
        // ...do need to flush out here
        if ( $modified )
            $this->em->flush();

        // Return whether any changes were made
        return $created || $modified || $deleted;
    }
}
