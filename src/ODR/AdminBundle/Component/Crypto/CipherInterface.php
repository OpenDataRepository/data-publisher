<?php

/**
 * Extracted from the abandoned dterranova/crypto-bundle (incompatible with Symfony 5) so ODR keeps
 * the EXACT same file-encryption format -- existing encrypted files must remain decryptable.
 * Code is preserved verbatim apart from the namespace.
 */

namespace ODR\AdminBundle\Component\Crypto;

interface CipherInterface {

	public function encrypt($data, $key);
	public function decrypt($data, $key);


}
