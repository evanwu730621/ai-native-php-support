<?php

declare(strict_types=1);

namespace App\Services;

final class DocumentChunker
{
    /**
     * @return list<string>
     */
    public function chunk(
        string $text,
        int $chunkSize = 500,
        int $overlap = 50
    ): array {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }

        if ($chunkSize < 1 || $overlap < 0 || $overlap >= $chunkSize) {
            throw new \InvalidArgumentException(
                'Chunk size must be positive and overlap must be smaller than chunk size.'
            );
        }

        $chunks = [];
        $length = mb_strlen($text);

        for ($start = 0; $start < $length;) {
            $chunk = trim(mb_substr($text, $start, $chunkSize));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($start + $chunkSize >= $length) {
                break;
            }

            $start += $chunkSize - $overlap;
        }

        return $chunks;
    }
}
