<?php

namespace App\Services;

use App\Models\Material;

class MaterialChunkingService
{
    public function createChunks(Material $material): void
    {
        $material->chunks()->delete();

        $words = preg_split(
            '/\s+/u',
            $material->extracted_text
        );

        $chunkSize = 5000;

        $chunks = array_chunk(
            $words,
            $chunkSize
        );

        foreach ($chunks as $index => $chunk)
        {
            $material->chunks()->create([

                'chunk_order' => $index + 1,

                'content' => implode(
                    ' ',
                    $chunk
                ),

                'word_count' => count(
                    $chunk
                ),
            ]);
        }
    }
}
