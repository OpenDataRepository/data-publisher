<?php

/**
 * Open Data Repository Data Publisher
 * CSVExport Monitor Command
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * This background process is used to ensure each CSVExport background job remains active...
 */

namespace ODR\AdminBundle\Command;

use ODR\AdminBundle\Command\ContainerAwareCommand;
use Doctrine\Common\Cache\ArrayCache;

// Services
use ODR\AdminBundle\Component\Service\CloneDatatypeService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
// Other
use drymek\PheanstalkBundle\Entity\Job;


class CSVExportMonitorCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_export:monitor')
            ->setDescription('Restarts the odr_csv_export processes after they exit.');
    }


    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $current_time = new \DateTime();
        $output->writeln( 'Starting odr_csv_export:monitor... ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
        while (true) {
            // Run command until manually stopped


            $pids = [];
            exec("ps auxww |grep 'odr_csv_export:worker_express'", $pids);
            $output->writeln( 'PIDS OUTPUT: ' . var_export($pids) );

            if ( count($pids) == 2 ) {
                // restart all three jobs only when all of them are dead
                exec("php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_1.log 2>&1 &");
                exec("php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_2.log 2>&1 &");
                exec("php app/console odr_csv_export:worker_express >> app/logs/export_worker_express_3.log 2>&1 &");

                $current_time = new \DateTime();
                $output->writeln( 'Restarting worker_express jobs... ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
            }


            $pids = [];
            exec("ps auxww |grep 'odr_csv_export:finalize_express'", $pids);
            $output->writeln( 'PIDS OUTPUT: ' . var_export($pids) );

            if ( count($pids) == 2 ) {
                // restart job
                exec("php app/console odr_csv_export:finalize_express >> app/logs/export_finalize_express.log 2>&1 &");

                $current_time = new \DateTime();
                $output->writeln( 'Restarting finalize_express job... ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
            }

            $current_time = new \DateTime();
            $output->writeln( 'Sleeping 60s: ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
            $output->writeln('');
            // Sleep 60s
            usleep(60000000);

        }
        return 0;
    }
}
