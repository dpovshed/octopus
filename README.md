# Octopus
Web project using [PHPReact](https://github.com/reactphp/react) library, designed as a sample project which perform sitemap validity checking.

![Logo](logo-medium.png)

## Usage from the Command Line Interface (CLI)

```bash
php application.php octopus:run http://www.domain.ext/sitemap.xml
```
using a specific amount of concurrent connections:

```bash
php application.php octopus:run http://www.domain.ext/sitemap.xml --concurrency 15
```

more detailed tuning is also available. In the example below system using regular GET request instead of mercifully requesting only headers (default) and with quite strict timeout for request of 3 seconds:

```bash
php application.php octopus:run http://www.domain.ext/sitemap.xml --concurrency 15 --requestType GET --timeout 3
```

## Usage from your own application
You can easily integrate sitemap crawling in your own application, have a look at the `Config` class for all possible configuration options.

```php
use Octopus\Config as OctopusConfig;
use Octopus\Processor as OctopusProcessor;
use Octopus\TargetManager as OctopusTargetManager;

$config = new OctopusConfig();
$config->targetFile = $sitemapUrl;
$config->additionalResponseHeadersToCount = array(
    'CF-Cache-Status', //Useful to check CloudFlare edge server cache status
);
$config->requestHeaders = array(
    'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', //Simulate Google's webcrawler
);
$targetManager = new OctopusTargetManager($config);
$processor = new OctopusProcessor($config, $targetManager);
try {
    $numberOfQueuedFiles = $targetManager->populate();
    $this->logger->info($numberOfQueuedFiles . ' URLs queued for crawling');
    $processor->warmUp();
    $processor->spawnBundle();
} catch (\Exception $e) {
    $this->logger->notice('Exception on initialization: ' . $e->getMessage());
    return;
}

while ($targetManager->countQueue()) {
    $processor->run();
}

$this->logger->info('Statistics: ' . print_r($processor->statCodes, true));
$this->logger->info('Applied concurrency: ' . $processor->config->concurrency);
$this->logger->info('Total amount of processed data: ' . $processor->totalData);
$this->logger->info('Failed to load #URLs: ' . count($processor->brokenUrls));
```

## Limitations
Since currently Octopus is mainly educational tool it does not handle all 3xx
errors, loop redirects and other advanced cases.

