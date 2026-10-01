<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class CurlFetchHttpClient implements FetchHttpClientInterface
{
    public function __construct(private readonly int $connectSeconds = 5, private readonly int $totalSeconds = 30, private readonly string $userAgent = 'ScholarshipTrackerCrawler/0.1.0') {}
    public function get(array $target, array $headers, int $maxBytes, callable $onChunk, ?callable $heartbeat = null): array
    {
        $responseHeaders = []; $status = 0; $headerBytes=0; $started = microtime(true); $lastBeat = $started; $callbackFailure = null; $headerOverflow=false; $sizeGuard=new ResponseSizeGuard($maxBytes);
        $host = $target['host']; $port = $target['port']; $ip = $target['addresses'][0] ?? '';
        $resolveIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $url = $target['url']; $handle = curl_init($url);
        $curlOptions = [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => $this->connectSeconds, CURLOPT_TIMEOUT => $this->totalSeconds,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => array_merge(['Accept: text/html, application/xhtml+xml, application/json, application/pdf', 'Accept-Encoding: identity'], array_map(fn($k,$v)=>$k.': '.$v, array_keys($headers), array_values($headers))),
            CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$responseHeaders, &$status,&$headerBytes,&$headerOverflow): int {
                $len = strlen($line); $headerBytes+=$len; if($headerBytes>65536){$headerOverflow=true;return 0;}
                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', trim($line), $m)) { $status=(int)$m[1]; $responseHeaders=[]; }
                elseif (str_contains($line, ':')) { [$k,$v]=explode(':',$line,2);$name=strtolower(trim($k));if(in_array($name,['location','content-type','content-encoding','retry-after','etag','last-modified','content-language'],true))$responseHeaders[$name]=trim($v); } return $len;
            },
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($sizeGuard,$onChunk,&$status,&$callbackFailure): int {
                $n=strlen($chunk); if (!$sizeGuard->accept($chunk)) return 0;
                try { return $onChunk($chunk, $status) === false ? 0 : $n; } catch (\Throwable $e) { $callbackFailure=$e; return 0; }
            }, CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function($curl, $downloadTotal, $downloadNow) use ($heartbeat,&$lastBeat,&$callbackFailure): int {
                if ($heartbeat && microtime(true)-$lastBeat >= 1) { $lastBeat=microtime(true); try { $heartbeat(); } catch (\Throwable $e) { $callbackFailure=$e; return 1; } } return 0;
            },
        ];
        if (!filter_var($host,FILTER_VALIDATE_IP)) $curlOptions[CURLOPT_RESOLVE]=[$host.':'.$port.':'.$resolveIp];
        curl_setopt_array($handle,$curlOptions);
        $ok = curl_exec($handle); $errorNo = curl_errno($handle); $error = curl_error($handle); $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
        if ($callbackFailure instanceof FetchFailure) throw $callbackFailure;
        if ($callbackFailure) throw new FetchFailure('LEASE_LOST',false,'Job lease heartbeat failed.');
        if ($headerOverflow) throw new FetchFailure('MALFORMED_RESPONSE',false,'Response headers exceeded the configured limit.');
        if ($sizeGuard->exceeded()) throw new FetchFailure('RESPONSE_TOO_LARGE');
        if ($ok === false) {
            $code = in_array($errorNo, [CURLE_OPERATION_TIMEDOUT], true) ? 'TIMEOUT' : (in_array($errorNo,[CURLE_SSL_CONNECT_ERROR,CURLE_PEER_FAILED_VERIFICATION],true) ? 'TLS_FAILED' : 'CONNECTION_FAILED');
            throw new FetchFailure($code, $code !== 'TLS_FAILED', 'HTTP fetch failed: '.$errorNo);
        }
        return ['status'=>$status,'headers'=>$responseHeaders,'bytes'=>$sizeGuard->bytes(),'duration_ms'=>(int)((microtime(true)-$started)*1000)];
    }
}
