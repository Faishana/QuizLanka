<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PdfExtractionService
{
    public function extract($pdfPath)
    {
        $parser = new Parser();

        $pdf = $parser->parseFile($pdfPath);

        $text = trim($pdf->getText());

        \Log::info('PDF Parser Result', [
            'chars' => mb_strlen($text)
        ]);

        return $text;
    }
}
