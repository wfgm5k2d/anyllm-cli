<?php

require_once __DIR__ . '/vendor/autoload.php';

use AnyllmCli\Command\RunCommand;

$app = new RunCommand();
$app->run();
