<?php

namespace App\Service;

class TorrentDbService
{
    private string $dataPath;
    private ?array $categoryIndex = null;
    private ?\PDO $pdo = null;

    public function __construct(string $projectDir)
    {
        $this->dataPath = $projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'torrents.db';
    }

    public function isDbInitialized(): bool
    {
        return file_exists($this->dataPath) && filesize($this->dataPath) > 0;
    }

    private function getPdo(): ?\PDO
    {
        if ($this->pdo === null && $this->isDbInitialized()) {
            $this->pdo = new \PDO('sqlite:' . $this->dataPath);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $this->pdo;
    }

    /**
     * Build and return category index from SQLite.
     * @param bool $allowXxx
     * @return array
     */
    public function getCategories(bool $allowXxx = false): array
    {
        if (!$this->isDbInitialized()) {
            return [];
        }

        $categories = [];

        try {
            $pdo = $this->getPdo();
            if ($pdo) {
                // We fetch category_slug directly as it contains the "type/subtype" format
                $stmt = $pdo->query('SELECT DISTINCT parent_category, category, category_slug FROM torrents WHERE category_slug IS NOT NULL AND category_slug != ""');
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $slug = $row['category_slug'];

                    // Filter out XXX categories if not allowed
                    if (!$allowXxx && str_starts_with(strtolower($slug), 'xxx/')) {
                        continue;
                    }

                    // Format label: type/subtype -> Type > Subtype
                    $label = $slug;
                    if (str_contains($slug, '/')) {
                        $parts = explode('/', $slug);
                        $formattedParts = array_map(fn($p) => mb_convert_case($p, MB_CASE_TITLE, "UTF-8"), $parts);
                        $label = implode(' > ', $formattedParts);
                    } else {
                        $label = mb_convert_case($slug, MB_CASE_TITLE, "UTF-8");
                    }

                    $categories[] = [
                        'parent_category' => $row['parent_category'],
                        'category' => $row['category'],
                        'category_slug' => $label
                    ];
                }
            }
        } catch (\Exception $e) {
            // Log error or ignore
        }

        // Sort by slug (label) in descending order (Z -> A)
        // This ensures "Video" comes before "Audio" and "3D"
        usort($categories, fn($a, $b) => strcasecmp($b['category_slug'] ?? '', $a['category_slug'] ?? ''));

        return $categories;
    }

    /**
     * Search torrents in a specific category.
     */
    public function search(string $categoryId, string $query, int $limit = 50): array
    {
        $results = [];

        if (!$this->isDbInitialized()) {
            return $results;
        }

        $pdo = $this->getPdo();
        if (!$pdo) {
            return $results;
        }

        // $categoryId is expected to be "parent_category-category"
        $parts = explode('-', $categoryId);
        $parentCategory = $parts[0] ?? '';
        $category = $parts[1] ?? '';

        // Normalize query to use % instead of spaces
        $normalizedQuery = $this->normalize($query);
        $queryParts = preg_split('/\s+/', trim($normalizedQuery), -1, PREG_SPLIT_NO_EMPTY);
        $likeQuery = '%' . implode('%', $queryParts) . '%';

        try {
            $sql = 'SELECT name as title, hash_info as hash, size, CASE WHEN length(description) > 0 THEN 1 ELSE 0 END as has_description FROM torrents 
                    WHERE name LIKE :query 
                      AND parent_category = :parent_category 
                      AND (category = :category OR category IS NULL OR category = "")
                    LIMIT :limit';

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':query', $likeQuery);
            $stmt->bindValue(':parent_category', $parentCategory);
            $stmt->bindValue(':category', $category);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $results[] = [
                    'title' => $row['title'],
                    'hash' => $row['hash'],
                    'size' => $row['size'],
                    'has_description' => (bool) $row['has_description']
                ];
            }
        } catch (\Exception $e) {
            // Ignore or log error
        }

        return $results;
    }

    public function getDescription(string $hash): ?string
    {
        if (!$this->isDbInitialized()) {
            return null;
        }

        $pdo = $this->getPdo();
        if (!$pdo) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT description FROM torrents WHERE hash_info = :hash LIMIT 1');
            $stmt->execute([':hash' => $hash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && !empty($row['description'])) {
                $uncompressed = @gzuncompress($row['description']);
                if ($uncompressed !== false) {
                    // Try to ensure it is valid UTF-8
                    return mb_convert_encoding($uncompressed, 'UTF-8', 'UTF-8');
                }
            }
        } catch (\Exception $e) {
            // Ignore error
        }

        return null;
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
