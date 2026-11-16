<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class R2UploadService
{
    /**
     * Upload a local file to Cloudflare R2.
     *
     * @param string $localPath
     * @param string $folder
     * @return string
     */
    public function upload(
        string $localPath,
        string $folder = 'avatars'
    ): string {

        if (!file_exists($localPath)) {
            throw new RuntimeException(
                "Local file not found: {$localPath}"
            );
        }

        $extension = pathinfo($localPath, PATHINFO_EXTENSION);

        $filename = sprintf(
            '%s/%s.%s',
            trim($folder, '/'),
            Str::uuid(),
            $extension
        );

        $stream = fopen($localPath, 'r');

        try {

            $uploaded = Storage::disk('s3')->put(
                $filename,
                $stream,
                'public'
            );

            if (!$uploaded) {
                throw new RuntimeException(
                    'Failed to upload file to Cloudflare R2.'
                );
            }

        } finally {

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $publicUrl = config('filesystems.disks.s3.public_url');

        if (empty($publicUrl)) {
            throw new RuntimeException(
                'R2 public URL is not configured.'
            );
        }

        return rtrim($publicUrl, '/') . '/' . $filename;
    }

    /**
     * Delete a file from Cloudflare R2 using its public URL.
     *
     * @param string|null $url
     * @return void
     */
    public function deleteByUrl(?string $url): void
    {
        if (empty($url)) {
            return;
        }

        $publicUrl = config('filesystems.disks.s3.public_url');

        if (empty($publicUrl)) {
            throw new RuntimeException(
                'R2 public URL is not configured.'
            );
        }

        $publicUrl = rtrim($publicUrl, '/');

        if (!str_starts_with($url, $publicUrl . '/')) {
            throw new RuntimeException(
                'Invalid Cloudflare R2 public URL.'
            );
        }

        $path = ltrim(
            substr($url, strlen($publicUrl)),
            '/'
        );

        if (empty($path)) {
            return;
        }

        Storage::disk('s3')->delete($path);
    }


    /**
     * Check whether a file exists in Cloudflare R2.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return Storage::disk('s3')->exists($path);
    }

    /**
     * Get the public URL of a file.
     *
     * @param string $path
     * @return string
     */
    public function url(string $path): string
    {
        return Storage::disk('s3')->url($path);
    }
}
