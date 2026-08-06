<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAIAvatarService implements AvatarService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::client(
            config('services.openai.api_key')
        );
    }

    /**
     * Generate AI avatar from a user photo.
     *
     * @param string $imagePath Absolute local image path.
     * @return string Absolute local generated avatar path.
     */
    public function generate(string $imagePath): string
    {
        if (!file_exists($imagePath)) {
            throw new RuntimeException(
                "Image not found: {$imagePath}"
            );
        }

        $stream = fopen($imagePath, 'r');

        try {

            $response = $this->client->images()->edit([
                'model'  => 'gpt-image-1',

                'image'  => $stream,

                'prompt' => config('avatar.prompt'),

                'size'   => '1024x1024',
            ]);

        } finally {

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (
            empty($response->data)
            || empty($response->data[0]->b64_json)
        ) {
            throw new RuntimeException(
                'OpenAI did not return an avatar.'
            );
        }

        $image = base64_decode(
            $response->data[0]->b64_json,
            true
        );

        if ($image === false) {
            throw new RuntimeException(
                'Failed to decode avatar image.'
            );
        }

        $directory = storage_path('app/temp/avatar');

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $avatarPath = sprintf(
            '%s/%s.png',
            $directory,
            Str::uuid()
        );

        if (file_put_contents($avatarPath, $image) === false) {
            throw new RuntimeException(
                'Failed to save generated avatar.'
            );
        }

        return $avatarPath;
    }
}
