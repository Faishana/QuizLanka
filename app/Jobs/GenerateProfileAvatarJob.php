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
use Throwable;

class GenerateProfileAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of attempts.
     */
    public int $tries = 3;

    /**
     * Maximum execution time.
     */
    public int $timeout = 300;

    /**
     * Retry delays in seconds.
     */
    public function backoff(): array
    {
        return [60, 120, 300];
    }

    public function __construct(
        public int $userId,
        public string $tempPath
    ) {}

    public function handle(
        AvatarService $avatarService,
        R2UploadService $r2UploadService
    ): void {

        $disk = Storage::disk('local');

        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $user = User::find($this->userId);

        if (!$user) {

            Log::warning('Avatar generation cancelled. User not found.', [
                'user_id' => $this->userId,
            ]);

            // No user needs this image anymore.
            $disk->delete($this->tempPath);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | DEBUG: Worker Environment Snapshot
        |--------------------------------------------------------------------------
        | This block logs the actual execution environment of the queue worker
        | so we can compare it against Tinker results.
        */

        $localPath = $disk->path($this->tempPath);

        Log::info('AVATAR WORKER ENVIRONMENT DEBUG', [
            'user_id'           => $this->userId,
            'temp_path'         => $this->tempPath,
            'absolute_path'     => $localPath,
            'storage_exists'    => $disk->exists($this->tempPath),
            'file_exists'       => file_exists($localPath),
            'is_readable'       => is_readable($localPath),
            'disk_root'         => config('filesystems.disks.local.root'),
            'base_path'         => base_path(),
            'hostname'          => gethostname(),
            'current_directory' => getcwd(),
            'php_user'          => get_current_user(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate Original Image
        |--------------------------------------------------------------------------
        */

        if (!$disk->exists($this->tempPath)) {

            Log::error('Temporary profile image not found.', [
                'user_id'    => $this->userId,
                'path'       => $this->tempPath,
                'disk_root'  => config('filesystems.disks.local.root'),
            ]);

            throw new RuntimeException(
                'Temporary profile image not found: ' . $this->tempPath
            );
        }

        // Note: $localPath already resolved above in the debug block.

        if (!is_file($localPath) || !is_readable($localPath)) {

            Log::error('Temporary profile image is not readable.', [
                'user_id' => $this->userId,
                'path'    => $localPath,
            ]);

            throw new RuntimeException(
                'Temporary profile image is not readable.'
            );
        }

        $avatarPath = null;
        $completed = false;

        try {

            /*
            |--------------------------------------------------------------------------
            | Generate AI Avatar
            |--------------------------------------------------------------------------
            */

            Log::info('Starting profile avatar generation.', [
                'user_id'        => $this->userId,
                'original_image' => $this->tempPath,
            ]);

            $avatarPath = $avatarService->generate($localPath);

            if (
                empty($avatarPath) ||
                !is_file($avatarPath) ||
                !is_readable($avatarPath)
            ) {
                throw new RuntimeException(
                    'Avatar generation failed or generated image is missing.'
                );
            }

            Log::info('AI avatar generated successfully.', [
                'user_id' => $this->userId,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Upload New Avatar to Cloudflare R2
            |--------------------------------------------------------------------------
            */

            $avatarUrl = $r2UploadService->upload(
                $avatarPath,
                'avatars'
            );

            if (empty($avatarUrl)) {
                throw new RuntimeException(
                    'Avatar upload to Cloudflare R2 failed.'
                );
            }

            Log::info('Avatar uploaded to R2 successfully.', [
                'user_id'    => $this->userId,
                'avatar_url' => $avatarUrl,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update User Profile
            |--------------------------------------------------------------------------
            */

            $oldAvatarUrl = $user->profile_image;

            $updated = $user->update([
                'profile_image' => $avatarUrl,
            ]);

            if (!$updated) {
                throw new RuntimeException(
                    'Failed to update user profile image.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Mark Processing as Completed
            |--------------------------------------------------------------------------
            */

            $completed = true;

            /*
            |--------------------------------------------------------------------------
            | Delete Previous Avatar from R2
            |--------------------------------------------------------------------------
            */

            if (
                !empty($oldAvatarUrl) &&
                $oldAvatarUrl !== $avatarUrl
            ) {
                try {

                    $r2UploadService->deleteByUrl($oldAvatarUrl);

                    Log::info('Previous profile avatar deleted.', [
                        'user_id' => $this->userId,
                    ]);

                } catch (Throwable $e) {

                    Log::warning('Previous avatar could not be deleted.', [
                        'user_id' => $this->userId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Profile avatar generated successfully.', [
                'user_id'       => $user->id,
                'profile_image' => $avatarUrl,
            ]);

        } catch (Throwable $e) {

            Log::error('Profile avatar generation failed.', [
                'user_id'   => $this->userId,
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            // Preserve the original image for the next queue attempt.
            throw $e;

        } finally {

            /*
            |--------------------------------------------------------------------------
            | Cleanup
            |--------------------------------------------------------------------------
            */

            // Delete original image only after successful processing.
            if ($completed) {

                $deleted = $disk->delete($this->tempPath);

                Log::info('Original profile image cleanup.', [
                    'user_id' => $this->userId,
                    'path'    => $this->tempPath,
                    'deleted' => $deleted,
                ]);
            }

            // Always delete locally generated avatar file.
            if ($avatarPath && is_file($avatarPath)) {
                @unlink($avatarPath);
            }
        }
    }
}