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
    public function suggestFilename(string $packName, string $fileName): mixed
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
            $config = $this->storage->get('config', []);
            $instructions = $config['grok_renaming_prompt'] ?? "";

            if (empty($instructions)) {
                $instructions = "1. Use the CONTEXT to identify the Category (Movie, Series, Music, Ebook), Title, and metadata (Season, Year, Artist, Album).\n";
                $instructions .= "2. Standardize filenames based on category:\n";
                $instructions .= "   - Series: \"Showname.SxxExx.OptionalTitle.ext\".\n";
                $instructions .= "   - Anime: \"Showname.SxxExx.OptionalTitle.ext\".\n";
                $instructions .= "   - Movies: \"Movie Name (Year).ext\".\n";
                $instructions .= "   - Music: \"XX - Song Name.ext\" (XX = track number, identify from filename).\n";
                $instructions .= "   - Ebooks: \"Clean Book Title.ext\".\n";
                $instructions .= "3. Identify episode/track numbers from individual filenames (e.g., \"01.mkv\" is Episode 1).\n";
                $instructions .= "4. Rules for \"path\":\n";
                $instructions .= "   - Series: \"Video/TV Shows/Series Name/Season XX/\" (e.g. \"Video/TV Shows/The Sopranos/Season 02/\").\n";
                $instructions .= "   - Anime: \"Video/Anime/Series Name/Season XX/\" (e.g. \"Video/Anime/Naruto/Season 01/\").\n";
                $instructions .= "   - Movies: \"Video/Movies/Movie Name (Year)/\" (e.g. \"Video/Movies/Inception (2010)/\").\n";
                $instructions .= "   - Music: \"Music/Artist Name/Album Name/\" (e.g. \"Music/Pink Floyd/The Wall/\").\n";
                $instructions .= "   - Ebooks: \"Ebooks/Series Name/\" or \"Ebooks/Author Name/\" (e.g. \"Ebooks/Harry Potter/\"). use a T in front of the volume number when required. Only keep the name of the book (and the series if there is one) in the filename.\n";
                $instructions .= "5. Return a JSON object where keys are ORIGINAL filenames and values are another JSON object containing \"filename\" and \"path\".\n";
                $instructions .= "6. For ebooks NEVER change the language of the book title.\n";
                $instructions .= "7. Maintain the exact original file extension.";
            }

            $prompt .= "\nINSTRUCTIONS:\n" . $instructions;

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
