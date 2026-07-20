<?php

namespace App\Services;

class AiTextCleaningService
{
    public function clean(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remove ISBN
        $text = preg_replace('/ISBN\s*[:\-]?\s*[0-9Xx\-]+/iu', '', $text);

        // Remove copyright
        $text = preg_replace('/©.*$/imu', '', $text);

        // Remove page numbers
        $text = preg_replace('/^\s*\d+\s*$/m', '', $text);

        // Remove common book metadata
        $removePatterns = [
            '/Educational Publications Department/iu',
            '/Ministry of Education/iu',
            '/First Published.*$/imu',
            '/Printed by.*$/imu',
            '/Publisher.*$/imu',
            '/Author.*$/imu',
            '/Editors?.*$/imu',
            '/Preface/iu',
            '/Acknowledgements?/iu',
            '/Table of Contents/iu',
        ];

        foreach ($removePatterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        // Remove empty lines
        $text = preg_replace("/\n{2,}/", "\n\n", $text);

        return trim($text);
    }
}
