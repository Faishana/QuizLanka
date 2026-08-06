<?php

namespace App\Services;

interface AvatarService
{
    /**
     * Generate an AI avatar from a user photo.
     *
     * @param string $imagePath Local image path.
     * @return string Local generated avatar path.
     */
    public function generate(string $imagePath): string;
}
