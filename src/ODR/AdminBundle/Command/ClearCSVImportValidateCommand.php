<?php

/**
* Open Data Repository Data Publisher
* ClearCSVImportValidate Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs from the
*  csv_import_validate tube.
*
*/

namespace ODR\AdminBundle\Command;

//use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// dunno if needed
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;

//class RefreshCommand extends Command
class ClearCSVImportValidateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_csv_import:clear_validate')
            ->setDescription('Deletes all jobs from the csv_import_validate tube')
            ->addOption('old', null, InputOption::VALUE_NONE, 'If set, prepends the redis_prefix to the tube name for deleting jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $pheanstalk = $container->get('pheanstalk');

        $redis_prefix = $container->getParameter('memcached_key_prefix');

        while (true) {
            // Wait for a job?
            if ($input->getOption('old'))
                $job = $pheanstalk->watch($redis_prefix.'_csv_import_validate')->ignore('default')->reserve();
            else
                $job = $pheanstalk->watch('csv_import_validate')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $datatype_id = $data->datatype_id;
            $job_source = $data->redis_prefix;

            // Dealt with the job
            $pheanstalk->delete($job);
if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted job for datatype '.$datatype_id.' from '.$redis_prefix.'_csv_import_validate');
else
    $output->writeln( date('H:i:s').'  deleted job for datatype '.$datatype_id.' ('.$job_source.') from csv_import_validate');

            // Sleep for a bit
            usleep(50000); // sleep for 0.05 seconds
        }

    }
}
