<?php

/**
 * Open Data Repository Data Publisher
 * Clone Datatype Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This background process is used to clone an existing "Master Template" (really just a datatype
 * without datarecords), and all its associated themes and datafields into a new datatype.
 */

namespace ODR\AdminBundle\Command;

use HWI\Bundle\OAuthBundle\Tests\Fixtures\FOSUser;
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\CloneMasterDatatypeService;
use ODR\AdminBundle\Component\Service\DatarecordExportService;
use ODR\AdminBundle\Component\Service\DatatypeCreateService;
use ODR\AdminBundle\Component\Service\EntityCreationService;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\OpenRepository\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

// Services
use ODR\AdminBundle\Component\Service\CloneDatatypeService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
// Other
use Doctrine\ORM\EntityManager;
use \Doctrine\Common\Collections\Criteria;

use ODR\AdminBundle\Entity\DataType;


class CloneDatatypePreloaderCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_datatype:clone_datatype_preloader')
            ->setDescription('Creates a queue of cloned databases from existing templates.');
    }


    /**
     * This function is currently used only for AHED Project
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');

        while (true) {
            // Run command until manually stopped
            $job = null;
            try {
                // Watch for a job

                $current_time = new \DateTime();
                $output->writeln($current_time->format('Y-m-d H:i:s') . ' (UTC-5)');

                /** @var EntityManager $em */
                $em = $container->get('doctrine')->getEntityManager();

                // Check how many preloaded databases are in the system
                $output->writeln('Checking for preload databases');
                $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
                /** @var DataType $master_datatype */
                $master_datatype = $repo_datatype->find(670);


                // Try refresh to ensure up to date
                $em->refresh($master_datatype);


                /*
                // Delete old revisions that are not issued
                $query = $em->createQuery("
                        SELECT dt FROM ODRAdminBundle:DataType dt 
                        WHERE 
                        dt.masterDatatypeId = :master_datatype_id
                        and dt.preloadStatus LIKE :preload_term
                        and dt.preloadStatus IS NOT NULL
                        and dt.created < :create_date
                    ")
                    ->setParameter('master_datatype_id', $master_datatype->getId())
                    ->setParameter('preload_term', '\d+')
                    ->setParameter('create_date', (new \DateTime())->modify('-1 day'));

                $to_delete = $query->getArrayResult();

                print_r($to_delete);
                */


                /** @var DataType[] $datatypes */
                $datatypes = $repo_datatype->findBy([
                    'masterDataType' => 670,
                    'preload_status' => $master_datatype->getDataTypeMeta()->getMasterRevision()
                ]);


                $output->writeln('Preload revision needed: ' . $master_datatype->getDataTypeMeta()->getMasterRevision());
                $output->writeln('Found: ' . count($datatypes));

                // Detach this so we can query again on next pass.
                // $em->detach($master_datatype);

                // Maintain 10 of current revision
                if (count($datatypes) < 10) {

                    $user_manager = $container->get('fos_user.user_manager');

                    /** @var FOSUser $user */
                    $user = $user_manager->findUserBy(['id' => 1]);

                    /** @var DatatypeCreateService $dtc_service */
                    $dtc_service = $container->get('odr.datatype_create_service');
                    $datatype = $dtc_service->direct_add_datatype(
                        669,
                        0,
                        $user,
                        true,
                        null,
                        null,
                        null,
                        $master_datatype->getDataTypeMeta()->getMasterRevision()
                    );

                    /** @var CloneMasterDatatypeService $clone_datatype_service */
                    $clone_datatype_service = $this->getContainer()->get('odr.clone_master_datatype_service');
                    $result = $clone_datatype_service->createDatatypeFromMaster(
                        $datatype->getId(),
                        1,
                        $datatype->getTemplateGroup()
                    );

                    $output->writeln('Datatype creation complete.');

                    /** @var DataRecord $metadata_record */
                    $actual_data_record = $em->getRepository('ODRAdminBundle:DataRecord')
                        ->findOneBy(array('dataType' => $datatype->getId()));

                    if (!$actual_data_record) {
                        // A metadata datarecord doesn't exist...create one
                        /** @var EntityCreationService $entity_create_service */
                        $entity_create_service = $container->get('odr.entity_creation_service');

                        $user_manager = $container->get('fos_user.user_manager');
                        $odr_user = $user_manager->findUserBy(array('id' => $user->getId()));

                        $delay_flush = true;
                        $actual_data_record = $entity_create_service
                            ->createDatarecord($odr_user, $datatype, $delay_flush);

                        // Datarecord is ready, remove provisioned flag
                        $output->writeln('Metadata record completion creation complete.');
                        // TODO Naming is a little weird here
                        $actual_data_record->setProvisioned(false);
                        $em->flush();


                        // Call API to create cached version

                        /** @var CacheService $cache_service */
                        $cache_service = $container->get('odr.cache_service');
                        $data = $cache_service
                            ->get('json_record_' . $actual_data_record->getUniqueId());

                        if (!$data) {
                            $output->writeln('Creating JSON Record');

                            /** @var DatarecordExportService $dre_service */
                            $dre_service = $container->get('odr.datarecord_export_service');
                            $data = $dre_service->getData(
                                'v3',
                                array($actual_data_record->getId()),
                                'json',
                                true,
                                $odr_user,
                                $container->getParameter('site_baseurl'),
                                0
                            );

                            // Cache this data for faster retrieval
                            $output->writeln('Caching JSON Record');
                            $cache_service->set(
                                'json_record_' . $actual_data_record->getUniqueId(),
                                $data
                            );
                            $output->writeln('JSON Record Complete');
                        }
                    }
                    // Exit to reset cache...
                    exit();
                }

                // Sleep for a bit 10s
                usleep(10000000);
            } catch (\Throwable $e) {
                if ($e->getMessage() == 'retry') {
                    $output->writeln('Could not resolve host, releasing job to try again');
                    $logger->error('CloneDatatypePreloaderCommand.php: ' . $e->getMessage());
                } else {
                    $output->writeln($e->getMessage());
                    $logger->error('CloneDatatypePreloaderCommand.php: ' . $e->getMessage());
                }
                usleep(1000000);     // sleep for 1 second
            } catch (\Exception $e) {
                if ($e->getMessage() == 'retry') {
                    $output->writeln('Could not resolve host, releasing job to try again');
                    $logger->error('CloneDatatypePreloaderCommand.php: ' . $e->getMessage());
                    usleep(1000000);     // sleep for 1 second
                } else {
                    $output->writeln($e->getMessage());
                    $logger->error('CloneDatatypePreloaderCommand.php: ' . $e->getMessage());
                    usleep(1000000);     // sleep for 1 second
                }
            }
        }
    }
}
