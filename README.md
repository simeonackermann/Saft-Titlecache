# RDF Title Cache 

Simple title cache for RDF data (based on [Saft](http://safting.github.io/) and [Nette](https://github.com/nette/caching)). As store it supports currently only Virtuoso. Caches could be the following: Memcached, APC, Redis, ElasticSearch, SQLite, MongoDB.

## Start

1. move this files in a folder of your webserver, e.g. `YOUR_WEBROOT/titlecache/index.php`
2. execute `composer update`
3. now you can create and get the title-cache via GET parameter or YAML-file.

### Create Cache

To create the cache call:

     http://localhost/titlecache/?action=create

Optionally give a graph uri to change the default graph (`http://example.org/`):

    http://localhost/titlecache/?action=create&graph=http://your-graph-uri.org/


### Get Cached Titles

To retrieve the cached titles call:

     http://localhost/titlecache/?action=get&uris=http://your-uri-1.org,http://your-uri1.org

with comma seperated uri-list. Optionally give a language to set the prefered language (de|en|fr|...) for the titles:

    http://localhost/titlecache/?action=get&uris=http://your-uri-1.org,http://your-uri-2.org&lang=de


#### From File

The uris to get could sended as file via:

    http://localhost/titlecache/?action=get&uris-from=/path/to/file.txt

Its also possible to send the file as HTTP-Multipart parameter (for example from HTML formular), with parameter name `uris-from`.


## Extended (action from YAML file)

Actions could also loaded from a yaml config file. Take a look at `example-config.yml` for a configuration example.

It must given as `action-from` parameter:

    http://localhost/titlecache/?action-from=/path/to/file.yml


## Results

The result will return JSON encoded array like:

    [
        status: "success|error"
        data: null|{"http://your-uri-1.org":"My title 1", "http://your-uri-2.org":"My title 2"}
        message: null|"Message"
    ]

You can give the parameter `results=/path/to/results/folder` to store the results as text files in the given folder (must be writeable).

