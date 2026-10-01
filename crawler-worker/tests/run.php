<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Scholarship\CrawlerWorker\Api\CrawlerApiClient;
use Scholarship\CrawlerWorker\Api\CrawlerApiClientInterface;
use Scholarship\CrawlerWorker\Api\HttpRequest;
use Scholarship\CrawlerWorker\Api\HttpResponse;
use Scholarship\CrawlerWorker\Api\HttpTransportInterface;
use Scholarship\CrawlerWorker\Api\RetryPolicy;
use Scholarship\CrawlerWorker\Api\SleeperInterface;
use Scholarship\CrawlerWorker\Cli\WorkerApplication;
use Scholarship\CrawlerWorker\Config\WorkerConfig;
use Scholarship\CrawlerWorker\Credential\CredentialStoreInterface;
use Scholarship\CrawlerWorker\Credential\DevelopmentFileCredentialStore;
use Scholarship\CrawlerWorker\Credential\WindowsCredentialManagerStore;
use Scholarship\CrawlerWorker\Exception\ApiException;
use Scholarship\CrawlerWorker\Exception\AuthenticationException;
use Scholarship\CrawlerWorker\Exception\ConfigurationException;
use Scholarship\CrawlerWorker\Exception\NonRetryableApiException;
use Scholarship\CrawlerWorker\Exception\ProtocolException;
use Scholarship\CrawlerWorker\Exception\RetryableApiException;
use Scholarship\CrawlerWorker\Exception\TransportException;
use Scholarship\CrawlerWorker\Logging\StructuredLogger;
use Scholarship\CrawlerWorker\Spool\LocalSpool;
use Scholarship\CrawlerWorker\Support\ShutdownController;
use Scholarship\CrawlerWorker\Support\WorkerIdentityStore;
use Scholarship\CrawlerWorker\Fetch\DnsResolverInterface;
use Scholarship\CrawlerWorker\Fetch\IpAddressPolicy;
use Scholarship\CrawlerWorker\Fetch\UrlPolicy;
use Scholarship\CrawlerWorker\Fetch\FetchFailure;
use Scholarship\CrawlerWorker\Fetch\FetchHttpClientInterface;
use Scholarship\CrawlerWorker\Fetch\RobotsPolicy;
use Scholarship\CrawlerWorker\Fetch\SafeFetcher;

final class FakeTransport implements HttpTransportInterface
{
    public array $requests = [];
    public array $responses = [];
    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) throw $next;
        if (! $next instanceof HttpResponse) throw new RuntimeException('No fake response queued.');
        return $next;
    }
}

final class FakeFetchHttp implements FetchHttpClientInterface
{
    public array $calls=[]; public array $fixtures=[];
    public function get(array $target,array $headers,int $maxBytes,callable $onChunk,?callable $heartbeat=null):array
    {
        $key=$target['path']; $this->calls[]=$key; $fixture=array_shift($this->fixtures[$key]);
        if($fixture instanceof Throwable) throw $fixture;
        if(!is_array($fixture)) throw new RuntimeException('No fake HTTP fixture for '.$key);
        $body=$fixture['body']??''; $sent=0;
        for($offset=0;$offset<strlen($body);$offset+=16){$chunk=substr($body,$offset,16);$sent+=strlen($chunk);if($sent>$maxBytes)throw new FetchFailure('RESPONSE_TOO_LARGE');$onChunk($chunk,$fixture['status']);}
        return ['status'=>$fixture['status'],'headers'=>$fixture['headers']??[],'bytes'=>strlen($body),'duration_ms'=>1];
    }
}

final class FakeSleeper implements SleeperInterface
{
    public array $delays = [];
    public function sleepMilliseconds(int $milliseconds): void { $this->delays[] = $milliseconds; }
}

final class MemoryCredentials implements CredentialStoreInterface
{
    public array $values = [];
    public function store(string $reference, string $credential): void { $this->values[$reference] = $credential; }
    public function retrieve(string $reference): ?string { return $this->values[$reference] ?? null; }
    public function delete(string $reference): void { unset($this->values[$reference]); }
    public function exists(string $reference): bool { return isset($this->values[$reference]); }
}

final class FakeApi implements CrawlerApiClientInterface
{
    public array $calls = [];
    public mixed $claim = null;
    public array $worker = ['uuid' => 'worker-uuid', 'label' => 'test worker', 'status' => 'idle', 'protocol_version' => 1, 'last_heartbeat_at' => null];
    public array $activation;
    public function __construct() { $this->activation = ['worker' => ['uuid' => 'worker-uuid', 'label' => 'test worker', 'status' => 'active'], 'token' => 'b'.str_repeat('2', 63), 'expires_at' => '2030-01-01T00:00:00+00:00']; }
    public function activate(string $activationCode, int $protocolVersion, string $softwareVersion): array { $this->calls[] = ['activate', $activationCode]; return $this->activation; }
    public function getCurrentWorker(string $token): array { $this->calls[] = ['me']; return $this->worker; }
    public function claimJob(string $token, string $claimRequestKey): ?array { $this->calls[] = ['claim', $claimRequestKey]; return $this->claim; }
    public function heartbeat(string $token, int $jobId, int $attemptId, string $leaseToken): array { $this->calls[] = ['heartbeat', $jobId, $attemptId]; return ['lease_expires_at' => '2030-01-01T00:00:00+00:00', 'lease_generation' => 1]; }
    public function uploadArtifact(string $token, int $jobId, int $attemptId, array $metadata, string $bytes, string $idempotencyKey): array { $this->calls[] = ['artifact', $jobId, $attemptId, strlen($bytes), $idempotencyKey]; return ['artifact_id' => $metadata['artifact_id'] ?? '']; }
    public function submitFetchResult(string $token, int $jobId, int $attemptId, array $result, string $idempotencyKey): array { $this->calls[] = ['result', $jobId, $attemptId, $result['outcome'] ?? null, $idempotencyKey]; return ['state' => 'completed']; }
    public function health(): array { $this->calls[] = ['health']; return ['status' => 'ok']; }
}

