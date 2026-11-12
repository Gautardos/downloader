<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AiService
{
    private const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    private const OPENROUTER_MODELS_ENDPOINT = 'https://openrouter.ai/api/v1/models';
    private const XAI_ENDPOINT = 'https://api.x.ai/v1/chat/completions';
    private const OPENROUTER_REFERER = 'https://github.com/Gautardos/downloader';
    private const OPENROUTER_TITLE = 'Downloader';

    private JsonStorage $storage;
    private CacheInterface $cache;
    private Client $client;

    public function __construct(JsonStorage $storage, CacheInterface $cache)
    {
        $this->storage = $storage;
        $this->cache = $cache;
        $this->client = new Client();
    }

    public function hasAi(string $usage = 'renaming'): bool
    {
        $runtime = $this->getRuntimeConfig($usage);

        return !empty($runtime['api_key']) && !empty($runtime['model']);
    }

    public function getRuntimeConfig(string $usage = 'renaming'): array
    {
        $config = $this->storage->get('config', []);
        $provider = $this->resolveProvider($config);

        if ($provider === 'xai') {
            $model = $this->resolveXaiModel($config, $usage);

            return [
                'provider' => 'xai',
                'endpoint' => self::XAI_ENDPOINT,
                'api_key' => trim((string) ($config['grok_api_key'] ?? '')),
                'model' => $model,
                'referer' => '',
                'title' => '',
            ];
        }

        return [
            'provider' => 'openrouter',
            'endpoint' => self::OPENROUTER_ENDPOINT,
            'api_key' => trim((string) ($config['openrouter_api_key'] ?? '')),
            'model' => $this->resolveOpenRouterModel($config, $usage),
            'referer' => self::OPENROUTER_REFERER,
            'title' => self::OPENROUTER_TITLE,
        ];
    }

    public function chat(string $usage, array $messages, array $options = []): array
    {
        $runtime = $this->getRuntimeConfig($usage);

        if (empty($runtime['api_key'])) {
            return $this->error($runtime['provider'], $runtime['model'], 'No API key configured for ' . $runtime['provider'] . '.');
        }
        if (empty($runtime['model'])) {
            return $this->error($runtime['provider'], null, 'No AI model configured for this usage.');
        }

        return $this->doChat(
            $runtime['provider'],
            $runtime['api_key'],
            $runtime['model'],
            $messages,
            $options,
            $runtime['referer'],
            $runtime['title']
        );
    }

    public function chatJson(string $usage, array $messages, array $options = []): array
    {
        $options['json'] = true;
        $response = $this->chat($usage, $messages, $options);

        if (!$response['success']) {
            return [];
        }

        return $this->decodeJsonContent((string) $response['content']);
    }

    public function listOpenRouterModels(?string $apiKey = null): array
    {
        $config = $this->storage->get('config', []);
        $key = $apiKey !== null ? trim($apiKey) : trim((string) ($config['openrouter_api_key'] ?? ''));

        return $this->cache->get('openrouter_models_v2', function (ItemInterface $item) use ($key) {
            $item->expiresAfter(3600);

            try {
                $headers = [
                    'Accept' => 'application/json',
                    'HTTP-Referer' => self::OPENROUTER_REFERER,
                    'X-Title' => self::OPENROUTER_TITLE,
                ];
                if ($key !== '') {
                    $headers['Authorization'] = 'Bearer ' . $key;
                }

                $response = $this->client->request('GET', self::OPENROUTER_MODELS_ENDPOINT, [
                    'headers' => $headers,
                    'query' => [
                        'limit' => 1000,
                        'output_modalities' => 'text',
                    ],
                    'timeout' => 25,
                    'connect_timeout' => 10,
                ]);

                $data = json_decode((string) $response->getBody(), true);
                $models = $this->normalizeModels($data['data'] ?? []);

                return [
                    'success' => true,
                    'models' => $models,
                    'error' => null,
                    'fetched_at' => time(),
                ];
            } catch (\Throwable $e) {
                $item->expiresAfter(60);

                return [
                    'success' => false,
                    'models' => [],
                    'error' => $this->extractError($e),
                    'fetched_at' => time(),
                ];
            }
        });
    }

    public function testUsage(string $usage, array $overrides = []): array
    {
        $runtime = $this->getRuntimeConfig($usage);

        $provider = strtolower((string) ($overrides['provider'] ?? $runtime['provider']));
        if (!in_array($provider, ['openrouter', 'xai'], true)) {
            $provider = $runtime['provider'];
        }

        $apiKey = array_key_exists('api_key', $overrides)
            ? trim((string) $overrides['api_key'])
            : $runtime['api_key'];

        $model = array_key_exists('model', $overrides)
            ? trim((string) $overrides['model'])
            : $runtime['model'];

        if ($apiKey === '') {
            return [
                'success' => false,
                'provider' => $provider,
                'model' => $model,
                'error' => 'No API key configured for ' . $provider . '.',
            ];
        }
        if ($model === '') {
            return [
                'success' => false,
                'provider' => $provider,
                'model' => null,
                'error' => 'No AI model configured for this usage.',
            ];
        }

        $startedAt = microtime(true);
        $response = $this->doChat(
            $provider,
            $apiKey,
            $model,
            [
                ['role' => 'system', 'content' => 'You are a connectivity test. Reply with the single word OK.'],
                ['role' => 'user', 'content' => 'ping'],
            ],
            ['temperature' => 0, 'max_tokens' => 256],
            $provider === 'openrouter' ? self::OPENROUTER_REFERER : '',
            $provider === 'openrouter' ? self::OPENROUTER_TITLE : ''
        );

        return [
            'success' => $response['success'],
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'content' => $response['content'] ?? null,
            'error' => $response['error'] ?? null,
        ];
    }

    private function resolveProvider(array $config): string
    {
        $requested = strtolower(trim((string) ($config['ai_provider'] ?? 'openrouter')));
        $openRouterKey = trim((string) ($config['openrouter_api_key'] ?? ''));
        $grokKey = trim((string) ($config['grok_api_key'] ?? ''));

        if ($requested === 'xai') {
            return 'xai';
        }

        if ($requested === 'openrouter' && $openRouterKey === '' && $grokKey !== '') {
            return 'xai';
        }

        if (!in_array($requested, ['openrouter', 'xai'], true)) {
            if ($openRouterKey !== '') {
                return 'openrouter';
            }
            if ($grokKey !== '') {
                return 'xai';
            }
        }

        return 'openrouter';
    }

    private function resolveOpenRouterModel(array $config, string $usage): string
    {
        $keys = [
            'default' => 'ai_model_default',
            'renaming' => 'ai_model_renaming',
            'music_genre' => 'ai_model_music_genre',
        ];

        $model = trim((string) ($config[$keys[$usage] ?? 'ai_model_default'] ?? ''));
        if ($model === '' && $usage !== 'default') {
            $model = trim((string) ($config['ai_model_default'] ?? ''));
        }

        return $model;
    }

    private function resolveXaiModel(array $config, string $usage): string
    {
        if ($usage === 'music_genre') {
            $model = trim((string) ($config['music_genre_grok_model'] ?? ''));
            if ($model !== '') {
                return $model;
            }
        }

        return trim((string) ($config['grok_model'] ?? ''));
    }

    private function doChat(
        string $provider,
        string $apiKey,
        string $model,
        array $messages,
        array $options = [],
        string $referer = '',
        string $title = ''
    ): array {
        $endpoint = $provider === 'xai' ? self::XAI_ENDPOINT : self::OPENROUTER_ENDPOINT;

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($provider === 'openrouter') {
            if ($referer !== '') {
                $headers['HTTP-Referer'] = $referer;
            }
            if ($title !== '') {
                $headers['X-Title'] = $title;
            }
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        if (array_key_exists('temperature', $options)) {
            $payload['temperature'] = $options['temperature'];
        }
        if (array_key_exists('max_tokens', $options)) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $jsonMode = !empty($options['json']);
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $request = function (array $body) use ($endpoint, $headers, $options) {
            return $this->client->request('POST', $endpoint, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => $options['timeout'] ?? 45,
                'connect_timeout' => 10,
            ]);
        };

        try {
            try {
                $response = $request($payload);
            } catch (RequestException $e) {
                if (!$jsonMode || !$e->getResponse() || $e->getResponse()->getStatusCode() !== 400) {
                    throw $e;
                }

                $body = (string) $e->getResponse()->getBody();
                if (stripos($body, 'response_format') === false && stripos($body, 'json mode') === false && stripos($body, 'response format') === false) {
                    throw $e;
                }

                unset($payload['response_format']);
                $response = $request($payload);
            }

            $data = json_decode((string) $response->getBody(), true);
            $content = $data['choices'][0]['message']['content'] ?? '';

            if (is_array($content)) {
                $content = implode(' ', array_map(static fn ($part) => is_string($part) ? $part : json_encode($part), $content));
            }

            $content = trim((string) $content);
            if ($content === '') {
                return $this->error($provider, $model, 'The AI provider returned an empty response.');
            }

            return [
                'success' => true,
                'provider' => $provider,
                'model' => $model,
                'content' => $content,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return $this->error($provider, $model, $this->extractError($e));
        }
    }

    private function decodeJsonContent(string $content): array
    {
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            $content = trim($matches[1]);
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeModels(array $models): array
    {
        $normalized = [];

        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id'])) {
                continue;
            }

            if (!empty($model['endpoints']) && is_array($model['endpoints']) && !in_array('/api/v1/chat/completions', $model['endpoints'], true)) {
                continue;
            }

            $supported = $model['supported_parameters'] ?? [];

            $normalized[] = [
                'id' => (string) $model['id'],
                'name' => (string) ($model['name'] ?? $model['id']),
                'context_length' => $model['context_length'] ?? null,
                'pricing' => $model['pricing'] ?? [],
                'supports_json' => is_array($supported) && in_array('response_format', $supported, true),
            ];
        }

        usort($normalized, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $normalized;
    }

    private function error(string $provider, ?string $model, string $message): array
    {
        return [
            'success' => false,
            'provider' => $provider,
            'model' => $model,
            'content' => null,
            'error' => $message,
        ];
    }

    private function extractError(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->getResponse()) {
            $body = (string) $e->getResponse()->getBody();
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['error']['message'])) {
                return (string) $data['error']['message'];
            }
            if ($body !== '') {
                return $body;
            }
        }

        return $e->getMessage();
    }
}
