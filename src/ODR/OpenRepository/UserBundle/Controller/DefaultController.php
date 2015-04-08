<?php

namespace ODR\OpenRepository\UserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('ODROpenRepositoryUserBundle:Default:index.html.twig', array('name' => $name));
    }
}
