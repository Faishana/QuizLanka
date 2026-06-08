<?php

namespace App\Services;

class AiTextCleaningService
{
    public function clean(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