final class SkippedTest extends RuntimeException {}

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'crawler-worker-tests-'.bin2hex(random_bytes(6));
mkdir($temporaryRoot, 0700, true);
$tests = 0;
$assertions = 0;
$skipped = 0;
$failures = [];
$assert = static function (bool $condition, string $message = 'assertion failed') use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$expect = static function (callable $callback, string $class) use ($assert): Throwable {
    try { $callback(); } catch (Throwable $exception) { $assert($exception instanceof $class, 'expected '.$class.', got '.get_class($exception)); return $exception; }
    throw new RuntimeException('expected exception '.$class);
};
$test = static function (string $name, callable $callback) use (&$tests, &$failures, &$skipped): void {
    $tests++;
    try { $callback(); fwrite(STDOUT, "PASS {$name}".PHP_EOL); }
    catch (SkippedTest $exception) { $skipped++; fwrite(STDOUT, "SKIP {$name}: ".$exception->getMessage().PHP_EOL); }
    catch (Throwable $exception) { $failures[] = [$name, $exception]; fwrite(STDOUT, "FAIL {$name}: ".$exception->getMessage().PHP_EOL); }
};
$makeConfig = static function (string $dir, array $overrides = []) use ($root): WorkerConfig {
    return WorkerConfig::fromArray($root, array_replace([
        'api_base_url' => 'https://scholarship.test', 'environment' => 'development', 'credential_store' => 'file-dev',
        'credential_reference' => 'test-worker', 'worker_uuid' => '', 'protocol_version' => 1, 'software_version' => '0.1.0',
        'connect_timeout_seconds' => 2, 'request_timeout_seconds' => 5, 'heartbeat_interval_seconds' => 1,
        'poll_interval_seconds' => 1, 'max_api_retries' => 2, 'retry_base_delay_ms' => 100,
        'retry_max_delay_ms' => 1000, 'max_api_response_bytes' => 4096, 'max_spool_entry_bytes' => 8,
        'spool_directory' => $dir.'/spool', 'log_directory' => $dir.'/logs', 'identity_file' => $dir.'/worker-state.json',
        'development_credential_file' => $dir.'/credentials.json', 'allow_insecure_local_api' => false,
    ], $overrides));
};
$makeClient = static function (FakeTransport $transport, FakeSleeper $sleeper, string $dir, array $overrides = []) use ($makeConfig): array {
    $config = $makeConfig($dir, $overrides);
    $logger = new StructuredLogger($config->logDirectory, false);
    $retry = new RetryPolicy($config->maxApiRetries, $config->retryBaseDelayMs, $config->retryMaxDelayMs, $sleeper, static fn (int $delay): int => (int) ($delay * 0.9));
    return [new CrawlerApiClient($config, $transport, $retry, $logger), $config, $logger];
};
$envelope = static fn (mixed $data): string => json_encode(['success' => true, 'message' => 'OK', 'data' => $data], JSON_THROW_ON_ERROR);
$response = static fn (int $status, mixed $data = null, array $headers = []): HttpResponse => new HttpResponse($status, $headers, is_string($data) ? $data : $envelope($data), 12);

$test('PHP version and typed configuration enforce HTTPS and local-only HTTP', static function () use ($assert, $expect, $makeConfig, $root): void {
    $assert(PHP_VERSION_ID >= 80300, 'PHP 8.3+ is required');
    $makeConfig($root)->validateForApi();
    $expect(static fn () => $makeConfig($root, ['api_base_url' => 'http://remote.example'])->validateForApi(), ConfigurationException::class);
    $makeConfig($root, ['api_base_url' => 'http://127.0.0.1:8000', 'environment' => 'development', 'allow_insecure_local_api' => true])->validateForApi();
    $expect(static fn () => $makeConfig($root, ['credential_store' => 'file-dev', 'environment' => 'production'])->validateForApi(), ConfigurationException::class);
});

$test('safe URL policy blocks unsafe schemes hosts ports and source scope', static function () use ($assert,$expect): void {
    $dns=new class implements DnsResolverInterface { public array $answers=[]; public function resolve(string $hostname):array{return $this->answers[$hostname]??['93.184.216.34'];} };
    $policy=new UrlPolicy(new IpAddressPolicy(),$dns);
    $source='https://example.test'; $prefix='/scholarships/';
    $assert($policy->inspect('https://example.test/scholarships/a',$source,$prefix)['host']==='example.test');
    $assert($policy->inspect('https://example.test:443/scholarships/2026',$source,$prefix)['port']===443);
    $assert($policy->safeLogUrl('https://example.test/scholarships/a?token=hidden')==='https://example.test/scholarships/a?[REDACTED]');
    foreach(['file:///etc/passwd','ftp://example.test/scholarships/x','https://localhost/scholarships/a','https://evil-example.test/scholarships/a','https://example.test/admissions','https://example.test/scholarships/%2e%2e/admin','https://example.test/scholarships/%252e%252e/admin','https://user@example.test/scholarships/a','https://example.test:8443/scholarships/a'] as $url) $expect(static fn()=>$policy->inspect($url,$source,$prefix),FetchFailure::class);
    $expect(static fn()=>$policy->inspect('http://example.test/scholarships/a',$source,$prefix),FetchFailure::class);
});

$test('DNS policy rejects private mixed mapped loopback and metadata answers after resolution', static function () use ($assert,$expect): void {
    $ips=new IpAddressPolicy(); $assert($ips->isPublic('93.184.216.34'));
    foreach(['10.0.0.1','127.0.0.1','169.254.169.254','192.168.1.2','::1','fc00::1','fe80::1','ff02::1','::ffff:127.0.0.1'] as $address) $assert(!$ips->isPublic($address),$address.' must be denied');
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34','10.0.0.4'];} };
    $policy=new UrlPolicy($ips,$dns); $expect(static fn()=>$policy->inspect('https://example.test/scholarships/a','https://example.test','/scholarships'),FetchFailure::class);
});

