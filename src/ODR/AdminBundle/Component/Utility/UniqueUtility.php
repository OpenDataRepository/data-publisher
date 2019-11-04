<?php

/**
 * Open Data Repository Data Publisher
 * User Utility
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 */

namespace ODR\AdminBundle\Component\Utility;


class UniqueUtility
{

    /**
     * Generates a unique id of a certain length.  Need to check
     * database to ensure uniqueness.
     *
     * @param int $length
     * @return bool|string
     */
    static public function uniqueIdReal($length = 7)
    {
        // uniqid gives 13 chars, but you could adjust it to your needs.
        if (function_exists("random_bytes")) {
            $bytes = random_bytes(ceil($length / 2));
        }
        elseif (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
        }
        else {
            throw new \Exception("no cryptographically secure random function available");
        }
        return substr(bin2hex($bytes), 0, $length);
    }

}
