<?php

/**
* Open Data Repository Data Publisher
* Crypto Command
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* This Symfony console command takes beanstalk jobs from the
* crypto_requests tube and passes the parameters to WorkerController
* to either encrypt a file/image entity, or decrypt a file entity.
*
*/

namespace ODR\AdminBundle\Command;

// Services
use ODR\AdminBundle\Component\Service\CryptoService;
// Symfony
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class CryptoCommand extends ContainerAwareCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('odr_crypto:worker')
            ->setDescription('Encrypts/Decrypts a File or Image object');
    }


    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Only need to load these once...
        $container = $this->getContainer();
        $logger = $container->get('logger');
        $pheanstalk = $container->get('pheanstalk');

//        /** @var CryptoService $crypto_service */
//        $crypto_service = $this->getContainer()->get('odr.crypto_service');

        while (true) {
            // Run command until manually stopped
            $job = null;
            try {
                // Wait for a job?
                $job = $pheanstalk->watch('crypto_requests')->ignore('default')->reserve();

                /** @var CryptoService $crypto_service */
                $crypto_service = $this->getContainer()->get('odr.crypto_service');

                // Get Job Data
                $data = json_decode($job->getData()); 

                // 
                $logger->info('CryptoCommand.php: '.$data->crypto_type.' request for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'...');
                $current_time = new \DateTime();

                if ($data->crypto_type == 'encrypt') {
                    $output->writeln($current_time->format('Y-m-d H:i:s').' (UTC-5)');
                    $output->writeln($data->crypto_type.' request for '.$data->object_type.' '.$data->object_id.' from '.$data->redis_prefix.'...');
                }

                $object_type = $data->object_type;
                $object_id = $data->object_id;
                $target_filename = $data->target_filename;
                $crypto_type = $data->crypto_type;

                $archive_filepath = $data->archive_filepath;
                $desired_filename = $data->desired_filename;

                if ($archive_filepath !== '' && $desired_filename !== '') {
                    // This decrypts the specified file and stores it in a zip archive
                    $crypto_service->decryptFileForArchive($object_id, $target_filename, $desired_filename, $archive_filepath);
                }
                else if ($crypto_type == 'decrypt' && strtolower($object_type) == 'file') {
                    // This will probably be the most common use of this command
                    $crypto_service->decryptFile($object_id, $target_filename);
                }
                else if ($crypto_type == 'decrypt' && strtolower($object_type) == 'image') {
                    // This one will probably not be used much at all...
                    // Usually, these are decrypted inline for immediate viewing
                    $crypto_service->decryptImage($object_id, $target_filename);
                }
                else {
                    // TODO - move encryption into the crypto service as well...

                    // Need to use cURL to send a POST request
                    $ch = curl_init();

                    // Create the required parameters to send
                    $parameters = array(
                        'object_type' => $data->object_type,
                        'object_id' => $data->object_id,
                        'target_filename' => $data->target_filename,
                        'crypto_type' => $data->crypto_type,

                        'archive_filepath' => $data->archive_filepath,
                        'desired_filename' => $data->desired_filename,

                        'api_key' => $data->api_key
                    );

                    // Set the options for the POST request
                    curl_setopt_array($ch,
                        array(
                            CURLOPT_POST => 1,
                            CURLOPT_HEADER => 0,
                            CURLOPT_URL => $data->url,
                            CURLOPT_FRESH_CONNECT => 1,
                            CURLOPT_RETURNTRANSFER => 1,
                            CURLOPT_FORBID_REUSE => 1,
                            CURLOPT_TIMEOUT => 0,   // TODO - actual timeout value instead of "never"?
                            CURLOPT_POSTFIELDS => http_build_query($parameters)
                        )
                    );

                    // Send the request
                    if (!$ret = curl_exec($ch)) {
                        if (curl_errno($ch) == 6) {
                            // Could not resolve host
                            throw new \Exception('retry');
                        }
                        else {
                            throw new \Exception(curl_error($ch));
                        }
                    }

                    // Do things with the response returned by the controller?
                    $result = json_decode($ret);
                    if (isset($result->r) && isset($result->d)) {
                        if ($result->r == 0) {
                            if ($data->crypto_type == 'encrypt')
                                $output->writeln($result->d);
                        }
                        else {
                            throw new \Exception($result->d);
                        }
                    }
                    else {
                        // Should always be a json return...
                        throw new \Exception(print_r($ret, true));
                    }
//$logger->debug('EncryptCommand.php: curl results...'.print_r($result, true));

                    // Done with this cURL object
                    curl_close($ch);
                }

                // Dealt with the job
                $pheanstalk->delete($job);

                // Sleep for a bit
                usleep(200000);
            }
            catch (\Exception $e) {
                if ( $e->getMessage() == 'retry' ) {
                    $output->writeln( 'Could not resolve host, releasing job to try again' );
                    $logger->err('CryptoCommand.php: '.$e->getMessage());

                    // Release the job back into the ready queue to try again
                    $pheanstalk->release($job);

                    // Sleep for a bit
                    usleep(1000000);     // sleep for 1 second
                }
                else {
                    $output->writeln($e->getMessage());

                    $logger->err('CryptoCommand.php: '.$e->getMessage());

                    // Delete the job so the queue doesn't hang, in theory
                    $pheanstalk->delete($job);
                }
            }
        }
    }
}
