<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Api;

use Scholarship\CrawlerWorker\Exception\TransportException;

final class CurlTransport implements HttpTransportInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        if (! extension_loaded('curl')) {
            throw new TransportException('PHP cURL extension is required.');
        }
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new TransportException('Could not initialize the HTTP transport.');
        }
        $body = '';
        $headers = [];
        $tooLarge = false;
        $started = hrtime(true);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($request->method),
            CURLOPT_HTTPHEADER => array_map(static fn (string $key, string $value): string => $key.': '.$value, array_keys($request->headers), array_values($request->headers)),
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $request->requestTimeoutSeconds,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $request): int {
                if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        $duration = (int) round((hrtime(true) - $started) / 1_000_000);
        if ($result === false) {
            if ($tooLarge) throw new \Scholarship\CrawlerWorker\Exception\ProtocolException('API response exceeded the configured size limit.');
            $message = 'API transport failed or timed out.';
            throw new TransportException($message, $errno);
        }

        return new HttpResponse($status, $headers, $body, $duration);
    }
}
