<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Credential;

interface CredentialStoreInterface
{
    public function store(string $reference, string $credential): void;
    public function retrieve(string $reference): ?string;
    public function delete(string $reference): void;
    public function exists(string $reference): bool;
}
