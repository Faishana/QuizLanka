<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PdfExtractionService
{
    public function extract($pdfPath)
    {
        $parser = new Parser();

        $pdf = $parser->parseFile($pdfPath);

        return trim($pdf->getText());
    }
}
