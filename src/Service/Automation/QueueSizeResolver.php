<?php

namespace App\Service\Automation;

class QueueSizeResolver
{
    private AutomationStore $store;

    public function __construct(AutomationStore $store)
    {
        $this->store = $store;
    }

    public function enrich(array $items): array
    {
        $enriched = [];
        foreach ($items as $item) {
            $enriched[] = is_array($item) ? $this->enrichItem($item) : $item;
        }

        return $enriched;
    }

    public function enrichItem(array $item): array
    {
        $currentSize = isset($item['size']) && is_numeric($item['size']) ? (int) $item['size'] : 0;
        if ($currentSize > 0) {
            return $item;
        }

        $intentId = (int) ($item['automation_intent_id'] ?? 0);
        if ($intentId <= 0) {
            return $item;
        }

        $intent = $this->store->getIntent($intentId);
        if ($intent === null) {
            return $item;
        }

        $size = $this->parseSize((string) ($intent['release_explanation'] ?? ''));
        if ($size > 0) {
            $item['size'] = $size;
            $item['size_estimated'] = true;
        }

        return $item;
    }

    private function parseSize(string $text): int
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(TB|TO|GB|GO|MB|MO)\b/iu', $text, $matches)) {
            $value = (float) str_replace(',', '.', $matches[1]);
            $factor = match (strtoupper($matches[2])) {
                'TB', 'TO' => 1024 ** 4,
                'GB', 'GO' => 1024 ** 3,
                'MB', 'MO' => 1024 ** 2,
                default => 0,
            };

            return (int) round($value * $factor);
        }

        return 0;
    }
}
