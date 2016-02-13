<?php

/*
# RDF Title Cache 

Simple memcache based title cache for virtuoso (realized with [Saft](http://safting.github.io/) and [Nette](https://github.com/nette/caching)).

## Start

1. copy that file in a folder of your webserver, e.g. `YOUR_WEBROOT/titlecache/index.php`
2. execute composer update
3. after that, it should create and get the title-cache via GET parameter.

### Create Cache

To create the cache call:

     http://localhost/titlecache/?action=create

Optionally give a graph uri to change the default graph (`http://example.org/`):

    http://localhost/titlecache/?action=create&graph=http://your-graph-uri.org/

### Get Cache

To retrieve the cached titles call:

     http://localhost/titlecache/?action=get&uris=http://your-uri-1.org,http://your-uri1.org

with comma seperated uri-list. Optionally give a language to set the default language (en):

    http://localhost/titlecache/?action=get&uris=http://your-uri-1.org,http://your-uri1.org&lang=de

The result should be a json encoded array like:

     { "http://your-uri1.org" : "Title 1", "http://your-uri2.org" : "Title 2" }
*/

require 'vendor/autoload.php';

use Saft\Addition\Virtuoso\Store\Virtuoso;
use Saft\Rdf\NodeFactoryImpl;
use Saft\Rdf\StatementFactoryImpl;
use Saft\Rdf\StatementIteratorFactoryImpl;
use Saft\Sparql\Query\QueryFactoryImpl;
use Saft\Sparql\Query\QueryUtils;
use Saft\Sparql\Result\ResultFactoryImpl;
use Nette\Caching\Cache;
use Nette\Caching\Storages\MemcachedStorage;
use Nette\Caching\Storages\SQLiteStorage;
use Nette\Caching\Storages\MongoDBStorage;
use Nette\Caching\Storages\APCStorage;
use Nette\Caching\Storages\ElasticsearchStorage;
use Nette\Caching\Storages\RedisStorage;

/**
 * This class implements the title cache for virtuoso
 *
 * @author Simeon Ackermann
 */
class TitleCache
{
    /**
     * Default Settings. Can be overwritten by create this class like: new TitleCache( array( "graph" => ... ) );
     *
     * @var array
     */
    private $config = array(
        // set your store, possible values: virtusos
        "store" => array(
            "backend" => "virtuoso",
            "virtuoso" => array(
                "dsn" => "VOS",
                "username" => "dba",
                "password" => "dba",
            )
        ),

        // set the cache-abckend. Possible values: memcached|sqlite|mongodb|apc|elasticsearch|redis
        // and its configuration (host|port|path)
        "cache" => array(
            "backend" => "memcached",

            "memcached" => array(
                "host" => "localhost",
                "port" => 11211
                ),
            "sqlite" => array(
                "path" => '/tmp/cache.sq3' // we cannot take ':memory:' as path, because its not persistant!
                ),
            "mongodb" => array(
                "host" => "localhost",
                "port" => 27017
                ),
            "apc" => array(), // no configuration needed for apc
            "elasticsearch" => array(
                "host" => "localhost",
                "port" => 9200
                ),
            "redis" => array(
                "host" => "localhost",
                "port" => 6379
            )
        )
    );

    /**
     * The available uris for titles.
     * The order in this array defines the range on select title. That means: the first entry 0 => ... is the default title-uri
     * NOTE: if you change this range, the cache must be re-created!
     *
     * @var array
     */
    private $title_uris = array(
        0 => "http://purl.org/dc/elements/1.1/title",
        1 => "http://www.w3.org/2000/01/rdf-schema#label",
        2 => "http://purl.org/dc/terms/title",
        3 => "http://purl.org/dc/terms/alternative",
        4 => "http://udfr.org/onto#documentTitle",
        5 => "http://www.w3.org/2004/02/skos/core#prefLabel",
        6 => "http://www.w3.org/2004/02/skos/core#altLabel",
        7 => "http://www.w3.org/2004/02/skos/core#hiddenLabel"
    );

    /**
     * The default graph for create/get actions
     *
     * @var string
     */
    private $graph = "http://example.org/";

    /**
     * The default language for titles
     *
     * @var string  Values should be: en, de, fr, ...
     */
    private $default_lang = "en";

    /**
     * The requested language
     *
     * @var string
     */
    private $lang = Null;

    /**
     * The requetsed action (create|get)
     *
     * @var string
     */
    private $action = Null;

    /**
     * The requested uris
     *
     * @var array
     */
    private $uris = array();


    /**
     * The cache object
     *
     * @var Cache
     */
    private $cache = Null;