$test('safe fetch follows only approved redirects and stores complete hashed artifact', static function () use ($assert,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    $policy=new UrlPolicy(new IpAddressPolicy(),$dns); $http=new FakeFetchHttp();
    $http->fixtures['/robots.txt']=[['status'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>"User-agent: *\nDisallow: /private\nAllow: /scholarships/\n"]];
    $http->fixtures['/scholarships/start']=[['status'=>302,'headers'=>['location'=>'/scholarships/final'],'body'=>'']];
    $body='<html>fixture</html>'; $http->fixtures['/scholarships/final']=[['status'=>200,'headers'=>['content-type'=>'text/html; charset=utf-8','etag'=>'fixture'],'body'=>$body]];
    $spool=new LocalSpool($temporaryRoot.'/safe-fetch-spool',4194304); $retry=new RetryPolicy(2,1,5,new FakeSleeper(),static fn($delay)=>$delay);
    $fetcher=new SafeFetcher($policy,$http,new RobotsPolicy(),$retry,$spool,4194304,3,['text/html'],0);
    $result=$fetcher->fetch(['job_id'=>51,'attempt_id'=>61,'lease_generation'=>1,'source_id'=>7,'robots_policy'=>'allowed','requested_url'=>'https://example.test/scholarships/start','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/scholarships/'],static function():void{});
    $assert($result->data['sha256']===hash('sha256',$body)); $assert($result->data['content_length']===strlen($body));
    $assert($result->data['final_url']==='https://example.test/scholarships/final'); $assert(count($result->data['redirects'])===1);
    $assert(file_get_contents($temporaryRoot.'/safe-fetch-spool/51-61/payload.bin')===$body);
    $assert($spool->readMetadata('51-61')['fetch_result']['sha256']===$result->data['sha256']);
    $assert($http->calls===['/robots.txt','/scholarships/start','/scholarships/final']);
    $http->fixtures['/scholarships/final']=[['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>$body]];
    $fetcher->fetch(['job_id'=>52,'attempt_id'=>62,'lease_generation'=>1,'source_id'=>7,'robots_policy'=>'allowed','requested_url'=>'https://example.test/scholarships/final','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/scholarships/'],static function():void{});
    $assert(count(array_filter($http->calls,static fn($path)=>$path==='/robots.txt'))===1,'robots response should be cached for the origin');
});

$test('safe fetch rejects disallowed robots paths and external redirects without retaining partial files', static function () use ($assert,$expect,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    $http=new FakeFetchHttp(); $http->fixtures['/robots.txt']=[['status'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>"User-agent: *\nDisallow: /private\n"]];
    $spool=new LocalSpool($temporaryRoot.'/safe-fetch-denied',4194304); $fetcher=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(1,1,5,new FakeSleeper()),$spool,4194304,2,['text/html'],0);
    $job=['job_id'=>71,'attempt_id'=>81,'lease_generation'=>1,'source_id'=>9,'robots_policy'=>'allowed','requested_url'=>'https://example.test/private/page','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/'];
    $expect(static fn()=>$fetcher->fetch($job,static function():void{}),FetchFailure::class);
    $assert(!is_dir($temporaryRoot.'/safe-fetch-denied/71-81'));
    $http2=new FakeFetchHttp(); $http2->fixtures['/robots.txt']=[['status'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>"User-agent: *\nAllow: /\n"]]; $http2->fixtures['/safe']=[['status'=>302,'headers'=>['location'=>'https://evil.test/safe'],'body'=>'']];
    $spool2=new LocalSpool($temporaryRoot.'/safe-fetch-redirect',4194304); $fetch2=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http2,new RobotsPolicy(),new RetryPolicy(1,1,5,new FakeSleeper()),$spool2,4194304,2,['text/html'],0);
    $job['job_id']=72;$job['attempt_id']=82;$job['requested_url']='https://example.test/safe';
    $expect(static fn()=>$fetch2->fetch($job,static function():void{}),FetchFailure::class);
    $assert(!is_dir($temporaryRoot.'/safe-fetch-redirect/72-82'));
});

