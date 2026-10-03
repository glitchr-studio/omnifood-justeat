<?php

namespace Omnifood\JustEat;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The JET Connect API (api.flytplatform.com), thin: JSON calls with the
 * brand's key in X-Flyt-Api-Key, and its three error shapes as Omnifood's -
 * {"success": false, "error_message"} (menus, availability, restaurants),
 * {"message"} (order callbacks), {"fault": {"errors": [...]}} - 401 and 403
 * as UnauthorizedException. Through the application's HTTP client when one
 * is given: the profiler sees the calls, the tests mock them.
 */
final class Api
{
    public const PLATFORM = 'justeat';
    public const BASE_URI = 'https://api.flytplatform.com';

    private readonly HttpClientInterface $http;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $baseUri = self::BASE_URI,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    /**
     * @param array<string, mixed>|object|null $json an object for "{}"
     *
     * @return array<string, mixed> the answer, [] when it has no body (204)
     */
    public function request(string $method, string $path, array|object|null $json = null): array
    {
        $options = ['headers' => ['X-Flyt-Api-Key' => $this->apiKey ?: throw new InvalidConfigException('The "justeat" platform needs: api_key.'), 'Accept' => 'application/json']];
        if (null !== $json) {
            $options['json'] = $json;
        }
        try {
            $response = $this->http->request($method, rtrim($this->baseUri, '/').'/'.ltrim($path, '/'), $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException(self::PLATFORM, 'JET Connect could not be reached: '.$e->getMessage(), null, null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        $data = \is_array($data) ? $data : [];
        if ($status >= 400 || false === ($data['success'] ?? null)) {
            throw self::error($status, $data);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function error(int $status, array $data): ProviderException
    {
        $fault = $data['fault']['errors'][0] ?? null;
        $message = (string) ($data['error_message'] ?? $data['message'] ?? $data['errorMessage'] ?? ($fault['description'] ?? \sprintf('HTTP %d.', $status)));
        $code = isset($fault['errorCode']) ? (string) $fault['errorCode'] : null;

        if (401 === $status || 403 === $status) {
            return new UnauthorizedException(self::PLATFORM, $message, $status, $code);
        }

        return new ProviderException(self::PLATFORM, $message, $status, $code);
    }
}