    /**
     * Constructor
     *
     * @param array     Overites the default configuration
     * @return echo     echo JSON result
     */
    function __construct($config=array())
    {                
        // may set graph
        if (isset($_REQUEST['graph']) && ! empty($_REQUEST['graph'])) {
            $this->graph = $_REQUEST['graph'];
        }

        if (isset($_REQUEST['action']) && ! empty($_REQUEST['action'])) {
            $this->action = $_REQUEST['action'];
        }
        
        if ( isset($_REQUEST['uris']) && ! empty($_REQUEST['uris'])) {

            if ( is_array($_REQUEST['uris']) ) {
                $this->uris = $_REQUEST['uris'];
            } else {
                $this->uris = explode(",", $_REQUEST['uris']);
            }            
        }

        if ( isset($_REQUEST['lang']) && ! empty($_REQUEST['lang'])) {
            $this->lang = $_REQUEST['lang'];
        }
        
        // may get config and merge with the defaults
        if ( isset($config) && ! empty($config) ) {
            $this->config = array_replace_recursive($this->config, $config);
        }
    }

    /**
     * Runs the app and echo json header
     *
     * @return array JSON Result
     */
    public function run( )
    {
        $result = array();

        if ( NULL == $this->action ) {
            return $this->createError( "No or empty action paramter given" );
        }

        $backend = $this->config["cache"]["backend"];
        $host = isset($this->config["cache"][$backend]["host"]) ? $this->config["cache"][$backend]["host"] : NULL;
        $port = isset($this->config["cache"][$backend]["port"]) ? $this->config["cache"][$backend]["port"] : NULL;
        $path = isset($this->config["cache"][$backend]["path"]) ? $this->config["cache"][$backend]["path"] : NULL;

        // initiate cache backend
        switch ($backend) {
            case 'memcached':
                $this->cache = new Cache( new MemcachedStorage($host, $port) );
                break;

            case 'sqlite':
                if (!extension_loaded('pdo_sqlite')) {
                    return $this->createError( 'Requires PHP pdo_sqlite for SQLite as cache backend' );
                }
                $this->cache = new Cache(new SQLiteStorage($path));
                break;

            case 'mongodb':
                $this->cache = new Cache(new MongoDBStorage($host, $port));
                break;

            case 'apc':
                $this->cache = new Cache(new APCStorage());
                break;

            case 'elasticsearch':
                $this->cache = new Cache(new ElasticsearchStorage($host, $port));
                break;

            case 'redis':
                $this->cache = new Cache(new RedisStorage($host, $port));
                break;
            
            default:
                return $this->createError( 'Unknown cache backend type "'.$backend.'"' );
                break;
        }

        // choose action
        switch ($this->action) {
            case 'get':                
                $result = $this->getTitles();                
                break;

            case 'create':                
                $result = $this->createCache();                
                break;
            
            default:
                return $this->createError( 'Unknown action paramter "'.$this->action.'" given. Try "get" or "create".' );
                break;
        }

        return $result;
    }

    /**
     * Create a error message
     *
     * @param string    Error message
     * @return array 
     */
    private function createError( $msg = Null ) {
        return $this->createResult( "error", Null, $msg );
    }

    /**
     * Create a result message
     *
     * @param string    $type   Status type (success|error)
     * @param mixed     $data   Data
     * @param mixed     $msg    Error message
     * @return array 
     */
    private function createResult( $type = "success", $data = Null, $msg = Null ) {
        return array( "status" => $type, "data" => $data, "message" => $msg );
    }

    /**
     * Get titles by given uris from cache
     *
     * @return array    The titles
     */
    private function getTitles()
    {        
        if ( empty($this->uris) ) {
            return $this->createError( "No uris given. Add some uri comma-seperated like: \"?create=get&uris=http://your-uri-1.org,http://your-uri-2.org\"" );
            break;
        }
        
        $createdOrUpdatedStats = $this->graphCacheStats();
        if ( false == $createdOrUpdatedStats ) {
            return $this->createError( 'Cannot get the cache for graph "'.$this->graph.'". It does not exists. Choose another graph or create the cache first by calling this file like: ?action=create&graph=' . $this->graph );
        }

        $titles = array();
        $lang = ( NULL == $this->lang ) ? $this->default_lang : $this->lang ;

        foreach ($this->uris as $uri ) {
            $title = NULL;
            $titleDefLang = NULL;
            $titleObjs = $this->cache->load( $this->graph . "." . $uri );

            if ( NULL != $titleObjs ) {
                
                foreach ($titleObjs["titles"] as $key => $titleObj) {

                    if ( isset($titleObj['lang']) ) {

                        if ( $titleObj["lang"] == $lang ) {
                            $title = $titleObj["value"];
                            break;
                        }

                        if ( $titleDefLang == NULL && $lang != $this->default_lang
                            && $titleObj["lang"] == $this->default_lang ) 
                        {
                            $titleDefLang = $titleObj["value"];
                        }

                    }
                }

                if ( empty($title) ) {

                    if ( ! empty($titleDefLang) ) {
                        $title = $titleDefLang;
                    } else {
                        $title = array_shift( $titleObjs["titles"] )["value"];
                    }
                }
            }
            $titles[ $uri ] = $title;            
        }
        return $this->createResult( "success", $titles );
    }

