<?php

declare(strict_types=1);

namespace Kabuto\Storage;

/**
 * ClamAV scanner adapter menggunakan clamd socket atau clamscan CLI.
 */
final class ClamAvScanner implements MalwareScanner
{
    public function __construct(
        private readonly string $socketPath = '/var/run/clamav/clamd.sock',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function scan(string $filePath): ScanResult
    {
        if (!is_file($filePath)) {
            throw new ScanException(sprintf('File [%s] does not exist.', $filePath));
        }

        if (file_exists($this->socketPath)) {
            return $this->scanViaSocket($filePath);
        }

        return $this->scanViaCli($filePath);
    }

    private function scanViaSocket(string $filePath): ScanResult
    {
        $socket = @stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, $this->timeoutSeconds);
        if ($socket === false) {
            throw new ScanException(sprintf('Cannot connect to clamd socket: %s', $errstr));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        fwrite($socket, "SCAN {$filePath}\n");
        $response = fgets($socket);
        fclose($socket);

        if ($response === false || $response === '') {
            throw new ScanException('Empty response from clamd.');
        }

        $lastLine = trim($response);

        if (str_contains($lastLine, 'OK')) {
            return ScanResult::clean('clamd');
        }

        if (str_contains($lastLine, 'FOUND')) {
            $parts = explode(':', $lastLine, 2);
            $threat = isset($parts[1]) ? trim(str_replace('FOUND', '', $parts[1])) : 'unknown';
            return ScanResult::infected($threat, 'clamd');
        }

        throw new ScanException(sprintf('Unexpected clamd response: %s', $lastLine));
    }

    private function scanViaCli(string $filePath): ScanResult
    {
        $escapedPath = escapeshellarg($filePath);
        $command = "clamscan --no-summary --stdout {$escapedPath}";

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($exitCode === 0) {
            return ScanResult::clean('clamscan');
        }

        if ($exitCode === 1 && count($output) > 0) {
            $lastLine = trim(end($output));
            $parts = explode(':', $lastLine, 2);
            $threat = isset($parts[1]) ? trim(str_replace('FOUND', '', $parts[1])) : 'unknown';
            return ScanResult::infected($threat, 'clamscan');
        }

        throw new ScanException(sprintf('clamscan failed with exit code [%d].', $exitCode));
    }
}
