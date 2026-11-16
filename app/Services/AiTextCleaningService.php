<?php

namespace App\Services;

class AiTextCleaningService
{
    public function clean(string $text): string
    {
        /*
        |--------------------------------------------------------------------------
        | Preserve Original Unicode
        |--------------------------------------------------------------------------
        |
        | Do NOT use iconv() or character-removal regex here.
        | Sinhala / Tamil / English must remain untouched.
        |
        */

        // Normalize line endings
        $text = preg_replace(
            '/\r\n|\r/u',
            "\n",
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove ISBN
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/ISBN\s*[:\-]?\s*[0-9Xx\-]+/iu',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove copyright lines
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^[^\n]*©[^\n]*$/imu',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove standalone page numbers
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^\s*\d+\s*$/mu',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove obvious book metadata
        |--------------------------------------------------------------------------
        |
        | Only remove complete lines.
        | This prevents accidentally deleting educational content.
        |
        */

        $removePatterns = [

            '/^[^\n]*Educational Publications Department[^\n]*$/imu',

            '/^[^\n]*Ministry of Education[^\n]*$/imu',

            '/^[^\n]*First Published[^\n]*$/imu',

            '/^[^\n]*Printed by[^\n]*$/imu',

            '/^[^\n]*Publisher[^\n]*$/imu',

            '/^[^\n]*Preface[^\n]*$/imu',

            '/^[^\n]*Acknowledgements?[^\n]*$/imu',

            '/^[^\n]*Table of Contents[^\n]*$/imu',

        ];

        foreach ($removePatterns as $pattern) {

            $text = preg_replace(
                $pattern,
                '',
                $text
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize spaces
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/[ \t]+/u',
            ' ',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize excessive empty lines
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            "/\n{3,}/u",
            "\n\n",
            $text
        );

        return trim($text);
    }
}