$test('safe fetch rejects unsupported MIME and oversized bodies without retaining partial artifacts', static function () use ($assert,$expect,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    $make=static function(FakeFetchHttp $http,string $dir)use($dns):SafeFetcher{return new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(0,1,5,new FakeSleeper()),new LocalSpool($dir,64),64,1,['text/html'],0);};
    $base=['attempt_id'=>4,'lease_generation'=>1,'source_id'=>2,'robots_policy'=>'allowed','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/'];
    $http=new FakeFetchHttp();$http->fixtures['/robots.txt']=[['status'=>404,'body'=>'']];$http->fixtures['/bin']=[['status'=>200,'headers'=>['content-type'=>'application/octet-stream'],'body'=>'binary']];
    $fetcher=$make($http,$temporaryRoot.'/mime');$expect(static fn()=>$fetcher->fetch($base+['job_id'=>3,'requested_url'=>'https://example.test/bin'],static function():void{}),FetchFailure::class);$assert(!is_dir($temporaryRoot.'/mime/3-4'));
    $http2=new FakeFetchHttp();$http2->fixtures['/robots.txt']=[['status'=>404,'body'=>'']];$http2->fixtures['/large']=[['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>str_repeat('x',65)]];
    $fetcher2=$make($http2,$temporaryRoot.'/large');$failure=$expect(static fn()=>$fetcher2->fetch($base+['job_id'=>5,'requested_url'=>'https://example.test/large'],static function():void{}),FetchFailure::class);$assert($failure->failureCode==='RESPONSE_TOO_LARGE');$assert(!is_dir($temporaryRoot.'/large/5-4'));
    $http3=new FakeFetchHttp();$http3->fixtures['/robots.txt']=[['status'=>404,'body'=>'']];$http3->fixtures['/exact']=[['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>str_repeat('y',64)]];
    $fetcher3=$make($http3,$temporaryRoot.'/exact');$r=$fetcher3->fetch($base+['job_id'=>6,'requested_url'=>'https://example.test/exact'],static function():void{});$assert($r->data['content_length']===64);
});

$test('safe fetch enforces redirect HTTPS/path policy and bounded redirect count', static function () use ($assert,$expect,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    foreach([
        ['https://evil.test/out','UNREGISTERED_ORIGIN'],
        ['http://example.test/scholarships/a','HTTPS_POLICY_VIOLATION'],
        ['https://example.test/outside','PATH_NOT_ALLOWED'],
    ] as [$location,$code]) {
        $http=new FakeFetchHttp();$http->fixtures['/robots.txt']=[['status'=>404]];$http->fixtures['/scholarships/start']=[['status'=>302,'headers'=>['location'=>$location]]];
        $fetcher=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(0,1,5,new FakeSleeper()),new LocalSpool($temporaryRoot.'/redirect-'.md5($location),1024),1024,1,['text/html'],0);
        $e=$expect(static fn()=>$fetcher->fetch(['job_id'=>101,'attempt_id'=>111,'lease_generation'=>1,'source_id'=>1,'robots_policy'=>'allowed','requested_url'=>'https://example.test/scholarships/start','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/scholarships/'],static function():void{}),FetchFailure::class);
        $assert($e->failureCode===$code);
    }
    $http=new FakeFetchHttp();$http->fixtures['/robots.txt']=[['status'=>404]];$http->fixtures['/scholarships/a']=[['status'=>302,'headers'=>['location'=>'/scholarships/b']]];$http->fixtures['/scholarships/b']=[['status'=>302,'headers'=>['location'=>'/scholarships/a']]];
    $fetcher=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(0,1,5,new FakeSleeper()),new LocalSpool($temporaryRoot.'/redirect-loop',1024),1024,1,['text/html'],0);
    $e=$expect(static fn()=>$fetcher->fetch(['job_id'=>102,'attempt_id'=>112,'lease_generation'=>1,'source_id'=>1,'robots_policy'=>'allowed','requested_url'=>'https://example.test/scholarships/a','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/scholarships/'],static function():void{}),FetchFailure::class);
    $assert($e->failureCode==='REDIRECT_LIMIT');
});

$test('safe fetch retries rate limits within bounds and honors Retry-After', static function () use ($assert,$expect,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    $http=new FakeFetchHttp();$http->fixtures['/robots.txt']=[['status'=>404]];$http->fixtures['/retry']=[['status'=>429,'headers'=>['retry-after'=>'2']],['status'=>200,'headers'=>['content-type'=>'text/html'],'body'=>'ready']];
    $sleeper=new FakeSleeper();$fetcher=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(2,100,5000,$sleeper,static fn($ms)=>$ms),new LocalSpool($temporaryRoot.'/fetch-retry',1024),1024,1,['text/html'],0);
    $r=$fetcher->fetch(['job_id'=>121,'attempt_id'=>131,'lease_generation'=>1,'source_id'=>1,'robots_policy'=>'allowed','requested_url'=>'https://example.test/retry','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/'],static function():void{});
    $assert($r->data['status_code']===200);$assert($sleeper->delays===[2000]);$assert(count($http->calls)===3);
});

$test('safe fetch classifies permanent and transient HTTP statuses explicitly', static function () use ($assert,$expect,$temporaryRoot): void {
    $dns=new class implements DnsResolverInterface { public function resolve(string $hostname):array{return ['93.184.216.34'];} };
    foreach([[401,'HTTP_401',1],[403,'HTTP_403',1],[404,'HTTP_404',1],[408,'TIMEOUT',3],[429,'HTTP_429',3],[500,'HTTP_5XX',3],[502,'HTTP_5XX',3],[503,'HTTP_5XX',3]] as [$status,$code,$attempts]) {
        $http=new FakeFetchHttp();$http->fixtures['/robots.txt']=[['status'=>404]];$http->fixtures['/status']=array_fill(0,$attempts,['status'=>$status,'headers'=>$status===429?['retry-after'=>'0']:[],'body'=>'']);
        $fetcher=new SafeFetcher(new UrlPolicy(new IpAddressPolicy(),$dns),$http,new RobotsPolicy(),new RetryPolicy(2,1,10,new FakeSleeper(),static fn($ms)=>$ms),new LocalSpool($temporaryRoot.'/status-'.$status,1024),1024,1,['text/html'],0);
        $error=$expect(static fn()=>$fetcher->fetch(['job_id'=>200+$status,'attempt_id'=>300+$status,'lease_generation'=>1,'source_id'=>1,'robots_policy'=>'allowed','requested_url'=>'https://example.test/status','registered_source_url'=>'https://example.test','allowed_path_prefix'=>'/'],static function():void{}),FetchFailure::class);
        $assert($error->failureCode===$code);$assert(count(array_filter($http->calls,static fn($path)=>$path==='/status'))===$attempts);
    }
});

$test('activation uses exact Phase 5A fields and validates one-time response', static function () use ($assert, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(201, ['worker' => ['uuid' => 'abc', 'label' => 'PC', 'status' => 'active'], 'token' => str_repeat('a', 64), 'expires_at' => '2030-01-01T00:00:00Z']);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/activate');
    $result = $client->activate(str_repeat('c', 64), 1, '0.1.0');
    $body = json_decode($transport->requests[0]->body, true);
    $assert($body === ['activation_code' => str_repeat('c', 64), 'protocol_version' => 1, 'software_version' => '0.1.0']);
    $assert($result['worker']['uuid'] === 'abc' && $result['token'] === str_repeat('a', 64));
    $assert(! isset($transport->requests[0]->headers['Authorization']));
    $assert(count($transport->requests) === 1, 'single-use activation is not retried');
    $assert(isset($transport->requests[0]->headers['X-Request-ID']));
});

