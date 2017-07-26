#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Octopus\Command\RunOctopusCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new RunOctopusCommand());
$application->run();