<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class SourceRateLimiter
{
    private array $lastRequestAt=[];
    public function await(int $sourceId,string $origin,int $minimumDelayMs):void
    {
        $origin=strtolower($origin);$keys=[$sourceId.'|'.$origin,'origin|'.$origin];$wait=0;
        foreach($keys as $key)$wait=max($wait,$minimumDelayMs-(int)((microtime(true)-($this->lastRequestAt[$key]??0.0))*1000));
        if($wait>0)usleep($wait*1000);
    }
    public function mark(int $sourceId,string $origin):void { $time=microtime(true);$origin=strtolower($origin);$this->lastRequestAt[$sourceId.'|'.$origin]=$time;$this->lastRequestAt['origin|'.$origin]=$time; }
}