$test('activation rejects malformed success payload and invalid code clearly', static function () use ($expect, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(201, ['worker' => [], 'token' => 'bad']);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/activate-invalid');
    $expect(static fn () => $client->activate('x', 1, '0.1.0'), ProtocolException::class);
    $transport2 = new FakeTransport(); $transport2->responses[] = $response(401, ['success' => false]);
    [$client2] = $makeClient($transport2, new FakeSleeper(), $temporaryRoot.'/activate-expired');
    $exception = $expect(static fn () => $client2->activate(str_repeat('x', 64), 1, '0.1.0'), AuthenticationException::class);
    if (! str_contains($exception->getMessage(), 'expired')) throw new RuntimeException('activation error was not clear');
});

$test('worker status uses bearer auth and parses actual status fields', static function () use ($assert, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(200, ['uuid' => 'abc', 'label' => 'PC', 'status' => 'idle', 'protocol_version' => 1, 'last_heartbeat_at' => null]);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/status');
    $data = $client->getCurrentWorker(str_repeat('t', 64));
    $assert($data['status'] === 'idle');
    $assert($transport->requests[0]->url === 'https://scholarship.test/api/v1/crawler/workers/me');
    $assert($transport->requests[0]->headers['Authorization'] === 'Bearer '.str_repeat('t', 64));
    $assert($transport->requests[0]->headers['Accept'] === 'application/json');
});

$test('claim sends idempotency key, validates lease schema, and handles no job', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    $job = ['job_id' => 8, 'attempt_id' => 21, 'lease_generation' => 1, 'lease_token' => str_repeat('d', 64), 'lease_expires_at' => '2030-01-01T00:00:00Z', 'requested_url' => 'https://example.test/apply', 'allowed_path_prefix' => '/apply', 'source_id' => 4, 'registered_source_url' => 'https://example.test', 'source_concurrency_limit' => 1, 'robots_policy' => 'allowed'];
    $transport = new FakeTransport(); $transport->responses[] = $response(200, $job); $transport->responses[] = $response(200, null);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/claim');
    $assert($client->claimJob(str_repeat('t', 64), 'claim-key')['attempt_id'] === 21);
    $assert(json_decode($transport->requests[0]->body, true) === ['claim_request_key' => 'claim-key']);
    $assert($client->claimJob(str_repeat('t', 64), 'empty-key') === null);
    $bad = new FakeTransport(); $bad->responses[] = $response(200, ['job_id' => 1]);
    [$badClient] = $makeClient($bad, new FakeSleeper(), $temporaryRoot.'/claim-bad');
    $expect(static fn () => $badClient->claimJob('t', 'bad'), ProtocolException::class);
});

$test('heartbeat sends attempt path and lease token only in JSON body', static function () use ($assert, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(200, ['lease_expires_at' => '2030-01-01T00:00:00Z', 'lease_generation' => 3]);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/heartbeat');
    $client->heartbeat(str_repeat('t', 64), 8, 21, str_repeat('z', 64));
    $request = $transport->requests[0];
    $assert($request->url === 'https://scholarship.test/api/v1/crawler/jobs/8/attempts/21/heartbeat');
    $assert(json_decode($request->body, true) === ['lease_token' => str_repeat('z', 64)]);
    $assert($request->headers['Content-Type'] === 'application/json');
});

$test('artifact upload and result submission reuse stable idempotency keys and exact payload bytes', static function () use ($assert, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport();
    $transport->responses[] = $response(201, ['artifact_id' => 'artifact-uuid']);
    $transport->responses[] = $response(200, ['state' => 'completed']);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/result-handoff');
    $meta = ['content_type' => 'application/pdf', 'sha256' => str_repeat('a', 64)];
    $client->uploadArtifact(str_repeat('t', 64), 8, 21, $meta, '%PDF-payload', 'artifact:stable');
    $client->submitFetchResult(str_repeat('t', 64), 8, 21, ['outcome' => 'fetched'], 'result:stable');
    $upload = $transport->requests[0];
    $assert($upload->url === 'https://scholarship.test/api/v1/crawler/jobs/8/attempts/21/artifacts');
    $assert($upload->body === '%PDF-payload' && $upload->headers['Idempotency-Key'] === 'artifact:stable');
    $assert(json_decode(base64_decode(strtr($upload->headers['X-Crawler-Metadata'], '-_', '+/')), true) === $meta);
    $assert($transport->requests[1]->headers['Idempotency-Key'] === 'result:stable');
    $assert(json_decode($transport->requests[1]->body, true) === ['outcome' => 'fetched']);
});

$test('401, 403, 409 and 422 are not retried', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    foreach ([401 => AuthenticationException::class, 403 => \Scholarship\CrawlerWorker\Exception\AuthorizationException::class, 409 => NonRetryableApiException::class, 422 => NonRetryableApiException::class] as $status => $type) {
        $transport = new FakeTransport(); $transport->responses[] = $response($status, ['success' => false, 'message' => 'failure']);
        [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/status-'.$status);
        $expect(static fn () => $client->getCurrentWorker('secret'), $type);
        $assert(count($transport->requests) === 1, 'non-transient status retried');
    }
});

