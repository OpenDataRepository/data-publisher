<?php 

namespace ODR\OpenRepository\UserBundle\Model;

use FOS\UserBundle\Entity\UserManager;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class CustomUserManager extends UserManager
{
    public function loadUserByUsername($email)
    {
        /*$user = $this->findUserByUsernameOrEmail($username);

        if (!$user) {
            throw new UsernameNotFoundException(sprintf('No user with name "%s" was found.', $username));
        }

        return $user;*/

        //Change it to only email (Default calls loadUserByUsername -> we send it to our own loadUserByEmail)
        return $this->loadUserByEmail($email);
    }

    public function loadUserByEmail($email)
    {
        $user = $this->findUserByEmail($email);

        if (!$user) {
            throw new UsernameNotFoundException(sprintf('No user with email "%s" was found.', $email));
        }

        return $user;

    }
}
