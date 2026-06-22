<?php

namespace ODR\OpenRepository\ApiBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
{
    public function indexAction($name)
    {
        return $this->render('@ODROpenRepositoryApi/Default/index.html.twig', ['name' => $name]);
    }
}
