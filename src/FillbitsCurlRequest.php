<?php
require('FillbitsValidator.php');

class FillbitsCurlRequest
{
    private $public_key = '';
    private $username = '';
    private $password = '';
    private $format = 'json';
    private $curl_handle;

    public function __construct($public_key, $username, $password, $format)
    {
        $this->public_key = $public_key;
        $this->username = $username;
        $this->password = $password;
        $this->format = $format;
        $this->curl_handle = null;
    }

    public function __destruct() {
        if ($this->curl_handle !== null) {
            // Close the cURL session
            curl_close($this->curl_handle);
        }
    }

    /**
     * Executes a cURL request to the Fillbits.ncom API.
     *
     * @param string $command The API command to call.
     * @param array $fields The required and optional fields to pass with the command.
     *
     * @return array Result data with error message. Error will equal "ok" if call was a success.
     *
     * @throws Exception If an invalid format is passed.
     */
    public function execute($command, $method, array $fields = [])
    {
        // Define the API url
        $api_url = 'https://imsba.com/api/v2/crypto/';
        // Validate the passed fields
        $validator = new FillbitsValidator($command, $fields);
        $validate_fields = $validator->validate();
        if (strpos($validate_fields, 'Error') !== FALSE) {
            echo $validate_fields;
            exit();
        } else {
            // Set default field values
//            $fields['version'] = 1;
//            $fields['format'] = $this->format;
//            $fields['key'] = $this->public_key;
//            $fields['cmd'] = $command;

            // Throw an error if the format is not equal to json or xml
//            if ($fields['format'] != 'json') {
//                return ['error' => 'Invalid response format passed. Please use "json" as a format value'];
//            }
//print_r($fields);exit;
            // Generate query string from fields
            $post_fields = http_build_query($fields, '', '&');

            // Generate the HMAC hash from the query string and private key
//            $hmac = hash_hmac('sha512', $post_fields, $this->private_key);

            // Check the cURL handle has not already been initiated
            if ($this->curl_handle === null) {
                $api_url .= $command . '?key=' . $this->public_key;

                // Initiate the cURL handle and set initial options
                $this->curl_handle = curl_init($api_url);
                curl_setopt($this->curl_handle, CURLOPT_FAILONERROR, TRUE);
                curl_setopt($this->curl_handle, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($this->curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
                if(strtoupper($method) == 'POST') {
                    curl_setopt($this->curl_handle, CURLOPT_POST, TRUE);
                    // Set HTTP POST fields for cURL
                    curl_setopt($this->curl_handle, CURLOPT_POSTFIELDS, $post_fields);
                }
            }

            // Set HMAC header for cURL
            curl_setopt($this->curl_handle, CURLOPT_HTTPHEADER, array());


            // Execute the cURL session
            $response = curl_exec($this->curl_handle);

            // Check the response of the cURL session
            if ($response !== FALSE) {
                $result = false;

                // Check the requested format
//                if ($fields['format'] == 'json') {

                // Prepare json result
                if (PHP_INT_SIZE < 8 && version_compare(PHP_VERSION, '5.4.0') >= 0) {
                    // We are on 32-bit PHP, so use the bigint as string option.
                    // If you are using any API calls with Satoshis it is highly NOT recommended to use 32-bit PHP
                    $decoded = json_decode($response, TRUE, 512, JSON_BIGINT_AS_STRING);
                } else {
                    $decoded = json_decode($response, TRUE);
                }

                // Check the json decoding and set an error in the result if it failed
                if ($decoded !== NULL && count($decoded)) {
                    $result = $decoded;
                } else {
                    $result = ['error' => 'Unable to parse JSON result (' . json_last_error() . ')'];
                }
//                } else {
//                    // Set the result to the response
//                    $result = $response;
//                }
            } else {
                // Throw an error if the response of the cURL session is false
                $result = ['error' => 'cURL error: ' . curl_error($this->curl_handle)];
            }
            return $result;
        }
    }
}
