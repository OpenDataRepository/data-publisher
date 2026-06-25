<?php

/**
 * Extracted verbatim (apart from the namespace) from the abandoned dterranova/crypto-bundle so ODR
 * keeps the EXACT same AES-256-CBC encryption format -- DO NOT change the algorithm, or existing
 * encrypted files become unreadable.
 */

namespace ODR\AdminBundle\Component\Crypto\Cipher;

use ODR\AdminBundle\Component\Crypto\CipherInterface;

class AES implements CipherInterface {


	public function __construct() {

	}

	public function encrypt($data, $key) {
		// Set a random salt
	    $salt = openssl_random_pseudo_bytes(8);

	    $salted = '';
	    $dx = '';
	    // Salt the key(32) and iv(16) = 48
	    while (strlen($salted) < 48) {
	    	$dx = md5($dx.$key.$salt, true);
	    	$salted .= $dx;
	    }

	    $_key = substr($salted, 0, 32);
	    $iv  = substr($salted, 32,16);

	    $encrypted_data = openssl_encrypt($data, 'aes-256-cbc', $_key, true, $iv);
	    return base64_encode('Salted__' . $salt . $encrypted_data);
	}

	public function decrypt($data, $key) {
		$_data = base64_decode($data);
	    $salt = substr($_data, 8, 8);
	    $ct = substr($_data, 16);
	    /**
	     * From https://github.com/mdp/gibberish-aes
	     *
	     * Number of rounds depends on the size of the AES in use
	     * 3 rounds for 256
	     *        2 rounds for the key, 1 for the IV
	     * 2 rounds for 128
	     *        1 round for the key, 1 round for the IV
	     * 3 rounds for 192 since it's not evenly divided by 128 bits
	     */
	    $rounds = 3;
	    $data00 = $key.$salt;
	    $md5_hash = array();
	    $md5_hash[0] = md5($data00, true);
	    $result = $md5_hash[0];
	    for ($i = 1; $i < $rounds; $i++) {
	    	$md5_hash[$i] = md5($md5_hash[$i - 1].$data00, true);
	        $result .= $md5_hash[$i];
	    }
	    $_key = substr($result, 0, 32);
	    $iv  = substr($result, 32,16);

	    return openssl_decrypt($ct, 'aes-256-cbc', $_key, true, $iv);
	}
}
