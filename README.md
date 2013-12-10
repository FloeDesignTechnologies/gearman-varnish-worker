gearman-varnish-worker
======================

A PHP [Gearman](https://doc.pheromone.ca/display/dev/Gearman) worker to execute
varnishadm commands.

This allow light and decoupled control of its Varnish cache servers by a (web)
application. Instead of direct connections between the application and the
Varnish servers, Gearman is used a middleware between them. The application
only known about the Gearman servers. The varnish-workers handle the connections
to the multiple Varnish servers and log of the executed commands.

![architecture diagram](/docs/architecture.png)

![sequence diagram](/docs/varnish-gearman.png)

Requirements
------------

* One or more [Gearman](https://doc.pheromone.ca/display/dev/Gearman) servers.
* One or more [Varnish](https://www.varnish-cache.org/) servers.
* PHP 5.3+ with the [Gearman](http://docs.php.net/manual/en/book.gearman.php),
  [PCNTL](http://www.php.net/manual/en/book.pcntl.php) and
  [JSON](http://www.php.net/manual/en/book.json.php) extensions.

Installation
------------

* Install Composer
```
$ curl -s http://getcomposer.org/installer | php
```

* Download dependencies
```
$ composer install
```
Note: use the `--no-dev` option to avoid downloading dependencies of the
example client web application.

* Create a `varnish-worker.ini` configuration file
```
# One servers[] line for each Gearman server
servers[]=127.0.0.1:4730
servers[]=192.168.1.1:4730
# One section for each Varnish server.
[varnish1]
  host=127.0.0.1
  port=6081
[varnish2]
  host=192.168.1.1
  port=6081
  secret="/etc/varnish/secret"
  version=3.0
```

* Run `varnish-worker.php`.
```
./varnish-worker.php
```