<?php

declare(strict_types = 1);

/* @var \Composer\Autoload\ClassLoader $loader; */
$loader = require __DIR__ . '/vendor/autoload.php';

$config = new Octopus\Config;
$result = new Octopus\Result;
$handlers = new Octopus\Handlers($config, $result);
$targets = new Octopus\TargetManager($config, $result);
$processor = new Octopus\Processor($config, $targets);

try {
  $targets->populate();
  $processor->warmUp();
  $processor->spawnBundle(); // Fill up initial portion then go.
}
catch (\Exception $e) {
  echo 'Exception on initialization: ' . $e->getMessage() . PHP_EOL;
  exit;
}

while ($targets->countQueue()) {
  $processor->run();
}

echo PHP_EOL . PHP_EOL . 'Results:' . PHP_EOL;
ksort($processor::$statCodes);
foreach ($processor::$statCodes as $code => $count) {
  echo $code . ': ' . $count . PHP_EOL;
}

echo 'Total data: ' . Octopus\Processor::$totalData . PHP_EOL;

if ($config->outputBroken) {
  file_put_contents($config->outputDestination . '/broken.txt',
    implode(PHP_EOL, array_keys($processor::$brokenUrls))
  );
}
