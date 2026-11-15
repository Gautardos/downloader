<?php

namespace App\Service;

class LogRedactor
{
    public function redact(string $text): string
    {
        $patterns = [
            '/(--(?:client[-_]?secret|password|passwd|token|api[-_]?key|access[-_]?token|refresh[-_]?token)\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1"***"',
            '/(--(?:client[-_]?secret|password|passwd|token|api[-_]?key|access[-_]?token|refresh[-_]?token)=)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1"***"',
            '/((?:api[_-]?key|apikey|token|password|passwd|client[_-]?secret|access[_-]?token|refresh[_-]?token)\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&]+)/i' => '$1***',
            '/(Authorization:\s*Bearer\s+)\S+/i' => '$1***',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }
}
