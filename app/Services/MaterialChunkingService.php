<?php

namespace App\Services;

use App\Models\Material;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class MaterialChunkingService
{
    /**
     * Default chunk size for AI generation (800-1000 words recommended)
     * Balances between API call efficiency and context preservation
     */
    protected int $chunkSize = 1000;

    /**
     * Overlap between chunks for context preservation
     * Higher overlap maintains better continuity between chunks
     */
    protected int $overlap = 100;

    /**
     * Maximum characters to search for natural break points
     */
    protected int $searchRange = 300;

    /**
     * Minimum chunk size to save (in characters)
     */
    protected int $minChunkSize = 200;

    /**
     * Create chunks from material text
     */
    public function createChunks(Material $material): int
    {
        // Delete existing chunks
        $material->chunks()->delete();

        $text = $material->extracted_text;

        if (empty($text)) {
            Log::warning('No text to chunk', ['material_id' => $material->id]);
            return 0;
        }

        $chunks = $this->splitIntoChunks($text);
        $count = 0;

        Log::info('Chunking text', [
            'material_id' => $material->id,
            'total_words' => count(
                preg_split('/\s+/u', trim($text))
            ),
            'total_chars' => mb_strlen($text),
            'chunk_size' => $this->chunkSize,
            'overlap' => $this->overlap,
            'expected_chunks' => count($chunks)
        ]);

        foreach ($chunks as $index => $chunkText) {
            $trimmedChunk = trim($chunkText);

            // Skip tiny chunks that don't contain meaningful content
            if (mb_strlen($trimmedChunk) < $this->minChunkSize) {
                Log::debug('Skipping tiny chunk', [
                    'material_id' => $material->id,
                    'chunk_index' => $index + 1,
                    'characters' => mb_strlen($trimmedChunk)
                ]);
                continue;
            }

            $material->chunks()->create([
                'chunk_order' => $index + 1,
                'content' => $trimmedChunk,
                'word_count' => count(
                    preg_split('/\s+/u', $trimmedChunk)
                ),
            ]);

            $count++;
        }

        // Update material with chunk statistics (if column exists)
        if ($count > 0 && $this->hasTotalChunksColumn($material)) {
            $material->update([
                'total_chunks' => $count
            ]);
        }

        Log::info('Chunks created', [
            'material_id' => $material->id,
            'chunks_created' => $count
        ]);

        return $count;
    }

    /**
     * Split text into intelligent chunks with overlap
     */
    protected function splitIntoChunks(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $position = 0;

        // Convert chunk size from words to approximate characters
        // Average word length in Sinhala is ~6-8 characters + 1 space
        $charChunkSize = $this->chunkSize * 8;

        while ($position < $length) {
            $end = min($position + $charChunkSize, $length);

            // Try to find a natural break (sentence or paragraph)
            if ($end < $length) {
                $break = $this->findNaturalBreak($text, $end, $position);
                if ($break !== null) {
                    $end = $break;
                }
            }

            $chunk = mb_substr($text, $position, $end - $position);
            $chunks[] = trim($chunk);

            $nextPosition = $end - ($this->overlap * 8);

            if ($nextPosition <= $position) {
                break;
            }

            $position = $nextPosition;

            if ($end >= $length) {
                break;
            }
        }

        return array_filter($chunks, function($chunk) {
            return mb_strlen($chunk) >= $this->minChunkSize;
        });
    }

    /**
     * Find a natural break point (paragraph or sentence)
     */
    protected function findNaturalBreak(string $text, int $start, int $min): ?int
    {
        // Try to find paragraph break (two newlines)
        $break = strpos($text, "\n\n", $start);
        if ($break !== false && $break - $start <= $this->searchRange && $break > $min) {
            return $break + 2;
        }

        // Try to find section break (colon)
        $colonBreak = strpos($text, ":", $start);
        if ($colonBreak !== false && $colonBreak - $start <= $this->searchRange && $colonBreak > $min) {
            // Only break on colon if followed by space or newline
            $nextChar = mb_substr($text, $colonBreak + 1, 1);
            if ($nextChar === ' ' || $nextChar === "\n") {
                return $colonBreak + 1;
            }
        }

        // Try to find semicolon break
        $semicolonBreak = strpos($text, ";", $start);
        if ($semicolonBreak !== false && $semicolonBreak - $start <= $this->searchRange && $semicolonBreak > $min) {
            $nextChar = mb_substr($text, $semicolonBreak + 1, 1);
            if ($nextChar === ' ' || $nextChar === "\n") {
                return $semicolonBreak + 1;
            }
        }

        // Try to find sentence end with period
        $sentenceEnd = strpos($text, ". ", $start);
        if ($sentenceEnd !== false && $sentenceEnd - $start <= $this->searchRange && $sentenceEnd > $min) {
            return $sentenceEnd + 2;
        }

        // Try to find sentence end with question mark
        $questionEnd = strpos($text, "? ", $start);
        if ($questionEnd !== false && $questionEnd - $start <= $this->searchRange && $questionEnd > $min) {
            return $questionEnd + 2;
        }

        // Try to find sentence end with exclamation
        $exclamationEnd = strpos($text, "! ", $start);
        if ($exclamationEnd !== false && $exclamationEnd - $start <= $this->searchRange && $exclamationEnd > $min) {
            return $exclamationEnd + 2;
        }

        // Try to find line break
        $lineBreak = strpos($text, "\n", $start);
        if ($lineBreak !== false && $lineBreak - $start <= $this->searchRange && $lineBreak > $min) {
            return $lineBreak + 1;
        }

        return null;
    }

    /**
     * Get chunk statistics
     */
    public function getChunkStats(Material $material): array
    {
        $chunks = $material->chunks;
        $wordCounts = $chunks->pluck('word_count')->toArray();

        return [
            'total_chunks' => $chunks->count(),
            'min_words' => min($wordCounts ?? [0]),
            'max_words' => max($wordCounts ?? [0]),
            'avg_words' => count($wordCounts) > 0 ? array_sum($wordCounts) / count($wordCounts) : 0,
            'total_words' => array_sum($wordCounts),
        ];
    }

    /**
     * Get the material's chunks as a collection
     */
    public function getChunks(Material $material): Collection
    {
        return $material->chunks()
            ->orderBy('chunk_order')
            ->get();
    }

    /**
     * Check if material has total_chunks column
     */
    protected function hasTotalChunksColumn(Material $material): bool
    {
        return in_array('total_chunks', $material->getFillable());
    }
}
