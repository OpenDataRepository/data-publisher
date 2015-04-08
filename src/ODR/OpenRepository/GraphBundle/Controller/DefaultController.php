<?php

namespace ODR\OpenRepository\GraphBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('ODROpenRepositoryGraphBundle:Default:index.html.twig', array('name' => $name));
    }
}
