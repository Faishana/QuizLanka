<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAIAvatarService implements AvatarService
{
    protected $client;
    protected string $imageModel;

    public function __construct()
    {
        $this->client = OpenAI::client(
            config('services.openai.api_key')
        );

        $this->imageModel = config(
            'services.openai.image_model',
            'gpt-image-2.5-sunburst'
        );
    }

    public function generate(string $imagePath): string
    {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new RuntimeException(
                "Image not found or not readable: {$imagePath}"
            );
        }

        $stream = fopen($imagePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(
                'Unable to open original profile image.'
            );
        }

        try {
            $response = $this->client->images()->edit([
                'model' => $this->imageModel,
                'image' => $stream,
                'prompt' => config('avatar.prompt'),
                'size' => '1024x1024',
            ]);

        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (
            empty($response->data) ||
            empty($response->data[0]->b64_json)
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

        $disk = Storage::disk('local');

        $filename = Str::uuid() . '.png';
        $avatarPath = 'temp/avatar/' . $filename;

        $saved = $disk->put($avatarPath, $image);

        if (!$saved) {
            throw new RuntimeException(
                'Failed to save generated avatar.'
            );
        }

        return $disk->path($avatarPath);
    }
}
