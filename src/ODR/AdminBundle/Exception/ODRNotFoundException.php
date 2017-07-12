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
     * @param string $message
     * @param boolean $exact  If true, print out $message exactly as given
     */
    public function __construct($message, $exact = false)
    {
        if (!$exact)
            $message = 'This '.$message.' has been deleted.';

        parent::__construct($message, self::getStatusCode());
    }


    /**
     * @inheritdoc
     */
    public function getStatusCode()
    {
        return 404;
    }
}
