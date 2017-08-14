#!/usr/bin/env php
<?php
$failSilent = true;
// Argument parsing
if (empty($_SERVER['argv'][1])) {
    if (!$failSilent) {
        echo "Usage: {$_SERVER['argv'][0]} (site-docroot)\n";
    }
    exit(1);
}
$basePath = $_SERVER['argv'][1];
if ($basePath[0] != '/') {
    $basePath = getcwd() . '/' . $basePath;
}

if (!file_exists($basePath)) {
    if (!$failSilent) {
        echo "Error: Path not found - $basePath";
    }
    exit(1);
}

// SilverStripe bootstrap
$basePath = rtrim($basePath, '/');
define('BASE_PATH', $basePath);
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.0';

chdir(BASE_PATH);
if (file_exists(BASE_PATH . '/sapphire/core/Core.php')) {
    //SS 2.x
    require_once(BASE_PATH . '/sapphire/core/Core.php');
} else {
    if (file_exists(BASE_PATH . '/framework/core/Core.php')) {
        //SS 3.x
        require_once(BASE_PATH . '/framework/core/Core.php');
    } else {
        if (file_exists(BASE_PATH . '/framework/src/Core/Core.php')) {
            //SS 4.x
            require_once(BASE_PATH . '/vendor/autoload.php');
            require_once(BASE_PATH . '/framework/src/Core/Core.php');
        } else {
            echo "Couldn't locate framework's Core.php. Perhaps " . BASE_PATH . " is not a SilverStripe project?\n";
            exit(2);
        }
    }
}

global $databaseConfig;

// We don't have a session in cli-script, but this prevents errors
$_SESSION = null;
global $_FILE_TO_URL_MAPPING;
$baseURL = $_FILE_TO_URL_MAPPING[rtrim($basePath, '/')];
@define('BASE_URL', $baseURL);
Config::inst()->update('Director', 'alternate_base_url', $baseURL);
$basePath = $basePath;

require_once("model/DB.php");

// Connect to database
if (!isset($databaseConfig) || !isset($databaseConfig['database']) || !$databaseConfig['database']) {
    echo "\nPlease configure your database connection details.  You can do this by creating a file
called _ss_environment.php in either of the following locations:\n\n";
    echo " - " . $basePath . DIRECTORY_SEPARATOR . "_ss_environment.php\n - ";
    echo dirname($basePath) . DIRECTORY_SEPARATOR . "_ss_environment.php\n\n";
    echo <<<ENVCONTENT

Put the following content into this file:
--------------------------------------------------
<?php

/* Change this from 'dev' to 'live' for a production environment. */
define('SS_ENVIRONMENT_TYPE', 'dev');

/* This defines a default database user */
define('SS_DATABASE_SERVER', 'localhost');
define('SS_DATABASE_USERNAME', '<user>');
define('SS_DATABASE_PASSWORD', '<password>');
define('SS_DATABASE_NAME', '<database>');
--------------------------------------------------

Once you have done that, run 'composer install' or './framework/sake dev/build' to create
an empty database.

For more information, please read this page in our docs:
http://docs.silverstripe.org/en/getting_started/environment_management/


ENVCONTENT;
    exit(1);
}
DB::connect($databaseConfig);


// Get the request URL from the querystring arguments
$url = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : null;
if (!$url) {
    echo 'Please specify an argument to cli-script.php/sake. For more information, visit'
        . ' http://docs.silverstripe.org/en/developer_guides/cli' . "\n";
    die();
}


$_SERVER['REQUEST_URI'] = BASE_URL . '/' . $url;

// Direct away - this is the "main" function, that hands control to the apporopriate controller
DataModel::set_inst(new DataModel());
require_once('ModuleMetrics.php');
echo ModuleMetrics::inst()->toJson();
echo "\n";

