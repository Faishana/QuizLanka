<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

use Spatie\Permission\Traits\HasRoles;

use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;

use App\Notifications\StudentPasswordResetNotification;

class User extends Authenticatable implements CanResetPasswordContract
{
    /**
     * Spatie role guard.
     *
     * All application roles are created against the "web" guard,
     * so we explicitly declare it here.
     */
    protected $guard_name = 'web';

    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;
    use CanResetPasswordTrait;

    /*
    |--------------------------------------------------------------------------
    | Canonical user categories
    |--------------------------------------------------------------------------
    */
    public const CATEGORY_STUDENT  = 'Student';
    public const CATEGORY_GOV_EXAM = 'Government Exam Candidate';
    public const CATEGORY_ADMIN    = 'Admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'user_category',
        'grade_id',
        'al_stream',
        'school',
        'district',
        'preferred_medium',
        'target_exam',
        'profile_image',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(
            Subject::class,
            'user_subjects'
        )->withTimestamps();
    }

    public function statistics()
    {
        return $this->hasOne(UserStatistic::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->user_category === self::CATEGORY_ADMIN;
    }

    public function isStudent(): bool
    {
        return $this->user_category === self::CATEGORY_STUDENT;
    }

    public function isGovernmentExamCandidate(): bool
    {
        return $this->user_category === self::CATEGORY_GOV_EXAM;
    }

    public function getDisplayAvatarAttribute(): ?string
    {
        return $this->profile_image;
    }

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(
            new StudentPasswordResetNotification($token)
        );
    }

    /**
     * Synchronize the Spatie role with the user's category.
     *
     * Note: this only syncs roles. Always save the user's
     * `user_category` before calling this method, ideally
     * inside the same database transaction.
     */
    public function syncCategoryRole(): void
    {
        // Remove previous student application category roles.
        $this->removeRole('student');
        $this->removeRole('government_exam_candidate');

        // Determine the role based on the current category.
        $role = match ($this->user_category) {
            self::CATEGORY_STUDENT => 'student',
            self::CATEGORY_GOV_EXAM => 'government_exam_candidate',
            default => null,
        };

        // Assign the matching role.
        if ($role !== null) {
            $this->assignRole($role);
        }
    }
}
