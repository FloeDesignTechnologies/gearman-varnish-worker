<?php

require '../vendor/autoload.php';
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
$app = new Silex\Application();

$app['debug'] = true;

// Register Gearman client as Service.
$app['gearman_client'] = function () {
  $client= new GearmanClient();
  $client->addServers('127.0.0.1:4730');
  return $client;
};

// Let's use Twig for templates.
$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path'       => __DIR__.'/views',
  //'twig.class_path' => __DIR__.'/vendor/twig/lib',
));
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
// Display (cacheable) date.
$app->get('/date', function(Request $request) use($app) {
  $output = $app['twig']->render('date.twig', array(
    'now' => date('r'),
  ));
  return new Response($output, 200, array(
    'Cache-Control' => 'public, max-age=3600',
  ));
})
->bind('date');

// Ban /date from Varnish cache
$app->post('/date', function(Request $request) use ($app) {
  $app['gearman_client']->doBackground('varnish_ban_url', '^' . $app['url_generator']->generate('date') . '$');
  return $app->redirect($app['url_generator']->generate('date'));
});

$app->run();
