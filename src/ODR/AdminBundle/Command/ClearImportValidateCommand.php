<?php

/**
* Open Data Repository Data Publisher
* ClearImportValidate Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command deletes beanstalk jobs created to
* validate uploaded XML files on the server prior to importing.
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
class ClearImportValidateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_import:clear_validate')
            ->setDescription('Deletes all jobs from the validate_import tube')
            ->addOption('old', null, InputOption::VALUE_NONE, 'If set, prepends the memcached_prefix to the tube name for deleting jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $pheanstalk = $container->get('pheanstalk');
        $memcached_prefix = $container->getParameter('memcached_key_prefix');

        while (true) {
            // Wait for a job?
            if ($input->getOption('old'))
                $job = $pheanstalk->watch($memcached_prefix.'_validate_import')->ignore('default')->reserve(); 
            else
                $job = $pheanstalk->watch('validate_import')->ignore('default')->reserve(); 

            $data = json_decode($job->getData());
            $datatype_id = $data->datatype_id;

            // Dealt with the job
            $pheanstalk->delete($job);

if ($input->getOption('old'))
    $output->writeln( date('H:i:s').'  deleted job for xml file "'.$data->xml_filename.'" of datatype '.$datatype_id.' from '.$memcached_prefix.'_validate_import');
else
    $output->writeln( date('H:i:s').'  deleted job for xml file "'.$data->xml_filename.'" of datatype '.$datatype_id.' from validate_import');

            // Sleep for a bit
            usleep(100000); // sleep for 0.1 seconds
        }

    }
}