$test('429 respects Retry-After and 5xx retries remain bounded', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(429, ['error' => true], ['retry-after' => '1']);
    $transport->responses[] = $response(200, ['status' => 'ok']);
    $sleeper = new FakeSleeper(); [$client] = $makeClient($transport, $sleeper, $temporaryRoot.'/retry-429');
    $assert($client->health()['status'] === 'ok');
    $assert($sleeper->delays === [1000], 'Retry-After delay not respected');
    $serverErrors = new FakeTransport();
    $serverErrors->responses = [$response(503, []), $response(500, []), $response(502, [])];
    $retrySleeper = new FakeSleeper(); [$retryClient] = $makeClient($serverErrors, $retrySleeper, $temporaryRoot.'/retry-5xx');
    $expect(static fn () => $retryClient->health(), RetryableApiException::class);
    $assert(count($serverErrors->requests) === 3, 'retry cap is not bounded to configured retries');
});

$test('transport failures retry up to the configured limit', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport();
    $transport->responses = [new TransportException('safe transport error'), new TransportException('safe transport error'), $response(200, ['status' => 'ok'])];
    $sleeper = new FakeSleeper(); [$client] = $makeClient($transport, $sleeper, $temporaryRoot.'/retry-transport');
    $assert($client->health()['status'] === 'ok');
    $assert(count($transport->requests) === 3 && count($sleeper->delays) === 2);
    $failed = new FakeTransport(); $failed->responses = [new TransportException('safe'), new TransportException('safe'), new TransportException('safe')];
    [$failedClient] = $makeClient($failed, new FakeSleeper(), $temporaryRoot.'/retry-exhausted');
    $expect(static fn () => $failedClient->health(), TransportException::class);
});

$test('malformed JSON and envelope errors are non-retryable protocol failures', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    foreach (['{bad json', '{"success":true,"message":"ok"}', '{"success":false,"message":"no","data":[]}'] as $body) {
        $transport = new FakeTransport(); $transport->responses[] = $response(200, $body);
        [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/malformed-'.strlen($body));
        $expect(static fn () => $client->health(), ProtocolException::class);
        $assert(count($transport->requests) === 1);
    }
});

$test('request ID is a UUID and present on every wire attempt', static function () use ($assert, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(503, []); $transport->responses[] = $response(200, ['status' => 'ok']);
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/request-id');
    $client->health();
    $ids = array_map(static fn (HttpRequest $request): string => $request->headers['X-Request-ID'], $transport->requests);
    $assert(preg_match('/^[0-9a-f-]{36}$/i', $ids[0]) === 1 && $ids[0] === $ids[1]);
});

$test('structured logs redact tokens, activation codes and authorization values', static function () use ($assert, $temporaryRoot): void {
    $secret = str_repeat('s', 64); $logger = new StructuredLogger($temporaryRoot.'/redacted-logs', false); $logger->rememberSecret($secret);
    $logger->log('info', 'test.event', ['message' => 'received '.$secret, 'access_token' => $secret, 'request_id' => 'rid-1']);
    $contents = file_get_contents($temporaryRoot.'/redacted-logs/worker.log');
    $assert(! str_contains($contents, $secret));
    $assert(str_contains($contents, '[REDACTED]') && str_contains($contents, 'rid-1'));
});

$test('development credential store supports store retrieve exists and delete', static function () use ($assert, $temporaryRoot): void {
    $path = $temporaryRoot.'/credential-dev/secret.json'; $store = new DevelopmentFileCredentialStore($path); $token = str_repeat('p', 64);
    $store->store('worker-ref', $token);
    $assert($store->exists('worker-ref') && $store->retrieve('worker-ref') === $token);
    $assert(str_contains(file_get_contents($path), $token), 'development adapter is explicitly plaintext');
    $store->delete('worker-ref');
    $assert(! $store->exists('worker-ref'));
});

$test('Windows Credential Manager stores and removes an ephemeral test credential', static function () use ($assert): void {
    if (PHP_OS_FAMILY !== 'Windows' || ! is_executable('C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe')) return;
    $store = new WindowsCredentialManagerStore(); $reference = 'ScholarshipTracker.CrawlerWorker.Test.'.bin2hex(random_bytes(5)); $token = bin2hex(random_bytes(32));
    $stored = false;
    try {
        $store->store($reference, $token);
        $stored = true;
        $assert($store->exists($reference) && $store->retrieve($reference) === $token);
    } catch (\Scholarship\CrawlerWorker\Exception\LocalStorageException $exception) {
        if (str_contains($exception->getMessage(), 'Windows error 1312')) throw new SkippedTest('Windows Credential Manager has no interactive logon session in this test environment.');
        throw $exception;
    } finally {
        if ($stored) $store->delete($reference);
    }
    $assert(! $store->exists($reference));
});

$test('identity store remembers non-secret worker and API identity', static function () use ($assert, $temporaryRoot): void {
    $store = new WorkerIdentityStore($temporaryRoot.'/identity/worker.json');
    $record = ['worker_uuid' => 'uuid-1', 'label' => 'PC', 'api_base_url' => 'https://server.test', 'expires_at' => '2030-01-01T00:00:00Z'];
    $store->save($record);
    $assert($store->load() === $record);
    $assert(! str_contains(file_get_contents($temporaryRoot.'/identity/worker.json'), 'token'));
});

$test('local spool creates metadata, bounds writes, finalizes, and deletes', static function () use ($assert, $expect, $temporaryRoot): void {
    $spool = new LocalSpool($temporaryRoot.'/spool-test', 4); $entry = $spool->createEntry(12, 24, ['communication_only' => true]);
    $spool->writeBounded($entry, '1234');
    $assert($spool->readMetadata($entry)['bytes'] === 4);
    $expect(static fn () => $spool->writeBounded($entry, '5'), \Scholarship\CrawlerWorker\Exception\LocalStorageException::class);
    $spool->finalize($entry);
    $assert($spool->readMetadata($entry)['state'] === 'finalized');
    $assert($spool->readPayload($entry) === '1234');
    $expect(static fn () => $spool->writeBounded($entry, 'x'), \Scholarship\CrawlerWorker\Exception\LocalStorageException::class);
    $spool->delete($entry);
    $assert(! is_dir($temporaryRoot.'/spool-test/'.$entry));
});

