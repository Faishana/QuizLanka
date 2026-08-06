<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AvatarService;
use App\Services\R2UploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GenerateProfileAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $userId,
        public string $tempPath
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AvatarService $avatarService,
        R2UploadService $r2UploadService
    ): void {

        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        if (!Storage::disk('local')->exists($this->tempPath)) {
            Log::warning('Temporary profile image not found.', [
                'user_id' => $this->userId,
                'path' => $this->tempPath,
            ]);

            return;
        }

        $avatarPath = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | Local Temp Image
            |--------------------------------------------------------------------------
            */

            $localPath = Storage::disk('local')->path($this->tempPath);

            /*
            |--------------------------------------------------------------------------
            | Generate AI Avatar
            |--------------------------------------------------------------------------
            */

            $avatarPath = $avatarService->generate($localPath);

            if (!$avatarPath || !file_exists($avatarPath)) {
                throw new RuntimeException('Avatar generation failed.');
            }

            /*
            |--------------------------------------------------------------------------
            | Remove Previous Avatar
            |--------------------------------------------------------------------------
            */

            if (!empty($user->profile_image)) {
                $r2UploadService->deleteByUrl($user->profile_image);
            }

            /*
            |--------------------------------------------------------------------------
            | Upload Avatar to Cloudflare R2
            |--------------------------------------------------------------------------
            */

            $avatarUrl = $r2UploadService->upload(
                $avatarPath,
                'avatars'
            );

            if (empty($avatarUrl)) {
                throw new RuntimeException('Avatar upload failed.');
            }

            /*
            |--------------------------------------------------------------------------
            | Save User
            |--------------------------------------------------------------------------
            */

            $user->update([
                'profile_image' => $avatarUrl,
            ]);

            Log::info('Profile avatar generated successfully.', [
                'user_id' => $user->id,
                'profile_image' => $avatarUrl,
            ]);

        } catch (\Throwable $e) {

            Log::error('Profile avatar generation failed.', [
                'user_id' => $this->userId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;

        } finally {

            /*
            |--------------------------------------------------------------------------
            | Cleanup
            |--------------------------------------------------------------------------
            */

            Storage::disk('local')->delete($this->tempPath);

            if ($avatarPath && file_exists($avatarPath)) {
                @unlink($avatarPath);
            }
        }
    }
}
