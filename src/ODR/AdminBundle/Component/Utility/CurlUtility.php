<?php


namespace ODR\AdminBundle\Component\Utility;


class CurlUtility
{
    public $request;
    private $debug = false;
    private $time = false;
    private $headers = [];

    /**
     * CurlUtility constructor.
     * @param $url
     * @param $headers
     * @param bool $debug
     * @param bool $time
     * @param $func
     */
    public function __construct($url, $headers, $debug = false, $time = false, $func = null)
    {

        $this->debug = $debug;
        $this->time = $time;
        $this->headers = $headers;
        $this->func = $func;

        // initialise the curl request
        $this->request = curl_init($url);

        // send a file
        curl_setopt($this->request, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->request, CURLOPT_RETURNTRANSFER, true);

    }

    public $start_time = 0;
    public function exec_time($start = true) {
       if($start) {
           $this->start_time = microtime(true);
       }
       else {
           fwrite(STDERR, $this->func . ' Time: ' . round((microtime(true) - $this->start_time) * 1000, 0) . 'ms');
       }
    }


    public function post($post_data, $file_name = null) {
        curl_setopt($this->request, CURLOPT_POST, true);
        curl_setopt($this->request, CURLOPT_FOLLOWLOCATION, true);

        if($file_name !== null) {
            ($this->debug ? fwrite(STDERR, $file_name) : '');
            $curl_file = '@' . realpath($file_name);
            if (function_exists('curl_file_create')) { // php 5.5+
                $curl_file = curl_file_create($file_name);
            }
            $post_data['file'] = $curl_file;
        }

        curl_setopt(
            $this->request,
            CURLOPT_POSTFIELDS,
            $post_data
        );

        if($this->time) $this->exec_time();
        $response = curl_exec($this->request);
        if($this->time) $this->exec_time(false);
        ($this->debug ? fwrite(STDERR, print_r($response)) : '');

        $http_status = curl_getinfo($this->request, CURLINFO_HTTP_CODE);
        ($this->debug ? fwrite(STDERR, $http_status) : '');

        // close the session
        curl_close($this->request);

        return array(
            'code' => $http_status,
            'response' => $response
        );
    }

    public function put($post_data) {
        array_push($this->headers, 'Content-Type: application/json', 'Content-Length: ' . strlen($post_data));
        curl_setopt($this->request, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($this->request, CURLOPT_CUSTOMREQUEST, 'PUT');

        // Curl PUT can't follow redirects - don't use FOLLOWLOCATION
        // curl_setopt($this->request, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt(
            $this->request,
            CURLOPT_POSTFIELDS,
            $post_data
        );

        if($this->time) $this->exec_time();
        $response = curl_exec($this->request);
        if($this->time) $this->exec_time(false);
        ($this->debug ? fwrite(STDERR, print_r($response)) : '');

        $http_status = curl_getinfo($this->request, CURLINFO_HTTP_CODE);
        ($this->debug ? fwrite(STDERR, $http_status) : '');

        // close the session
        curl_close($this->request);

        return array(
            'code' => $http_status,
            'response' => $response
        );
    }





}