$test('spool rejects traversal identifiers and cleans old entries', static function () use ($assert, $expect, $temporaryRoot): void {
    $spool = new LocalSpool($temporaryRoot.'/spool-clean', 20);
    $expect(static fn () => $spool->createEntry(0, 1), \Scholarship\CrawlerWorker\Exception\LocalStorageException::class);
    $expect(static fn () => $spool->readMetadata('../../secret'), \Scholarship\CrawlerWorker\Exception\LocalStorageException::class);
    $entry = $spool->createEntry(1, 2);
    touch($temporaryRoot.'/spool-clean/'.$entry, time() - 100);
    $assert($spool->cleanupOlderThan(time() - 10) === 1);
});

$test('application activation stores token without logging it', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/app-activation'); $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $store = new MemoryCredentials();
    $app = new WorkerApplication($config, $api, $store, new WorkerIdentityStore($config->identityFile), new LocalSpool($config->spoolDirectory, 100), $logger, new ShutdownController($logger));
    $identity = $app->activate(str_repeat('c', 64)); $token = $api->activation['token'];
    $assert($identity['worker_uuid'] === 'worker-uuid' && $store->retrieve($config->credentialReference) === $token);
    $assert(! str_contains(file_get_contents($config->logDirectory.'/worker.log'), $token));
    $assert(! str_contains(file_get_contents($config->logDirectory.'/worker.log'), str_repeat('c', 64)));
});

$test('application status and doctor authenticate but expose no credential', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/doctor'); $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $store = new MemoryCredentials();
    $store->store($config->credentialReference, $api->activation['token']);
    $identity = new WorkerIdentityStore($config->identityFile);
    $identity->save(['worker_uuid' => 'worker-uuid', 'label' => 'test worker', 'api_base_url' => $config->apiBaseUrl, 'expires_at' => '2030-01-01T00:00:00Z']);
    $app = new WorkerApplication($config, $api, $store, $identity, new LocalSpool($config->spoolDirectory, 100), $logger, new ShutdownController($logger));
    $status = $app->status(); $doctor = $app->doctor();
    $assert($status['worker']['uuid'] === 'worker-uuid' && ! isset($status['token']));
    $assert($doctor['api_health'] === 'ok' && $doctor['worker_authenticated'] === true);
    $assert(array_column($api->calls, 0) === ['me', 'health', 'me']);
});

$test('once mode handles empty queue without heartbeat', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/once-empty'); $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $api->claim = null; $store = new MemoryCredentials(); $store->store($config->credentialReference, 'token');
    (new WorkerIdentityStore($config->identityFile))->save(['worker_uuid' => 'worker-uuid', 'label' => 'test worker', 'api_base_url' => $config->apiBaseUrl, 'expires_at' => '2030-01-01T00:00:00Z']);
    $app = new WorkerApplication($config, $api, $store, new WorkerIdentityStore($config->identityFile), new LocalSpool($config->spoolDirectory, 100), $logger, new ShutdownController($logger));
    $assert($app->once() === null && count($api->calls) === 1);
});

$test('once mode records a claim, spools metadata and heartbeats without fetching', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/once-job'); $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $store = new MemoryCredentials(); $store->store($config->credentialReference, 'token');
    $api->claim = ['job_id' => 5, 'attempt_id' => 9, 'lease_generation' => 1, 'lease_token' => str_repeat('z', 64), 'requested_url' => 'https://example.test', 'allowed_path_prefix' => '/', 'lease_expires_at' => '2030-01-01T00:00:00Z', 'source_id' => 4, 'registered_source_url' => 'https://example.test', 'source_concurrency_limit' => 1, 'robots_policy' => 'allowed'];
    $identity = new WorkerIdentityStore($config->identityFile); $identity->save(['worker_uuid' => 'worker-uuid', 'label' => 'test worker', 'api_base_url' => $config->apiBaseUrl, 'expires_at' => '2030-01-01T00:00:00Z']);
    $spool = new LocalSpool($config->spoolDirectory, 100);
    $app = new WorkerApplication($config, $api, $store, $identity, $spool, $logger, new ShutdownController($logger));
    $app->once();
    $assert(array_column($api->calls, 0) === ['claim', 'heartbeat']);
    $assert($spool->readMetadata('5-9')['metadata']['state'] === 'communication_only');
    $assert(! str_contains(file_get_contents($config->logDirectory.'/worker.log'), str_repeat('z', 64)));
});

