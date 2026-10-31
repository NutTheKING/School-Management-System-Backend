<?php
declare(strict_types=1);
define('PROJECT_ROOT', dirname(__DIR__));
define('PUBLIC_PATH', PROJECT_ROOT . '/public');
define('STORAGE_PATH', getenv('APP_STORAGE_PATH') ?: PROJECT_ROOT . '/storage');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Bangkok');
