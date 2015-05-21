<?php
/**
 * @file the Octopus project main file.
 *
 */

/* @var \Composer\Autoload\ClassLoader $loader; */
require 'vendor/autoload.php';

require_once "Octopus/Config.php";
require_once "Octopus/Result.php";
require_once "Octopus/Handlers.php";
require_once "Octopus/Processor.php";
require_once "Octopus/TargetManager.php";

$config = new Octopus\Config;

$result = new Octopus\Result;
$handlers = new Octopus\Handlers($config, $result);
$targets = new Octopus\TargetManager($config, $result);
$processor = new Octopus\Processor($config, $targets);

try {
  $targets->populate();
  $processor->warmUp();
  // Fill up initial portion then go.
  $processor->spawnBundle();
}
catch (\Exception $e) {
  echo "Exception on init: " . $e->getMessage() . PHP_EOL;
  exit;
}

while ($targets->countQueue()) {
  $processor->run();
}

echo PHP_EOL . PHP_EOL . "Results:" . PHP_EOL;
ksort($processor::$statCodes);
foreach ($processor::$statCodes as $code => $count) {
  echo $code . ': ' . $count . PHP_EOL;
}

echo 'Total data: ' . Octopus\Processor::$totalData . PHP_EOL;

if ($config->outputBroken) {
  file_put_contents($config->outputDestination . '/broken.txt',
    implode("\n", array_keys($processor::$brokenUrls))
  );
}
