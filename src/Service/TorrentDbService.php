<?php

namespace App\Service;

class TorrentDbService
{
    private string $dataPath;
    private ?array $categoryIndex = null;

    public function __construct(string $projectDir)
    {
        $this->dataPath = $projectDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'gautardos' . DIRECTORY_SEPARATOR . 'hash-db';
    }

    /**
     * Build and return category index from CSV files.
     * @return array [['id' => int, 'name' => string, 'file' => string], ...]
     */
    public function getCategories(): array
    {
        if ($this->categoryIndex !== null) {
            return $this->categoryIndex;
        }

        $this->categoryIndex = [];

        if (!is_dir($this->dataPath)) {
            return $this->categoryIndex;
        }

        $files = glob($this->dataPath . DIRECTORY_SEPARATOR . 'category_*.csv');

        foreach ($files as $file) {
            $basename = basename($file);

            // Extract ID from filename: category_2184_*.csv -> 2184
            if (preg_match('/category_(\d+)_.*\.csv/', $basename, $matches)) {
                $categoryId = (int) $matches[1];

                // Read first data line to get category name
                $handle = fopen($file, 'r');
                if ($handle) {
                    // Skip header
                    fgets($handle);
                    // Read first data line
                    $line = fgets($handle);
                    fclose($handle);

                    if ($line) {
                        $parts = str_getcsv($line, ';');
                        $categoryName = $parts[1] ?? 'Unknown';

                        $this->categoryIndex[] = [
                            'id' => $categoryId,
                            'name' => $categoryName,
                            'file' => $basename
                        ];
                    }
                }
            }
        }

        // Sort by name
        usort($this->categoryIndex, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->categoryIndex;
    }

    /**
     * Search torrents in a specific category.
     */
    public function search(int $categoryId, string $query, int $limit = 50): array
    {
        $results = [];
        $files = glob($this->dataPath . DIRECTORY_SEPARATOR . 'category_' . $categoryId . '_*.csv');

        // 1. Search in standard category files
        if (!empty($files)) {
            $file = $files[0];

            // Normalize query for permissive search
            $normalizedQuery = $this->normalize($query);
            $queryParts = preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);

            if (!empty($queryParts)) {
                $handle = fopen($file, 'r');
                if ($handle) {
                    // Skip header
                    fgets($handle);

                    while (($line = fgets($handle)) !== false && count($results) < $limit) {
                        $parts = str_getcsv($line, ';');

                        if (count($parts) < 5) {
                            continue;
                        }

                        $title = $parts[3] ?? '';
                        $hash = $parts[4] ?? '';

                        if ($this->matchesQuery($title, $queryParts)) {
                            $results[] = [
                                'title' => trim($title, '"'),
                                'hash' => trim($hash)
                            ];
                        }
                    }
                    fclose($handle);
                }
            }
        }

        // 2. Search in tos_all_hash.txt (custom format: Title###Hash###Size###Type)
        $tosFile = $this->dataPath . DIRECTORY_SEPARATOR . 'tos_all_hash.txt';
        if (file_exists($tosFile) && count($results) < $limit) {
            $typeMatch = null;
            if (in_array($categoryId, [2178, 2183])) {
                $typeMatch = 'movie';
            } elseif (in_array($categoryId, [2179, 2184])) {
                $typeMatch = 'tv';
            }

            if ($typeMatch) {
                // Reuse query parts if already built, otherwise build them
                if (!isset($queryParts)) {
                    $normalizedQuery = $this->normalize($query);
                    $queryParts = preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);
                }

                if (!empty($queryParts)) {
                    $handle = fopen($tosFile, 'r');
                    if ($handle) {
                        while (($line = fgets($handle)) !== false && count($results) < $limit) {
                            $parts = explode('###', trim($line));
                            if (count($parts) < 4)
                                continue;

                            $title = $parts[0];
                            $hash = $parts[1];
                            $type = strtolower($parts[3]);

                            if ($type === $typeMatch && $this->matchesQuery($title, $queryParts)) {
                                $results[] = [
                                    'title' => $title,
                                    'hash' => $hash
                                ];
                            }
                        }
                        fclose($handle);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Check if a title matches all query parts.
     */
    private function matchesQuery(string $title, array $queryParts): bool
    {
        $normalizedTitle = $this->normalize($title);
        foreach ($queryParts as $qp) {
            if (stripos($normalizedTitle, $qp) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalize string for permissive matching.
     */
    private function normalize(string $str): string
    {
        // Remove accents
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        // Lowercase
        $str = strtolower($str);
        // Replace common separators with space
        $str = preg_replace('/[._\-]+/', ' ', $str);

        return $str;
    }
}
