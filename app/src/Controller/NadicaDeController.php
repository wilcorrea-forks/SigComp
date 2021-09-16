<?php

namespace App\Controller;

use Slim\Http\Request;
use Slim\Http\Response;

class NadicaDeController
{
  private $container;

  public function __construct(\Container $container)
  {
      $this->container = $container;
  }

  public function nada(Request $request, Response $response, $args)
  {
    $this->container->view['name'] = 'Agatha';
    $this->container->view['labels'] = ['Red', 'Blue', 'Yellow', 'Green', 'Purple', 'Orange'];
    $this->container->view['datasets'] = [
      [
        'label' => '# of Votes',
        'data' => [12, 19, 3, 5, 2, 3],
        'backgroundColor' => [
              'rgba(255, 99, 132, 0.2)',
              'rgba(54, 162, 235, 0.2)',
              'rgba(255, 206, 86, 0.2)',
              'rgba(75, 192, 192, 0.2)',
              'rgba(153, 102, 255, 0.2)',
              'rgba(255, 159, 64, 0.2)'
        ],
        'borderColor' => [
              'rgba(255, 99, 132, 1)',
              'rgba(54, 162, 235, 1)',
              'rgba(255, 206, 86, 1)',
              'rgba(75, 192, 192, 1)',
              'rgba(153, 102, 255, 1)',
              'rgba(255, 159, 64, 1)'
        ],
        'borderWidth' => 1
      ]
    ];
    return $this->container->view->render($response, 'nadica-de-nada.tpl');
  }
}