    /**
     * Initiate the store
     *
     * @return store|boolean    Instance of store or false
     */
    private function initiateStore()
    {
        $store = NULL;
        switch ($this->config["store"]["backend"]) {
            case 'virtuoso':
                // initiate virtuoso
                $store = new Virtuoso(
                    new NodeFactoryImpl(),
                    new StatementFactoryImpl(),
                    new QueryFactoryImpl(),
                    new ResultFactoryImpl(),
                    new StatementIteratorFactoryImpl(),
                    $this->config["store"]['virtuoso']
                );
                break;

            // TODO ...
            
            default:
                $store = false;
                break;
        }

        return $store;
    }

    /**
     * Create the cache
     *
     * @return array    Result array (counts, config, ...)
     */    
    private function createCache()
    {
        $counts = 0;
        $startTime = microtime(true);
        $titles = array();
        $store = $this->initiateStore();
        
        if ( ! $store ) {
            return $this->createError( 'Store "'.$this->config["store"]["backend"].'" initiating failed. May wrong config or your store is not running...?' );
        }

        // test if graph exists
        $sql = "ASK WHERE { GRAPH <".$this->graph."> { ?s ?p ?o } }";
        $queryResult = $store->query($sql);
        if ( false == $queryResult->getValue() ) {
            return $this->createError( 'Cannot create the cache: graph "'.$this->graph.'" does not exists in your store. Choose another graph or create it in your store.' );
        }

        // sparql query
        $filter = "FILTER ( ?p = <" . implode("> || ?p = <", $this->title_uris) . "> )";        
        $sql = "SELECT ?s ?p ?o FROM <" . $this->graph . "> WHERE { ?s ?p ?o . " . $filter . " }";
        $queryResult = $store->query($sql);

        // fetch result and create the titles
        foreach ($queryResult as $key => $entry) {
            // may titles are not literals? skip
            if ( ! $entry["o"]->isLiteral() ) {
                continue;
            }                

            $s = (string)$entry["s"];
            $p = (string)$entry["p"];
            $o = (string)$entry["o"];
            $lang = (string)($entry["o"]->getLanguage());

            if ( ! array_key_exists($s, $titles) ) {
                $titles[ $s ] = array(  "titles" => array() );
            }

            $title = array( 
                "uri" => $p, 
                "value" => $o
            );
            
            if ( NULL != $lang && ! empty($lang) ) {
                $title["lang"] = $lang;
            }
            
            $titles[ $s ]["titles"][] = $title;
        }

        // write the cache for each title
        foreach ($titles as $s => $title) {
            
            // sort title as given range in config->title_uris
            usort($title["titles"], function($a, $b) {
                $aRange = array_search($a["uri"], $this->title_uris);
                $bRange = array_search($b["uri"], $this->title_uris);

                if ( $aRange == $bRange) {
                    return 0;                    
                }
                return ( $aRange < $bRange) ? -1 : 1;
            });
            
            $this->cache->save( $this->graph . "." . $s, $title );                    
            $counts++;
        }

        $endTime = microtime(true);

        // save some graph stats
        $createdOrUpdatedStats = ( $this->graphCacheStats( $counts ) ) ? "updated" : "created";

        return $this->createResult( 
            "success", 
            array(  "backend" => $this->config["store"]["backend"], 
                    "cache" => $this->config["cache"]["backend"], 
                    "graph" => $this->graph, "default_lang" => $this->default_lang, 
                    "default_title_uri" => $this->title_uris[0], 
                    "duration" => $endTime - $startTime . " seconds",
                    "counts" => $counts ), 
            "Successfully ".$createdOrUpdatedStats." the cache. You can send requests now by asking this file like: ?action=get&uris=uri1,uri1,..." 
        );
    }

    /**
     * Save some statistics about the current title-cache in the cache
     *
     * @param int   Counted uris for action create
     * @return boolen   false if graph-statistics not found yet
     */
    private function graphCacheStats( $counts = 0 )
    {
        $stats = NULL;
        $cachedStats = $this->cache->load( "__TitleCacheFor:" . $this->graph );

        // save stats for create action
        if ( $this->action == "create" ) {
            if ( NULL != $cachedStats ) {
                // cached stats already exist  -> updated_time
                $stats = array_merge( $cachedStats, array(
                            "counts" => $counts,
                            "updated_time" => time()
                ));
            } else {
                // init create cached stats
                $stats = array(
                    "counts" => $counts,
                    "created_time" => time(),
                    "updated_time" => 0
                );
            }
        }
        // save statst for get (update asked_time)
        else if ( $this->action == "get" ) {
            if ( NULL != $cachedStats ) {
                $stats = array_merge( $cachedStats, array(
                            "asked_time" => time(),
                ));
            }
        }

        // save it in cache
        if ( Null != $stats ) {
            $stats = array_merge( $stats, array(
                        "uri" => $this->graph,
                        "store" => $this->config["store"]["backend"],                                        
            ));

            $this->cache->save( 
                "__TitleCacheFor:" . $this->graph,
                $stats
            );
        }

        return ( NULL != $cachedStats ) ? true : false;
    }
}


