<?php

/**
 * Open Data Repository Data Publisher
 * User Utility
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 */

namespace ODR\AdminBundle\Component\Utility;


class UserUtility
{

    /**
     * When passed the array version of a User entity, this function will scrub the
     * private/non-essential information from that array and return it.
     *
     * This is generally required whenever it's necessary to store who created/updated
     * something (datarecord, datafield, etc) via the cache service...the user entity returned by
     * doctrine has password and salt fields, which really shouldn't be cached...
     *
     * @param array $user_data
     *
     * @return array
     */
    static public function cleanUserData($user_data)
    {
        if ( !is_array($user_data) )
            $user_data = array();

        foreach ($user_data as $key => $value) {
            // Only want to keep the username, email, first, and last name from the array
            if ($key !== 'username'
                && $key !== 'email'
                && $key !== 'firstName'
                && $key !== 'lastName'
                //  && $key !== 'institution' && $key !== 'position'
            ) {
                unset( $user_data[$key] );
            }
        }

        return $user_data;
    }

}
