<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Credential;

use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Exception\ConfigurationException;

final class CredentialStoreFactory
{
    public static function create(WorkerConfig $config): CredentialStoreInterface
    {
        return match ($config->credentialStore) {
            'windows' => new WindowsCredentialManagerStore(),
            'file-dev' => new DevelopmentFileCredentialStore($config->developmentCredentialFile),
            default => throw new ConfigurationException('No supported credential store is configured.'),
        };
    }
}
