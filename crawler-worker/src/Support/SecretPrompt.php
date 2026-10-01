<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Support;

use Scholarship\CrawlerWorker\Exception\LocalStorageException;

final class SecretPrompt
{
    public function read(string $prompt): string
    {
        fwrite(STDOUT, $prompt.PHP_EOL);
        if (PHP_OS_FAMILY === 'Windows') {
            $script = '$s=Read-Host -Prompt "Activation code" -AsSecureString; $p=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); try { [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p) }';
            $process = @proc_open(['powershell.exe', '-NoLogo', '-NoProfile', '-Command', $script], [0 => ['file', 'php://stdin', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (! is_resource($process)) throw new LocalStorageException('Could not open the secure activation prompt.');
            $secret = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            if ($status !== 0 || ! is_string($secret)) throw new LocalStorageException('Secure activation prompt failed.');
            return trim($secret);
        }

        if (function_exists('readline')) return trim((string) readline('Activation code: '));
        $secret = fgets(STDIN);
        return is_string($secret) ? trim($secret) : '';
    }
}
