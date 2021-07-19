<?php

/**
 * Open Data Repository Data Publisher
 * Chemin Analog Plugin
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Due to design decisions, "auto-incrementing" of ID fields for databases is going to be handled
 * via render plugins.  As such, a plugin is needed to hold the logic for generating an ID once a
 * datarecord has been created.
 */

namespace ODR\OpenRepository\GraphBundle\Plugins\Chemin;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\OpenRepository\GraphBundle\Plugins\DatatypePluginInterface;
// Events
use ODR\AdminBundle\Component\Event\DatarecordCreatedEvent;
// Exceptions
use ODR\AdminBundle\Exception\ODRException;
// Services
use ODR\AdminBundle\Component\Service\DatabaseInfoService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Component\Service\LockService;
use ODR\OpenRepository\SearchBundle\Component\Service\SearchCacheService;
// Symfony
use Doctrine\ORM\EntityManager;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;


class CheminAnalogPlugin implements DatatypePluginInterface
{

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var DatabaseInfoService
     */
    private $dbi_service;

    /**
     * @var EntityCreationService
     */
    private $ec_service;

    /**
     * @var LockService
     */
    private $lock_service;

    /**
     * @var SearchCacheService
     */
    private $search_cache_service;

    /**
     * @var CsrfTokenManager
     */
    private $token_manager;

    /**
     * @var EngineInterface
     */
    private $templating;

    /**
     * @var Logger
     */
    private $logger;


    /**
     * CheminAnalogPlugin constructor.
     *
     * @param EntityManager $entity_manager
     * @param DatabaseInfoService $database_info_service
     * @param EntityCreationService $entity_creation_service
     * @param LockService $lock_service
     * @param SearchCacheService $search_cache_service
     * @param CsrfTokenManager $token_manager
     * @param EngineInterface $templating
     * @param Logger $logger
     */
    public function __construct(
        EntityManager $entity_manager,
        DatabaseInfoService $database_info_service,
        EntityCreationService $entity_creation_service,
        LockService $lock_service,
        SearchCacheService $search_cache_service,
        CsrfTokenManager $token_manager,
        EngineInterface $templating,
        Logger $logger
    ) {
        $this->em = $entity_manager;
        $this->dbi_service = $database_info_service;
        $this->ec_service = $entity_creation_service;
        $this->lock_service = $lock_service;
        $this->search_cache_service = $search_cache_service;
        $this->token_manager = $token_manager;
        $this->templating = $templating;
        $this->logger = $logger;
    }


    /**
     * Returns whether the plugin can be executed in the current context.
     *
     * @param array $render_plugin_instance
     * @param array $datatype
     * @param array $rendering_options
     *
     * @return bool
     */
    public function canExecutePlugin($render_plugin_instance, $datatype, $rendering_options)
    {
        // This render plugin is only allowed to work when in fake_edit mode
        if ( isset($rendering_options['context']) && $rendering_options['context'] === 'fake_edit' )
            return true;

        return false;
    }


    /**
     * Executes the CheminAnalog Plugin on the provided datarecords
     *
     * @param array $datarecords
     * @param array $datatype
     * @param array $render_plugin_instance
     * @param array $theme_array
     * @param array $rendering_options
     * @param array $parent_datarecord
     * @param array $datatype_permissions
     * @param array $datafield_permissions
     * @param array $token_list
     *
     * @return string
     * @throws \Exception
     */
    public function execute($datarecords, $datatype, $render_plugin_instance, $theme_array, $rendering_options, $parent_datarecord = array(), $datatype_permissions = array(), $datafield_permissions = array(), $token_list = array())
    {
        try {
            // ----------------------------------------
            // Extract various properties from the render plugin array
            $fields = $render_plugin_instance['renderPluginMap'];
            $options = $render_plugin_instance['renderPluginOptionsMap'];

            // Retrieve mapping between datafields and render plugin fields
            $rpf_df_id = null;
            $plugin_fields = array();
            foreach ($fields as $rpf_name => $rpf_df) {
                // Need to find the real datafield entry in the primary datatype array
                $rpf_df_id = $rpf_df['id'];

                $df = null;
                if ( isset($datatype['dataFields']) && isset($datatype['dataFields'][$rpf_df_id]) )
                    $df = $datatype['dataFields'][$rpf_df_id];

                if ($df == null)
                    throw new \Exception('Unable to locate array entry for the field "'.$rpf_name.'", mapped to df_id '.$rpf_df_id);

                // The "Database ID" field needs to be handled differently
                $plugin_fields[$rpf_df_id] = $rpf_name;
            }


            // ----------------------------------------
            // The datatype array shouldn't be wrapped with its ID number here...
            $initial_datatype_id = $datatype['id'];

            // The theme array is stacked, so there should be only one entry to find here...
            $initial_theme_id = '';
            foreach ($theme_array as $t_id => $t)
                $initial_theme_id = $t_id;

            // There *should* only be a single datarecord in $datarecords...
            $datarecord = array();
            foreach ($datarecords as $dr_id => $dr)
                $datarecord = $dr;

            // Also need to provide a special token so the "Database ID" field won't get ignored
            //  by FakeEdit because it prevents user edits...
            $token_id = 'FakeEdit_'.$datarecord['id'].'_'.$rpf_df_id.'_autogenerated';
            $token = $this->token_manager->getToken($token_id)->getValue();
            $special_tokens[$rpf_df_id] = $token;


            // ----------------------------------------
            if ( isset($rendering_options['context']) && $rendering_options['context'] === 'fake_edit' ) {
                // When in fake_edit mode, use the plugin's override
                return $this->templating->render(
                    'ODROpenRepositoryGraphBundle:Chemin:CheminAnalog/cheminanalog_fakeedit_fieldarea.html.twig',
                    array(
                        'datatype_array' => array($initial_datatype_id => $datatype),
                        'datarecord_array' => array($datarecord['id'] => $datarecord),
                        'theme_array' => $theme_array,

                        'target_datatype_id' => $initial_datatype_id,
                        'parent_datarecord' => $parent_datarecord,
                        'target_datarecord_id' => $datarecord['id'],
                        'target_theme_id' => $initial_theme_id,

                        'datatype_permissions' => $datatype_permissions,
                        'datafield_permissions' => $datafield_permissions,

                        'is_top_level' => $rendering_options['is_top_level'],
                        'is_link' => $rendering_options['is_link'],
                        'display_type' => $rendering_options['display_type'],

                        'token_list' => $token_list,
                        'special_tokens' => $special_tokens,

                        'plugin_fields' => $plugin_fields,
                    )
                );
            }
            else {
                // Otherwise, if this gets called for some reason, just return empty string so ODR's
                //  regular templating takes over
                return '';
            }
        }
        catch (\Exception $e) {
            // Just rethrow the exception
            throw $e;
        }
    }


