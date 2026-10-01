<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

interface HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
