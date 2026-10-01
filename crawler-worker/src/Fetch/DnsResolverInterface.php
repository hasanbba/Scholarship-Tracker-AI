<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
interface DnsResolverInterface { /** @return list<string> */ public function resolve(string $hostname): array; }
