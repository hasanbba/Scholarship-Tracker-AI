<?php

declare(strict_types=1);

namespace Scholarship\CrawlerWorker\Credential;

use Scholarship\CrawlerWorker\Exception\ConfigurationException;
use Scholarship\CrawlerWorker\Exception\LocalStorageException;

/** Uses Windows Credential Manager generic credentials via Advapi32 CredWrite/CredRead/CredDelete. */
final class WindowsCredentialManagerStore implements CredentialStoreInterface
{
    public function store(string $reference, string $credential): void { $this->invoke('store', $reference, $credential); }
    public function retrieve(string $reference): ?string
    {
        $result = $this->invoke('read', $reference);
        return $result === '' ? null : $result;
    }
    public function delete(string $reference): void { $this->invoke('delete', $reference); }
    public function exists(string $reference): bool { return $this->retrieve($reference) !== null; }

    private function invoke(string $operation, string $reference, ?string $secret = null): string
    {
        if (PHP_OS_FAMILY !== 'Windows') throw new ConfigurationException('Windows Credential Manager is only available on Windows.');
        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$inputData = [Console]::In.ReadToEnd() | ConvertFrom-Json
$source = @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class WorkerCredMan {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
  public struct CREDENTIAL {
    public UInt32 Flags; public UInt32 Type; public string TargetName; public string Comment;
    public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten; public UInt32 CredentialBlobSize;
    public IntPtr CredentialBlob; public UInt32 Persist; public UInt32 AttributeCount; public IntPtr Attributes;
    public string TargetAlias; public string UserName;
  }
  [DllImport("Advapi32.dll", EntryPoint="CredWriteW", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool CredWrite(ref CREDENTIAL credential, UInt32 flags);
  [DllImport("Advapi32.dll", EntryPoint="CredReadW", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool CredRead(string target, UInt32 type, UInt32 flags, out IntPtr credential);
  [DllImport("Advapi32.dll", EntryPoint="CredDeleteW", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool CredDelete(string target, UInt32 type, UInt32 flags);
  [DllImport("Advapi32.dll")] public static extern void CredFree(IntPtr buffer);
  public static void Store(string target, string value) {
    byte[] bytes=Encoding.UTF8.GetBytes(value); IntPtr blob=Marshal.AllocHGlobal(bytes.Length);
    try { Marshal.Copy(bytes,0,blob,bytes.Length); CREDENTIAL c=new CREDENTIAL {Type=1,TargetName=target,UserName="Scholarship Tracker worker",CredentialBlob=blob,CredentialBlobSize=(UInt32)bytes.Length,Persist=2}; if(!CredWrite(ref c,0)) throw new Exception("Win32 "+Marshal.GetLastWin32Error()); }
    finally { Marshal.FreeHGlobal(blob); Array.Clear(bytes,0,bytes.Length); }
  }
  public static string Read(string target) {
    IntPtr pointer; if(!CredRead(target,1,0,out pointer)) { int e=Marshal.GetLastWin32Error(); if(e==1168) return ""; throw new Exception("Win32 "+e); }
    try { CREDENTIAL c=(CREDENTIAL)Marshal.PtrToStructure(pointer,typeof(CREDENTIAL)); byte[] bytes=new byte[c.CredentialBlobSize]; Marshal.Copy(c.CredentialBlob,bytes,0,bytes.Length); string value=Encoding.UTF8.GetString(bytes); Array.Clear(bytes,0,bytes.Length); return value; }
    finally { CredFree(pointer); }
  }
  public static void Delete(string target) { if(!CredDelete(target,1,0) && Marshal.GetLastWin32Error()!=1168) throw new Exception(); }
}
'@
Add-Type -TypeDefinition $source
switch ($inputData.operation) {
  'store' { [WorkerCredMan]::Store([string]$inputData.reference,[string]$inputData.secret); [Console]::Out.Write('OK') }
  'read' { [Console]::Out.Write([WorkerCredMan]::Read([string]$inputData.reference)) }
  'delete' { [WorkerCredMan]::Delete([string]$inputData.reference); [Console]::Out.Write('OK') }
  default { exit 2 }
}
POWERSHELL;
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $process = @proc_open(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) throw new LocalStorageException('Could not start Windows Credential Manager adapter.');
        $json = json_encode(['operation' => $operation, 'reference' => $reference, 'secret' => $secret], JSON_THROW_ON_ERROR);
        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $diagnostic = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || ! is_string($output)) {
            $osCode = is_string($diagnostic) && preg_match('/Win32 (\d+)/', $diagnostic, $matches) ? (int) $matches[1] : null;
            throw new LocalStorageException('Windows Credential Manager operation failed'.($osCode ? ' (Windows error '.$osCode.')' : '').'.');
        }
        return $output;
    }
}
