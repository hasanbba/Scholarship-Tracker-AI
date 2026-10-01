<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
use Scholarship\CrawlerWorker\Api\RetryPolicy;
use Scholarship\CrawlerWorker\Spool\LocalSpoolInterface;
final class SafeFetcher
{
    private readonly SourceRateLimiter $rateLimiter;
    private float $deadline = 0;
    public function __construct(private readonly UrlPolicy $urls, private readonly FetchHttpClientInterface $http, private readonly RobotsPolicy $robots, private readonly RetryPolicy $retries, private readonly LocalSpoolInterface $spool, private readonly int $maxBytes = 4194304, private readonly int $maxRedirects = 4, private readonly array $contentTypes = ['text/html','application/xhtml+xml','application/json','application/pdf'], private readonly int $minimumDelayMs = 1000, private readonly int $operationTimeoutSeconds = 90, ?SourceRateLimiter $rateLimiter = null) { $this->rateLimiter=$rateLimiter??new SourceRateLimiter(); }

    public function fetch(array $job, callable $heartbeat): FetchResult
    {
        $start=microtime(true); $this->deadline=$start+$this->operationTimeoutSeconds; $requested=(string)$job['requested_url']; $registered=(string)$job['registered_source_url']; $prefix=(string)$job['allowed_path_prefix'];
        if (($job['robots_policy'] ?? '') !== 'allowed') throw new FetchFailure('ROBOTS_UNAVAILABLE', false, 'Source robots policy is not approved.');
        $initial=$this->inspectWithRetries($requested,$registered,$prefix); $initial['source_id']=(int)$job['source_id'];
        $origin=$initial['scheme'].'://'.$initial['host'].($initial['port']===($initial['scheme']==='https'?443:80)?'':':'.$initial['port']);
        if (! $this->robots->allowed($origin,$initial['path'],fn()=> $this->getRobots($registered,$prefix,(int)$job['source_id'],$heartbeat))) throw new FetchFailure('ROBOTS_BLOCKED');
        $entry=$this->spool->createEntry((int)$job['job_id'],(int)$job['attempt_id'],['lease_generation'=>(int)$job['lease_generation'],'state'=>'fetching']);
        try {
            $redirects=[]; $target=$requested;
            for ($hop=0; $hop <= $this->maxRedirects; $hop++) {
                $checked=$this->inspectWithRetries($target,$registered,$prefix); $checked['source_id']=(int)$job['source_id']; $hash=hash_init('sha256');
                $response=$this->requestWithRetries($checked,[],function(string $chunk,int $status)use($hash,$entry){if($status>=200&&$status<300){hash_update($hash,$chunk);$this->spool->writeBounded($entry,$chunk);return true;}return null;},$heartbeat,$this->maxBytes);
                $contentType=$this->baseType($response['headers']['content-type'] ?? null);
                if (in_array($response['status'],[301,302,303,307,308],true)) {
                    if ($hop >= $this->maxRedirects) throw new FetchFailure('REDIRECT_LIMIT');
                    $location=$response['headers']['location'] ?? null; if (!is_string($location)) throw new FetchFailure('REDIRECT_BLOCKED');
                    $next=$this->urls->resolveRedirect($checked['url'],$location); $nextCheck=$this->inspectWithRetries($next,$registered,$prefix);
                    if (str_starts_with(strtolower($checked['url']),'https://') && !str_starts_with(strtolower($next),'https://')) throw new FetchFailure('HTTPS_POLICY_VIOLATION');
                    if(!$this->robots->allowed($origin,$nextCheck['path'],fn()=> $this->getRobots($registered,$prefix,(int)$job['source_id'],$heartbeat))) throw new FetchFailure('ROBOTS_BLOCKED');
                    $redirects[]=['from'=>$this->urls->safeLogUrl($checked['url']),'to'=>$this->urls->safeLogUrl($next),'status'=>$response['status']]; $target=$next; continue;
                }
                if ($response['status']===401) throw new FetchFailure('HTTP_401',false,'Authentication is required by the source.',401); if ($response['status']===403) throw new FetchFailure('HTTP_403',false,'Source denied the request.',403); if ($response['status']===404) throw new FetchFailure('HTTP_404',false,'Source page was not found.',404);
                if ($response['status']===429) throw new FetchFailure('HTTP_429',true,'Source rate limit was reached.',429); if ($response['status']===408) throw new FetchFailure('TIMEOUT',true,'Source request timed out.',408);
                if ($response['status']>=500) throw new FetchFailure('HTTP_5XX',true,'Source server failure.',$response['status']); if ($response['status']!==200) throw new FetchFailure('MALFORMED_RESPONSE',false,'Only complete HTTP 200 responses are artifacts.',$response['status']);
                if ($contentType===null || !in_array($contentType,$this->contentTypes,true)) throw new FetchFailure('UNSUPPORTED_CONTENT_TYPE');
                if(isset($response['headers']['content-encoding']) && strtolower((string)$response['headers']['content-encoding'])!=='identity') throw new FetchFailure('MALFORMED_RESPONSE',false,'Compressed response encoding is not supported.');
                $this->spool->finalize($entry); $digest=hash_final($hash);
                $safeHeaders=[]; foreach(['etag','last-modified','content-language'] as $header) if(isset($response['headers'][$header])) $safeHeaders[$header]=substr((string)$response['headers'][$header],0,512);
                $result=new FetchResult(['outcome'=>'fetched','job_id'=>(int)$job['job_id'],'attempt_id'=>(int)$job['attempt_id'],'requested_url'=>$this->urls->safeLogUrl($requested),'final_url'=>$this->urls->safeLogUrl($checked['url']),'status_code'=>$response['status'],'content_type'=>$contentType,'content_length'=>$response['bytes'],'sha256'=>$digest,'artifact_reference'=>$entry.'/payload.bin','fetched_at'=>gmdate('c'),'redirects'=>$redirects,'robots_result'=>'allowed','duration_ms'=>(int)((microtime(true)-$start)*1000),'failure_code'=>null,'retryable'=>false,'response_metadata'=>$safeHeaders]);
                $metadata=$this->spool->readMetadata($entry); $metadata['state']='finalized'; $metadata['fetch_result']=$result->data; $this->spool->updateMetadata($entry,$metadata);
                return $result;
            }
            throw new FetchFailure('REDIRECT_LIMIT');
        } catch (\Throwable $e) { try{$this->spool->delete($entry);}catch(\Throwable){} throw $e; }
    }
    public function safeLogUrl(string $url): string { return $this->urls->safeLogUrl($url); }

