<?php

declare(strict_types=1);

namespace Deployer\Shared\Infrastructure;

use Deployer\Shared\Application\Port\HttpClient;
use Deployer\Shared\Application\Port\HttpResponse;
use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    public function postJson(string $url, array $body, int $timeoutSeconds): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required to send notifications.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialise curl.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        if (!is_string($response)) {
            throw new RuntimeException(sprintf('HTTP request failed: %s', curl_error($handle)));
        }

        return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $response);
    }
}
