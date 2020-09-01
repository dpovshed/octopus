#!/usr/bin/env php
<?php
// application.php

require __DIR__.'/vendor/autoload.php';

use Octopus\Command\RunOctopusCommand;
use Symfony\Component\Console\Application;

$command = new RunOctopusCommand();
$application = new Application();
$application->add($command);
$application->setDefaultCommand($command->getName(), true);
$application->run();
