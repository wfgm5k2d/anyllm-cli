<?php

require_once __DIR__ . '/vendor/autoload.php';

use AnyllmCli\Application\RunCommand;

$app = new RunCommand();
$app->run();
