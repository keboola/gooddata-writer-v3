<?php

require_once __DIR__ . '/../vendor/autoload.php';

defined('GD_USERNAME') || define('GD_USERNAME', getenv('GD_USERNAME')? getenv('GD_USERNAME') : null);
defined('GD_PASSWORD') || define('GD_PASSWORD', getenv('GD_PASSWORD')? getenv('GD_PASSWORD') : null);
defined('GD_PID') || define('GD_PID', getenv('GD_PID')? getenv('GD_PID') : null);
