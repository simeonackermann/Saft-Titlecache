<?php

/*
# Call the TitleCache from Terminal or Browser:

- may load config from yaml file
- may write results
- return as json

## via Terminal:

    php index.php --action=create

    php index.php --action=get --uris=http://example.org/1,http://example.org/2

## via Browser (see TitelCache.php for more examples)

    http://localhost/titlecache/?action=create

    http://localhost/?action=get&uris=http://example.org/1,http://example.org/2

## optionally parameter:

    --action-from=path-to-yaml-file

    --results=path-to-results-folder
*/

require 'vendor/autoload.php';
require 'TitleCache.php';

use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

// available options for call via terminal
if ( isset($argv) ) {
    $opts = getopt("", 
        array(  "action::",
                "uris::",
                "graph::",
                "action-from::", 
                "results::"
        )
    );

    $_REQUEST = $opts;
}

$result = array();
// may get folder for results
$resultsDir = (isset($_REQUEST['results'])) ? $_REQUEST['results'] : null;


function createError( $msg = "" ) {
    return array( "status" => "error", "data" => NULL, "message" => $msg );
}


function loadFromFile() {

    $file = $_REQUEST["action-from"];

    if ( ! file_exists( $file ) ) {
        return createError( "Unable to open file: " . $file );
    }

    $yaml = new Parser();
    try {
        $value = $yaml->parse(file_get_contents($file));
    } catch (ParseException $e) {
        return createError( "Unable to parse the YAML string: " . $e->getMessage() );
    }

    if ( ! isset($value["actions"]) ) {
        return createError( "No action paramater (get or create) given in file: ".  $file );
    }

    $actions = $value["actions"];

    // walk given actions, call TitleCache for each
    foreach ($actions as $i => $action) {
        $config = isset($action["config"]) ? $action["config"] : array();

        // may get uris from urifile
        if ( isset($action["uris-from"]) ) {
            $urifile = $action["uris-from"];

            if ( ! file_exists($urifile) ) {
                return createError( "Urifile not found: ". $urifile );
            }
            $action["uris"] = explode( "\n" , file_get_contents($urifile));
        }

        $_REQUEST = $action;

        $TitleCache = new TitleCache($config);
        $result[] = $TitleCache->run();
    }

    return $result;
}

function load() {

    if ( isset($_FILES["uris-from"]) ) {
        $_REQUEST["uris"] = explode("\n", file_get_contents( $_FILES["uris-from"]["tmp_name"] ));
    } elseif ( isset($_REQUEST['uris-from']) && ! empty($_REQUEST['uris-from']) ) {
        $urifile = $_REQUEST["uris-from"];

        if ( ! file_exists($urifile) ) {
            return createError( "Urifile not found: ". $urifile );
        }
        $_REQUEST["uris"] = explode( "\n" , file_get_contents($urifile));
    }

    $TitleCache = new TitleCache();
    return $TitleCache->run();
}


// get actions yaml file
if ( isset($_REQUEST["action-from"]) ) {
    $result = loadFromFile();
} else {
    // get action directly from request
    $result[] = load();
}

// may write results
if ( isset( $resultsDir ) ) {

    foreach ($result as $i => $res) {
        $yamlResult = Yaml::dump($res, 2);
        file_put_contents( $resultsDir . '/result-' . $i . '.txt' , $yamlResult);
    }
}

// output result
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Content-Type: application/json');
echo json_encode($result);