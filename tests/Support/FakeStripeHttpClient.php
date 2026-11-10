<?php

declare(strict_types=1);

namespace CraftCms\CommerceStripe\Tests\Support;

use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for the Stripe SDK’s HTTP client, so tests can check the requests the SDK sends and fake Stripe’s
 * responses.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, path: string, url: string, headers: array<string, string>, params: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @var array<string, list<array{body: array<string, mixed>|callable, status: int}>>
     */
    private array $responses = [];

    /**
     * @var array<string, array{body: array<string, mixed>|callable, status: int}>
     */
    private array $defaults = [];

    /**
     * Sets the response for a request that has no queued responses.
     *
     * @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $body
     */
    public function default(string $method, string $path, array|callable $body, int $status = 200): static
    {
        $this->defaults[strtoupper($method) . ' ' . $path] = ['body' => $body, 'status' => $status];

        return $this;
    }

    /**
     * Queues a response for a request. Responses for the same request are returned in order, and the last one is
     * repeated.
     *
     * @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $body
     */
    public function respond(string $method, string $path, array|callable $body, int $status = 200): static
    {
        $this->responses[strtoupper($method) . ' ' . $path][] = ['body' => $body, 'status' => $status];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $path = (string)parse_url($absUrl, PHP_URL_PATH);
        $key = strtoupper($method) . ' ' . $path;

        $parsedHeaders = [];
        foreach ($headers as $header) {
            [$name, $value] = array_map('trim', explode(':', $header, 2)) + [1 => ''];
            $parsedHeaders[strtolower($name)] = $value;
        }

        $this->requests[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'url' => $absUrl,
            'headers' => $parsedHeaders,
            'params' => $params,
        ];

        if (!empty($this->responses[$key])) {
            $response = count($this->responses[$key]) > 1 ? array_shift($this->responses[$key]) : $this->responses[$key][0];
        } elseif (isset($this->defaults[$key])) {
            $response = $this->defaults[$key];
        } else {
            throw new RuntimeException("No fake Stripe response for $key");
        }

        $body = is_callable($response['body']) ? ($response['body'])($params) : $response['body'];

        return [json_encode($body), $response['status'], ['request-id' => 'req_test']];
    }

    /**
     * Returns the requests sent for a method and path.
     *
     * @return list<array{method: string, path: string, url: string, headers: array<string, string>, params: array<string, mixed>}>
     */
    public function sent(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            fn(array $request) => $request['method'] === strtoupper($method) && $request['path'] === $path,
        ));
    }

    /**
     * Returns the only request sent for a method and path.
     *
     * @return array{method: string, path: string, url: string, headers: array<string, string>, params: array<string, mixed>}
     */
    public function sentOnce(string $method, string $path): array
    {
        $requests = $this->sent($method, $path);

        if (count($requests) !== 1) {
            throw new RuntimeException(sprintf('Expected one %s %s request, got %d.', $method, $path, count($requests)));
        }

        return $requests[0];
    }
}
