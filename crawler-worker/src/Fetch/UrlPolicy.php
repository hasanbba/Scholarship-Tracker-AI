<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class UrlPolicy
{
    public function __construct(private readonly IpAddressPolicy $ips, private readonly DnsResolverInterface $dns, private readonly bool $allowHttp = false) {}
    public function inspect(string $url, string $registeredUrl, string $pathPrefix, bool $robotsFile = false): array
    {
        $u = $this->parse($url); $s = $this->parse($registeredUrl);
        $scheme = strtolower($u['scheme']); $sourceScheme = strtolower($s['scheme']);
        if (! in_array($scheme, ['https','http'], true)) throw new FetchFailure('INVALID_URL');
        if ($scheme === 'http' && ! $this->allowHttp) throw new FetchFailure('HTTPS_POLICY_VIOLATION');
        if ($sourceScheme === 'https' && $scheme !== 'https') throw new FetchFailure('HTTPS_POLICY_VIOLATION');
        if ($u['host'] !== $s['host'] || ($u['port'] ?? ($scheme==='https'?443:80)) !== ($s['port'] ?? ($sourceScheme==='https'?443:80))) throw new FetchFailure('UNREGISTERED_ORIGIN');
        $path = $this->normalizePath($u['path'] ?: '/');
        $prefix = $this->normalizePath($pathPrefix ?: '/');
        $within = $path === rtrim($prefix, '/') || str_starts_with($path, rtrim($prefix, '/').'/');
        if ($robotsFile && $path === '/robots.txt') $within = true;
        if (! $within) throw new FetchFailure('PATH_NOT_ALLOWED');
        $addresses = $this->dns->resolve($u['host']); $this->ips->assertAllPublic($addresses);
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) throw new FetchFailure('INVALID_URL');
        return ['url' => $this->build($u), 'scheme' => $scheme, 'host' => $u['host'], 'port' => $port, 'addresses' => $addresses, 'path' => $path];
    }
    public function resolveRedirect(string $base, string $location): string
    {
        if (trim($location) === '' || preg_match('/[\r\n]/', $location)) throw new FetchFailure('REDIRECT_BLOCKED');
        if (parse_url($location, PHP_URL_SCHEME)) return $location;
        $b = $this->parse($base);
        if (str_starts_with($location, '//')) return $b['scheme'].':'.$location;
        $host=str_contains($b['host'],':')?'['.$b['host'].']':$b['host'];
        $authority = $b['scheme'].'://'.$host.(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($location, '?')) return $authority.($b['path']?:'/').$location;
        if (str_starts_with($location, '/')) return $authority.$location;
        $dir = substr($b['path'] ?: '/', 0, (int) strrpos($b['path'] ?: '/', '/') + 1);
        return $authority.$dir.$location;
    }
    public function safeLogUrl(string $url): string
    {
        $p=parse_url($url); if(!is_array($p)||!isset($p['scheme'],$p['host'])) return '[invalid-url]';
        $port=isset($p['port'])?':'.$p['port']:''; return strtolower($p['scheme']).'://'.strtolower($p['host']).$port.($p['path']??'/').(isset($p['query'])?'?[REDACTED]':'');
    }
    private function parse(string $url): array
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || ! isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) throw new FetchFailure('INVALID_URL');
        $host = strtolower(rtrim($p['host'], '.'));
        if(str_starts_with($host,'[')&&str_ends_with($host,']')) $host=substr($host,1,-1);
        if (preg_match('/[^\x20-\x7e]/', $host) || $host === '' || in_array($host, ['localhost','localhost.localdomain','ip6-localhost','metadata.google.internal','metadata.azure.internal','instance-data.ec2.internal'], true) || str_ends_with($host,'.localhost') || str_ends_with($host,'.local')) throw new FetchFailure('SSRF_BLOCKED');
        return ['scheme' => $p['scheme'], 'host' => $host, 'port' => $p['port'] ?? null, 'path' => $p['path'] ?? '/', 'query' => $p['query'] ?? null];
    }
    private function normalizePath(string $path): string
    {
        if (preg_match('/%(2f|5c|2e|25)/i', $path)) throw new FetchFailure('PATH_NOT_ALLOWED');
        $decoded = rawurldecode($path);
        if (preg_match('#(^|/)\.\.?(/|$)#', $decoded)) throw new FetchFailure('PATH_NOT_ALLOWED');
        $segments = [];
        foreach (explode('/', $decoded) as $segment) { if ($segment === '' || $segment === '.') continue; if ($segment === '..') throw new FetchFailure('PATH_NOT_ALLOWED'); $segments[] = rawurlencode($segment); }
        return '/'.implode('/', $segments).(str_ends_with($decoded, '/') && $segments ? '/' : '');
    }
    private function build(array $p): string { $host=str_contains($p['host'],':')?'['.$p['host'].']':$p['host'];return strtolower($p['scheme']).'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').$this->normalizePath($p['path']).(isset($p['query']) ? '?'.$p['query'] : ''); }
}
