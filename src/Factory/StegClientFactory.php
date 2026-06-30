<?php

// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 public-sector-dev-crew

declare(strict_types=1);

namespace Steg\Factory;

use Steg\Client\MockClient;
use Steg\Client\OpenAiCompatibleClient;
use Steg\StegClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Factory for creating StegClient instances from DSN strings or config arrays.
 *
 * Supported DSN formats:
 *   vllm://localhost:8000/v1?model=llama-3.3-70b-awq
 *   ollama://localhost:11434?model=llama3.2
 *   litellm://localhost:4000/v1?model=gpt-4&api_key=sk-...
 *   localai://localhost:8080/v1?model=ggml-gpt4all-j
 *   mock://default
 *   mock://default?response=Hello+World
 *
 * Array config format:
 *   [
 *     'base_url' => 'http://localhost:8000/v1',
 *     'model'    => 'llama-3.3-70b-awq',
 *     'api_key'  => 'EMPTY',          // optional
 *     'timeout'  => 120,              // optional, seconds
 *   ]
 */
final class StegClientFactory
{
    /** @var array<string, string> Maps DSN schemes to base URL patterns */
    private const SCHEME_DEFAULTS = [
        'vllm' => 'http',
        'ollama' => 'http',
        'litellm' => 'http',
        'localai' => 'http',
        'llama' => 'http',
    ];

    /** @var array<string, int> Default ports per scheme */
    private const DEFAULT_PORTS = [
        'vllm' => 8000,
        'ollama' => 11434,
        'litellm' => 4000,
        'localai' => 8080,
        'llama' => 8080,
    ];

    public static function fromDsn(string $dsn, ?HttpClientInterface $httpClient = null): StegClient
    {
        $parsed = parse_url($dsn);
        if (false === $parsed || !isset($parsed['scheme'])) {
            throw new \InvalidArgumentException(\sprintf('Invalid Steg DSN: "%s"', $dsn));
        }

        $scheme = strtolower($parsed['scheme']);

        if ('mock' === $scheme) {
            $query = [];
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $query);
            }
            $response = \is_string($query['response'] ?? null) ? $query['response'] : 'Mock response.';
            $model = \is_string($query['model'] ?? null) ? $query['model'] : 'mock-model';

            return new StegClient(new MockClient(response: $response, model: $model));
        }

        $httpProtocol = self::SCHEME_DEFAULTS[$scheme] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $defaultPort = self::DEFAULT_PORTS[$scheme] ?? 8000;
        $port = $parsed['port'] ?? $defaultPort;
        $path = isset($parsed['path']) && '' !== $parsed['path'] ? $parsed['path'] : '/v1';

        $baseUrl = \sprintf('%s://%s:%d%s', $httpProtocol, $host, $port, rtrim($path, '/'));

        $query = [];
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $model = \is_string($query['model'] ?? null) ? $query['model'] : '';
        if ('' === $model) {
            throw new \InvalidArgumentException(\sprintf('DSN "%s" is missing required "model" query parameter.', $dsn));
        }

        $apiKey = \is_string($query['api_key'] ?? null) ? $query['api_key'] : 'EMPTY';
        $timeout = isset($query['timeout']) && is_numeric($query['timeout'])
            ? (int) $query['timeout']
            : 120;

        return self::buildClient($baseUrl, $model, $apiKey, $timeout, $httpClient);
    }

    /**
     * @param array<string, mixed> $config Untrusted config array; keys are validated at this boundary
     */
    public static function fromConfig(array $config, ?HttpClientInterface $httpClient = null): StegClient
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!\is_string($baseUrl) || '' === $baseUrl) {
            throw new \InvalidArgumentException('Config key "base_url" is required and must not be empty.');
        }

        $model = $config['model'] ?? null;
        if (!\is_string($model) || '' === $model) {
            throw new \InvalidArgumentException('Config key "model" is required and must not be empty.');
        }

        $apiKey = \is_string($config['api_key'] ?? null) ? $config['api_key'] : 'EMPTY';
        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? (int) $config['timeout']
            : 120;

        return self::buildClient(rtrim($baseUrl, '/'), $model, $apiKey, $timeout, $httpClient);
    }

    private static function buildClient(
        string $baseUrl,
        string $model,
        string $apiKey,
        int $timeout,
        ?HttpClientInterface $httpClient,
    ): StegClient {
        if (null === $httpClient) {
            $httpClient = self::createDefaultHttpClient();
        }

        return new StegClient(
            new OpenAiCompatibleClient(
                httpClient: $httpClient,
                baseUrl: $baseUrl,
                model: $model,
                apiKey: $apiKey,
                timeout: $timeout,
            ),
        );
    }

    private static function createDefaultHttpClient(): HttpClientInterface
    {
        if (!class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            throw new \LogicException('No HttpClientInterface provided and symfony/http-client is not installed. Either install symfony/http-client or pass your own HttpClientInterface instance.');
        }

        return \Symfony\Component\HttpClient\HttpClient::create();
    }
}
