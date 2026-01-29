<?php

namespace App\Service;

use GuzzleHttp\Client;

class GrokService
{
    private const API_URL = 'https://api.x.ai/v1/chat/completions';
    private Client $client;
    private JsonStorage $storage;

    public function __construct(JsonStorage $storage)
    {
        $this->client = new Client();
        $this->storage = $storage;
    }

    private function getApiKey(): ?string
    {
        $config = $this->storage->get('config');
        return $config['grok_api_key'] ?? null;
    }

    /**
     * Suggests a standardized filename for media files.
     */
    public function suggestFilename(string $packName, string $fileName): ?string
    {
        $suggestions = $this->suggestFilenames($packName, [$fileName]);
        return $suggestions[$fileName] ?? null;
    }

    /**
     * Suggests standardized filenames for multiple files in a pack.
     */
    public function suggestFilenames(string $packName, array $fileNames): array
    {
        $apiKey = $this->getApiKey();
        if (!$apiKey || empty($fileNames)) {
            return [];
        }

        try {
            $prompt = "CONTEXT (Pack/Folder Name): \"$packName\"\n\n";
            $prompt .= "FILES TO RENAMING:\n";
            foreach ($fileNames as $name) {
                $prompt .= "- \"$name\"\n";
            }
            $prompt .= "\nINSTRUCTIONS:\n";
            $prompt .= "1. Use the CONTEXT to identify the Series/Movie title and Season/Year.\n";
            $prompt .= "2. For series, use format: \"Showname.SxxExx.OptionalTitle.ext\".\n";
            $prompt .= "3. For movies, use format: \"Movie Name (Year).ext\".\n";
            $prompt .= "4. Identify episode numbers from the individual filenames (e.g., \"01.mkv\" is Episode 1).\n";
            $prompt .= "5. Return a JSON object where keys are ORIGINAL filenames and values are the NEW suggested filenames.\n";
            $prompt .= "6. Maintain the exact original file extension.";

            $config = $this->storage->get('config', []);
            $model = $config['grok_model'] ?? 'grok-4-fast-non-reasoning';

            $response = $this->client->request('POST', 'https://api.x.ai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a professional media library organizer. Your goal is to rename files for perfect compatibility with Scrapers like Plex, Kodi, TMDB, and TVDB. You output ONLY valid JSON.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object']
                ]
            ]);

            $content = $response->getBody()->getContents();
            $data = json_decode($content, true);

            // The AI returns its content inside 'message' -> 'content'
            $jsonContent = $data['choices'][0]['message']['content'] ?? '{}';

            // Sometimes the AI might still wrap in markdown code blocks despite instructions
            if (preg_match('/^```json\s*(.*?)\s*```$/s', trim($jsonContent), $matches)) {
                $jsonContent = $matches[1];
            }

            return json_decode($jsonContent, true) ?: [];

        } catch (\Exception $e) {
            return [];
        }
    }
}
