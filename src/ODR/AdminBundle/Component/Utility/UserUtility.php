<?php
/**
 * ODR Office: UserUtility
 *
 * Author: Nate Stone
 * Email: nate@stoneumbrella.com
 * Date: 9/27/17 12:41 PM
 *
 * Copyright 2017 - My Circle Health
 *
 */

namespace ODR\AdminBundle\Component\Utility;


class UserUtility
{

    /**
     * When passed the array version of a User entity, this function will scrub the private/non-essential information
     * from that array and return it.
     *
     * @param array $user_data
     *
     * @return array
     */
    static public function cleanUserData($user_data) {
        if(!is_array($user_data)) {
            $user_data = array();
        }
        foreach ($user_data as $key => $value) {
            if ($key !== 'username'
                && $key !== 'email'
                && $key !== 'firstName'
                && $key !== 'lastName'
                //  && $key !== 'institution' && $key !== 'position'
            )
                unset( $user_data[$key] );
        }

        return $user_data;

    }

}