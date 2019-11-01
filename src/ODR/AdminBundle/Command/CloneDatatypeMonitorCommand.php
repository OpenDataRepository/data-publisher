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

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Doctrine\Common\Cache\ArrayCache;

// Services
use ODR\AdminBundle\Component\Service\CloneDatatypeService;
// Symfony
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
// Other
use drymek\PheanstalkBundle\Entity\Job;


class CloneDatatypeMonitorCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_datatype:clone_monitor')
            ->setDescription('Restarts the odr_datatype:clone process after it exits.');
    }


    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $current_time = new \DateTime();
        $output->writeln( 'Starting clone_monitor: ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
        while (true) {
            // Run command until manually stopped


            $pids = array();
            exec("ps auxww |grep 'odr_datatype:clone_master'", $pids);

            $output->writeln( 'PIDS OUTPUT: ' . var_export($pids));

            if(count($pids) == 2) {
                // start job
                exec("php app/console odr_datatype:clone_master >> app/logs/datatype_create.log 2>&1 &");
                $current_time = new \DateTime();
                $output->writeln( 'Restarting clone_master: ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
            }

            $current_time = new \DateTime();
            $output->writeln( 'Sleeping 10s: ' . $current_time->format('Y-m-d H:i:s').' (UTC-5)' );
            // Sleep 5s
            usleep(5000000);

        }
    }
}
