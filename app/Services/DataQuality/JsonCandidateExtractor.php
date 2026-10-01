<?php

namespace App\Services\DataQuality;

use JsonException;

class JsonCandidateExtractor
{
    /** @throws JsonException */
    public function extract(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new JsonException('The structured observation must contain a JSON object.');
        }

        return $decoded['candidate'] ?? $decoded;
    }
}
