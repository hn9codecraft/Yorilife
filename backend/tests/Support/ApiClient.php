<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal HTTP client for feature tests: keeps the session cookie like a
 * browser would and remembers the CSRF token from login / me responses.
 */
final class ApiClient
{
    /** @var array<string, string> cookie name => value */
    public array $cookies = [];

    public ?string $csrfToken = null;

    /**
     * @param array<string, mixed>|string|null $body array = JSON, string = raw body
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>|null, raw: string}
     */
    public function request(string $method, string $path, array|string|null $body = null, array $headers = [], bool $withCsrf = true): array
    {
        $curl = curl_init(TestServer::BASE_URL . $path);
        $sendHeaders = ['Accept: application/json'];

        if (is_array($body)) {
            $sendHeaders[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        } elseif (is_string($body)) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        if ($withCsrf && $this->csrfToken !== null && $method !== 'GET') {
            $sendHeaders[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }
        foreach ($headers as $name => $value) {
            $sendHeaders[] = "{$name}: {$value}";
        }
        if ($this->cookies !== []) {
            $sendHeaders[] = 'Cookie: ' . implode('; ', array_map(
                static fn (string $name, string $value): string => "{$name}={$value}",
                array_keys($this->cookies),
                $this->cookies,
            ));
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $sendHeaders,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = array_map('trim', explode(':', $line, 2));
                    if (strcasecmp($name, 'Set-Cookie') === 0) {
                        $this->storeCookie($value);
                    }
                    $responseHeaders[strtolower($name)] = $value;
                }

                return strlen($line);
            },
        ]);

        $raw = (string) curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['data']['csrfToken'])) {
            $this->csrfToken = $decoded['data']['csrfToken'];
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $raw];
    }

    public function login(string $email, string $password): array
    {
        return $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function sessionId(): ?string
    {
        return $this->cookies['yorilife_session'] ?? null;
    }

    private function storeCookie(string $header): void
    {
        [$pair] = explode(';', $header, 2);
        [$name, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
        $expired = preg_match('/expires=([^;]+)/i', $header, $m) === 1 && strtotime($m[1]) < time();

        if ($value === '' || $value === 'deleted' || $expired) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}
