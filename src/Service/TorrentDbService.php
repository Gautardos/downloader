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

        if (empty($files)) {
            return $results;
        }

        $file = $files[0];

        // Normalize query for permissive search
        $normalizedQuery = $this->normalize($query);
        $queryParts = preg_split('/\s+/', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($queryParts)) {
            return $results;
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            return $results;
        }

        // Skip header
        fgets($handle);

        while (($line = fgets($handle)) !== false && count($results) < $limit) {
            $parts = str_getcsv($line, ';');

            if (count($parts) < 5) {
                continue;
            }

            $title = $parts[3] ?? '';
            $hash = $parts[4] ?? '';

            // Normalize title for matching
            $normalizedTitle = $this->normalize($title);

            // Check if all query parts match
            $allMatch = true;
            foreach ($queryParts as $qp) {
                if (stripos($normalizedTitle, $qp) === false) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                $results[] = [
                    'title' => trim($title, '"'),
                    'hash' => trim($hash)
                ];
            }
        }

        fclose($handle);

        return $results;
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
