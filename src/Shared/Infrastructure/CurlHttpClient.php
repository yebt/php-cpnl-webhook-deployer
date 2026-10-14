<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

use CurlHandle;
use Deployer\Shared\Application\Port\HttpClient;
use Deployer\Shared\Application\Port\HttpResponse;
use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    public function postJson(string $url, array $body, int $timeoutSeconds): HttpResponse
    {
        $handle = $this->handle($url, $timeoutSeconds);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($handle);
        if (!is_string($response)) {
            throw new RuntimeException(sprintf('HTTP request failed: %s', curl_error($handle)));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response);
    }

    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
    {
        $handle = $this->handle($url, $timeoutSeconds);
        $body = '';
        $tooLarge = false;
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => array_map(static fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $h, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            // Stream into memory with a hard cap instead of trusting Content-Length.
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $h, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        if ($tooLarge) {
            throw new RuntimeException(sprintf('Response exceeds %d bytes.', $maxBytes));
        }
        if ($ok === false) {
            throw new RuntimeException(sprintf('HTTP request failed: %s', curl_error($handle)));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body, $responseHeaders);
    }

    private function handle(string $url, int $timeoutSeconds): CurlHandle
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required for outbound HTTP.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialise curl.');
        }
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        return $handle;
    }
}