    private function getRobots(string $registered,string $prefix,int $sourceId,callable $heartbeat): string
    {
        $p=parse_url($registered); $url=strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'').'/robots.txt';
        $target=$this->inspectWithRetries($url,$registered,$prefix,true); $target['source_id']=$sourceId; $body='';
        $response=$this->requestWithRetries($target,[],function(string $chunk,int $status)use(&$body){if($status===200){if(strlen($body)+strlen($chunk)>262144)throw new FetchFailure('ROBOTS_UNAVAILABLE');$body.=$chunk;return true;}return null;},$heartbeat,262144);
        if ($response['status']===404) return '';
        $type=$this->baseType($response['headers']['content-type']??null);
        if ($response['status']!==200 || $type!=='text/plain' || !mb_check_encoding($body,'UTF-8') || str_contains($body,"\0")) throw new FetchFailure('ROBOTS_UNAVAILABLE');
        return preg_replace('/^\xEF\xBB\xBF/','',$body) ?? $body;
    }
    private function requestWithRetries(array $target,array $headers,callable $write,callable $heartbeat,int $limit): array
    {
        $sourceId=(int)($target['source_id']??0);$origin=$target['scheme'].'://'.$target['host'].':'.$target['port'];
        for($i=0;;$i++){if(microtime(true)>=$this->deadline)throw new FetchFailure('TIMEOUT',false,'Fetch operation deadline exceeded.');$this->rateLimiter->await($sourceId,$origin,$this->minimumDelayMs);if(microtime(true)>=$this->deadline)throw new FetchFailure('TIMEOUT',false,'Fetch operation deadline exceeded.');$wrote=false;$wrapped=function(string $chunk,int $status)use($write,&$wrote,$heartbeat){if(microtime(true)>=$this->deadline)throw new FetchFailure('TIMEOUT',false,'Fetch operation deadline exceeded.');$heartbeat();$result=$write($chunk,$status);$wrote=$wrote||$result===true;return $result;};$guard=fn()=>microtime(true)>=$this->deadline?throw new FetchFailure('TIMEOUT',false,'Fetch operation deadline exceeded.'):$heartbeat();try{$r=$this->http->get($target,['User-Agent'=>'ScholarshipTrackerCrawler/0.1.0'], $limit,$wrapped,$guard);$this->rateLimiter->mark($sourceId,$origin);if(in_array($r['status'],[408,429,500,502,503,504],true)&&$i<2&&!$wrote){$this->retries->pause($i+1,$r['headers']['retry-after']??null);continue;}return $r;}catch(FetchFailure $e){$this->rateLimiter->mark($sourceId,$origin);if(!$e->retryable||$wrote||$i>=2)throw $e;$this->retries->pause($i+1);}}
    }
    private function baseType(?string $value):?string { if($value===null)return null;return strtolower(trim(explode(';',$value,2)[0])); }
    private function inspectWithRetries(string $url,string $registered,string $prefix,bool $robotsFile=false):array
    {
        for($i=0;;$i++){try{return $this->urls->inspect($url,$registered,$prefix,$robotsFile);}catch(FetchFailure $e){if($e->failureCode!=='DNS_FAILURE'||$i>=2)throw $e;$this->retries->pause($i+1);}}
    }
}
