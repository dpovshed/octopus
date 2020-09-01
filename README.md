# Octopus Sitemap Crawler
Small PHP tool to crawl collections of URLs in a Sitemap using the [PHPReact](https://github.com/reactphp/react) library for asynchronous loading of the URLs. Both plain text files and [XML Sitemaps](https://www.sitemaps.org/protocol.html) are supported.

![Logo](logo-medium.png)

## Usage from the Command Line Interface (CLI)

Crawl the URLs in a Sitemap with verbose logging (`-vvv`).

```bash
php application.php http://www.domain.ext/sitemap.xml -vvv
```

Using 15 concurrent connections instead of the default 5 concurrent connections:

```bash
php application.php http://www.domain.ext/sitemap.xml --concurrency 15 -vvv
```

Use a `HTTP GET` request instead of the default `HTTP HEAD`. Note that `HTTP HEAD` requests involve less data transfer since no body is involved:

```bash
php application.php http://www.domain.ext/sitemap.xml --requestType GET -vvv
```

Use a timeout of 3 seconds instead of the default 10 seconds:

```bash
php application.php http://www.domain.ext/sitemap.xml --timeout 3 -vvv
```

Use a specific UserAgent instead of the default `Octopus/1.0`, for example, to simulate a search engine crawling a sitemap:

```bash
php application.php http://www.domain.ext/sitemap.xml --userAgent 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' -vvv
```

Use the `TablePresenter` to display intermediate results instead of the default `EchoPresenter`:

```bash
php application.php http://www.domain.ext/sitemap.xml --presenter Octopus\\Presenter\\TablePresenter -vvv
```

## Usage from your own application
You can easily integrate sitemap crawling in your own application, have a look at the `Config` class for all possible configuration options. If required you can use a [PSR3-Logger](https://www.php-fig.org/psr/psr-3/) for logging purposes.

```php
use Octopus\Config;
use Octopus\Processor;

$config = new Config();
$config->concurrency = 2;
$config->targetFile = 'https://www.domain.ext/sitemap.xml';
$config->additionalResponseHeadersToCount = array(
    'CF-Cache-Status', //Useful to check CloudFlare edge server cache status
);
$config->requestHeaders = array(
    'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', //Simulate Google's webcrawler
);
$processor = new Processor($config, $this->logger); //A PSR3 Logger can be injected if required
$processor->run();

$this->logger->info('Statistics: ' . print_r($processor->result->getStatusCodes(), true));
$this->logger->info('Applied concurrency: ' . $config->concurrency);
$this->logger->info('Total amount of processed data: ' . $processor->result->getTotalData());
$this->logger->info('Failed to load #URLs: ' . count($processor->result->getBrokenUrls()));
```

## Limitations
Currently, Octopus is mainly an experimental / educational tool. Advanced use cases in HTTP response handling might not be supported.

## Tests

To run the test suite, you first need to clone this repository and then install all dependencies [using Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ make test
```
