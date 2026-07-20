<?php

namespace App\Services;

class PastPaperMcqExtractorService
{
    public function extract(string $text): array
    {

        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $questions = [];

        \Log::info('Regex Extract Result',[
            'count' => count($questions)
        ]);

       $pattern = '/
        (\d+)[\.\)]\s*
        (.*?)
        \(\s*1\s*\)\s*(.*?)
        \(\s*2\s*\)\s*(.*?)
        \(\s*3\s*\)\s*(.*?)
        \(\s*4\s*\)\s*(.*?)
        (?=\s*\d+[\.\)]|\s*$)
        /sxu';

             \Log::info('Regex Extract Result',[
            'count' => count($questions)
        ]);

        preg_match_all(
            $pattern,
            $text,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {

            $questions[] = [

                'question' => trim($match[2]),

                'options' => [

                    'A' => trim($match[3]),

                    'B' => trim($match[4]),

                    'C' => trim($match[5]),

                    'D' => trim($match[6]),
                ],

                'correct_answer' => null
            ];
        }

        return $questions;
    }
}