    /**
     * Handles when a datarecord is created.
     *
     * @param DatarecordCreatedEvent $event
     */
    public function onDatarecordCreate(DatarecordCreatedEvent $event)
    {
        // Pull some required data from the event
        $user = $event->getUser();
        $datarecord = $event->getDatarecord();
        $datatype = $datarecord->getDataType();

        // Need to locate the "database_id" field for this render plugin...
        $query = $this->em->createQuery(
           'SELECT df
            FROM ODRAdminBundle:RenderPlugin rp
            JOIN ODRAdminBundle:RenderPluginInstance rpi WITH rpi.renderPlugin = rp
            JOIN ODRAdminBundle:RenderPluginMap rpm WITH rpm.renderPluginInstance = rpi
            JOIN ODRAdminBundle:DataFields df WITH rpm.dataField = df
            JOIN ODRAdminBundle:RenderPluginFields rpf WITH rpm.renderPluginFields = rpf
            WHERE rp.pluginClassName = :plugin_classname AND rpi.dataType = :datatype
            AND rpf.fieldName = :field_name
            AND rp.deletedAt IS NULL AND rpi.deletedAt IS NULL AND rpm.deletedAt IS NULL
            AND df.deletedAt IS NULL'
        )->setParameters(
            array(
                'plugin_classname' => 'odr_plugins.chemin.chemin_analog',
                'datatype' => $datatype->getId(),
                'field_name' => 'Database ID'
            )
        );
        $results = $query->getResult();
        if ( count($results) !== 1 )
            throw new ODRException('Unable to find the "Database ID" field for the RenderPlugin "Chemin Analog", attached to Datatype '.$datatype->getId());

        // Will only be one result, at this point
        $datafield = $results[0];
        /** @var DataFields $datafield */


        // ----------------------------------------
        // Need to acquire a lock to ensure that there are no duplicate values
        $lockHandler = $this->lock_service->createLock('datatype_'.$datatype->getId().'_autogenerate_id'.'.lock', 15);    // 15 second ttl
        if ( !$lockHandler->acquire() ) {
            // Another process is in the mix...block until it finishes
            $lockHandler->acquire(true);
        }

        // Now that a lock is acquired, need to find the "most recent" value for the field that is
        //  getting incremented...
        $old_value = self::findCurrentValue($datafield->getId());

        // Extract the numeric part of the "most recent" value, and add 1 to it
        $val = intval( substr($old_value, 2) );
        $val += 1;

        // Convert it back into the expected format so the storage entity can get created
        $new_value = 'CA'.str_pad($val, 5, '0', STR_PAD_LEFT);
        $this->ec_service->createStorageEntity($user, $datarecord, $datafield, $new_value);

        // No longer need the lock
        $lockHandler->release();


        // ----------------------------------------
        // Not going to mark the datarecord as updated, but still need to do some other cache
        //  maintenance because a datafield value got changed...

        // If the datafield that got changed was the datatype's sort datafield, delete the cached datarecord order
        if ( $datatype->getSortField() != null && $datatype->getSortField()->getId() == $datafield->getId() )
            $this->dbi_service->resetDatatypeSortOrder($datatype->getId());

        // Delete any cached search results involving this datafield
        $this->search_cache_service->onDatafieldModify($datafield);
    }


    /**
     * For this database, it is technically possible to use SortService::sortDatarecordsByDatafield()
     * However, there could be values that don't match the correct format /^CA[0-9]{5,5}$/, so it's
     * safer to specifically find the values that match the correct format.
     *
     * Don't particularly like random render plugins finding random stuff from the database, but
     * there's no other way to satisfy the design requirements.
     *
     * @param int $datafield_id
     * @return string
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function findCurrentValue($datafield_id)
    {
        // Going to use native SQL...DQL can't use limit without using querybuilder...
        $query =
           'SELECT e.value
            FROM odr_short_varchar e
            WHERE e.data_field_id = :datafield AND e.value REGEXP "^CA[0-9]{5,5}$"
            AND e.deletedAt IS NULL
            ORDER BY e.value DESC
            LIMIT 0,1';
        $params = array(
            'datafield' => $datafield_id,
        );
        $conn = $this->em->getConnection();
        $results = $conn->executeQuery($query, $params);

        // Should only be one value in the result...
        $current_value = null;
        foreach ($results as $result)
            $current_value = $result['value'];
        // ...but if there's not for some reason, use this value as the "current".  onDatarecordCreate()
        //  will increment it so that the value "CA00001" is what will actually get saved.
        if ( is_null($current_value) )
            $current_value = 'CA00000';

        return $current_value;
    }
}
