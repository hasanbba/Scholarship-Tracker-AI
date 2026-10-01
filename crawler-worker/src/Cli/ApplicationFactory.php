<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Cli;

use Scholarship\CrawlerWorker\Api\CrawlerApiClient;
use Scholarship\CrawlerWorker\Api\CurlTransport;
use Scholarship\CrawlerWorker\Api\NativeSleeper;
use Scholarship\CrawlerWorker\Api\RetryPolicy;
use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Credential\CredentialStoreFactory;
use Scholarship\CrawlerWorker\Logging\StructuredLogger;
use Scholarship\CrawlerWorker\Spool\LocalSpool;
use Scholarship\CrawlerWorker\Support\ShutdownController;
use Scholarship\CrawlerWorker\Support\WorkerIdentityStore;
use Scholarship\CrawlerWorker\Fetch\CurlFetchHttpClient;
use Scholarship\CrawlerWorker\Fetch\DnsResolverInterface;
use Scholarship\CrawlerWorker\Fetch\IpAddressPolicy;
use Scholarship\CrawlerWorker\Fetch\RobotsPolicy;
use Scholarship\CrawlerWorker\Fetch\SafeFetcher;
use Scholarship\CrawlerWorker\Fetch\SystemDnsResolver;
use Scholarship\CrawlerWorker\Fetch\UrlPolicy;

final class ApplicationFactory
{
    public static function create(WorkerConfig $config, bool $consoleLog = true): WorkerApplication
    {
        $logger = new StructuredLogger($config->logDirectory, $consoleLog);
        $retry = new RetryPolicy($config->maxApiRetries, $config->retryBaseDelayMs, $config->retryMaxDelayMs, new NativeSleeper());
        $identities = new WorkerIdentityStore($config->identityFile);
        $api = new CrawlerApiClient($config, new CurlTransport(), $retry, $logger, static fn () => $identities->load()['worker_uuid'] ?? null);
        $spool = new LocalSpool($config->spoolDirectory, min($config->maxSpoolEntryBytes, $config->maxFetchBytes));
        $fetcher = new SafeFetcher(new UrlPolicy(new IpAddressPolicy(), new SystemDnsResolver(), $config->allowHttpSources && $config->environment === 'development'),
            new CurlFetchHttpClient($config->fetchConnectTimeoutSeconds, $config->fetchTimeoutSeconds), new RobotsPolicy(), $retry, $spool,
            $config->maxFetchBytes, $config->maxRedirects, $config->allowedContentTypes, $config->minimumSourceDelayMs, $config->fetchOperationTimeoutSeconds);
        return new WorkerApplication(
            $config, $api, CredentialStoreFactory::create($config), $identities,
            $spool, $logger, new ShutdownController($logger), $fetcher,
        );
    }
}
