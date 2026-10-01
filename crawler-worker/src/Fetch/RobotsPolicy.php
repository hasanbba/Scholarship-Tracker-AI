<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class RobotsPolicy
{
    private array $cache = [];
    public function allowed(string $origin, string $path, callable $load): bool
    {
        $key = strtolower($origin); $now = time();
        if (! isset($this->cache[$key]) || $this->cache[$key]['expires'] <= $now) {
            try { $this->cache[$key] = ['expires'=>$now+3600,'rules'=>$this->parse((string)$load())]; }
            catch (\Throwable) { throw new FetchFailure('ROBOTS_UNAVAILABLE', false, 'Robots policy could not be evaluated.'); }
        }
        $rule = null;
        foreach ($this->cache[$key]['rules'] as [$allow,$pattern]) {
            if ($pattern === '') continue;
            $anchored=str_ends_with($pattern,'$'); $regex=preg_quote($anchored?substr($pattern,0,-1):$pattern,'#'); $regex=str_replace('\\*','.*',$regex);
            if (preg_match('#^'.$regex.($anchored?'$':'').'#u',$path)===1) {
                $specificity=strlen(str_replace(['*','$'],'',$pattern));
                if($rule===null||$specificity>$rule[1]||($specificity===$rule[1]&&$allow)) $rule=[$allow,$specificity];
            }
        }
        return $rule === null || $rule[0];
    }
    private function parse(string $body): array
    {
        $rules=[]; $active=false; $hasGroup=false; $sawAgent=false;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line=trim(explode('#',$line,2)[0]); if ($line==='') continue; if (!str_contains($line,':')) throw new FetchFailure('ROBOTS_UNAVAILABLE'); [$name,$value]=array_map('trim',explode(':',$line,2));
            if (strcasecmp($name,'user-agent')===0) { if($value==='')throw new FetchFailure('ROBOTS_UNAVAILABLE');$sawAgent=true;if ($hasGroup) $active=false; if ($value==='*' || strcasecmp($value,'ScholarshipTrackerCrawler')===0 || str_starts_with(strtolower($value),'scholarshiptrackercrawler/')) { $active=true; $hasGroup=true; } }
            elseif ($active && in_array(strtolower($name),['allow','disallow'],true)) $rules[]=[strtolower($name)==='allow',$value];
        }
        if(trim($body)!==''&&!$sawAgent)throw new FetchFailure('ROBOTS_UNAVAILABLE');
        return $rules;
    }
}
