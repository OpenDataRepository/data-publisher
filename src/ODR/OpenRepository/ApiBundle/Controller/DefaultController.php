<?php

namespace ODR\OpenRepository\ApiBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction($name)
    {
        return $this->render('ODROpenRepositoryApiBundle:Default:index.html.twig', array('name' => $name));
    }
}
