<?php

/**
 * Open Data Repository Data Publisher
 * ODRNotFound Exception
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * Wrapper class to get Symfony to return a 404 error.
 */

namespace ODR\AdminBundle\Exception;


class ODRNotFoundException extends ODRException
{

    /**
     * ODRNotFoundException constructor.
     *
     * @param string $message
     * @param bool $exact      If true, print out $message exactly as given
     * @param int $source
     */
    public function __construct($message, $exact = false, $source = 0)
    {
        if (!$exact)
            $message = "This ".$message." can't be found.";

        parent::__construct($message, self::getStatusCode(), $source);
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 404;
    }
}