$test('worker once mode safely fetches then uploads artifact and submits the result before deleting spool', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/worker-handoff', ['max_spool_entry_bytes' => 1024]);
    $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $store = new MemoryCredentials();
    $store->store($config->credentialReference, 'token');
    $api->claim = ['job_id' => 55, 'attempt_id' => 66, 'lease_generation' => 4, 'lease_token' => str_repeat('z', 64),
        'requested_url' => 'https://example.test/scholarships/start', 'allowed_path_prefix' => '/scholarships',
        'lease_expires_at' => '2030-01-01T00:00:00Z', 'source_id' => 7, 'registered_source_url' => 'https://example.test',
        'source_concurrency_limit' => 1, 'robots_policy' => 'allowed'];
    $identity = new WorkerIdentityStore($config->identityFile);
    $identity->save(['worker_uuid' => 'worker-uuid', 'label' => 'test worker', 'api_base_url' => $config->apiBaseUrl, 'expires_at' => '2030-01-01T00:00:00Z']);
    $dns = new class implements DnsResolverInterface { public function resolve(string $hostname): array { return ['93.184.216.34']; } };
    $http = new FakeFetchHttp(); $http->fixtures['/robots.txt'] = [['status' => 404, 'body' => '']];
    $body = '{"candidate":{"title":"Fixture"}}';
    $http->fixtures['/scholarships/start'] = [['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => $body]];
    $spool = new LocalSpool($config->spoolDirectory, 1024);
    $fetcher = new SafeFetcher(new UrlPolicy(new IpAddressPolicy(), $dns), $http, new RobotsPolicy(), new RetryPolicy(0, 1, 5, new FakeSleeper()), $spool, 1024, 2, ['text/html', 'application/json'], 0);
    $app = new WorkerApplication($config, $api, $store, $identity, $spool, $logger, new ShutdownController($logger), $fetcher);
    $app->once();
    $calls = array_column($api->calls, 0);
    $assert(array_search('artifact', $calls, true) !== false && array_search('result', $calls, true) > array_search('artifact', $calls, true));
    $upload = $api->calls[array_search('artifact', $calls, true)];
    $assert($upload[3] === strlen($body));
    $assert(! is_dir($config->spoolDirectory.'/55-66'), 'acknowledged spool entry should be removed');
});

$test('poll mode has a bounded cycle count and does not repeatedly heartbeat a no-op job', static function () use ($assert, $makeConfig, $temporaryRoot): void {
    $config = $makeConfig($temporaryRoot.'/poll-bounded'); $logger = new StructuredLogger($config->logDirectory, false); $api = new FakeApi(); $store = new MemoryCredentials(); $store->store($config->credentialReference, 'token');
    $api->claim = ['job_id' => 6, 'attempt_id' => 10, 'lease_generation' => 1, 'lease_token' => str_repeat('e', 64), 'requested_url' => 'https://example.test', 'allowed_path_prefix' => '/', 'lease_expires_at' => gmdate('c', time() + 60), 'source_id' => 4, 'registered_source_url' => 'https://example.test', 'source_concurrency_limit' => 1, 'robots_policy' => 'allowed'];
    $identity = new WorkerIdentityStore($config->identityFile); $identity->save(['worker_uuid' => 'worker-uuid', 'label' => 'test worker', 'api_base_url' => $config->apiBaseUrl, 'expires_at' => '2030-01-01T00:00:00Z']);
    $app = new WorkerApplication($config, $api, $store, $identity, new LocalSpool($config->spoolDirectory, 100), $logger, new ShutdownController($logger));
    $app->poll(2);
    $assert(array_column($api->calls, 0) === ['claim', 'heartbeat']);
});

$test('retry-after seconds and HTTP-date are parsed within configured cap', static function () use ($assert, $makeClient, $temporaryRoot): void {
    $sleeper = new FakeSleeper(); $config = $makeClient(new FakeTransport(), $sleeper, $temporaryRoot.'/retry-after-config', ['retry_max_delay_ms' => 1500])[1];
    $policy = new RetryPolicy(2, 100, 1500, $sleeper, static fn (int $delay): int => 0);
    $policy->pause(1, '2');
    $assert($sleeper->delays[0] === 1500, 'Retry-After must be capped to configured maximum');
    $future = gmdate('D, d M Y H:i:s', time() + 1).' GMT';
    $policy->pause(1, $future);
    $assert($sleeper->delays[1] > 0 && $sleeper->delays[1] <= 1500);
    $assert($config->protocolVersion === 1);
});

$test('worker source has no Laravel or MySQL dependency and never bootstraps Laravel', static function () use ($assert, $root): void {
    $composer = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(! isset($composer['require']['laravel/framework']));
    $assert(! isset($composer['require']['ext-pdo_mysql']) && ! isset($composer['require']['ext-mysqli']));
    $entry = file_get_contents($root.'/bin/worker');
    $assert(! str_contains($entry, 'artisan') && ! str_contains($entry, 'Illuminate\\'));
    $assert(str_contains($composer['require']['php'], '8.3'));
});

$test('worker ignores credentials and spool contents in git', static function () use ($assert, $root): void {
    $ignore = file_get_contents($root.'/.gitignore');
    $assert(str_contains($ignore, '/storage/*') && str_contains($ignore, '/config/local.php'));
    $localExample = file_get_contents($root.'/config/local.example.php');
    $assert(! preg_match('/[a-f0-9]{64}/i', $localExample));
});

$test('CLI help executes without Laravel or an API connection', static function () use ($assert, $root): void {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/worker').' --help';
    exec($command.' 2>&1', $output, $code);
    $assert($code === 0 && str_contains(implode("\n", $output), 'does not extract scholarship data'));
});

$test('API errors never copy response bodies or secrets into messages', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    $secret = str_repeat('q', 64); $transport = new FakeTransport(); $transport->responses[] = $response(401, '{"message":"'.$secret.'"}');
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/safe-errors');
    $exception = $expect(static fn () => $client->getCurrentWorker($secret), AuthenticationException::class);
    $assert(! str_contains($exception->getMessage(), $secret));
    $log = file_get_contents($temporaryRoot.'/safe-errors/logs/worker.log');
    $assert(! str_contains($log, $secret));
});

$test('generic non-retryable API errors preserve status without response detail', static function () use ($assert, $expect, $makeClient, $response, $temporaryRoot): void {
    $transport = new FakeTransport(); $transport->responses[] = $response(404, '{"message":"private response detail"}');
    [$client] = $makeClient($transport, new FakeSleeper(), $temporaryRoot.'/not-found');
    $exception = $expect(static fn () => $client->getCurrentWorker('x'), ApiException::class);
    $assert($exception->status === 404 && ! str_contains($exception->getMessage(), 'private response detail'));
});

$test('worker temporary test directories are not produced inside source paths', static function () use ($assert, $root, $temporaryRoot): void {
    $assert(! str_starts_with($temporaryRoot, $root));
});

foreach ($failures as [$name, $exception]) {
    fwrite(STDERR, "{$name}: ".$exception::class.': '.$exception->getMessage().PHP_EOL);
}
fwrite(STDOUT, "Tests: {$tests}; Assertions: {$assertions}; Skipped: {$skipped}; Failures: ".count($failures).PHP_EOL);

exit($failures === [] ? 0 : 1);
