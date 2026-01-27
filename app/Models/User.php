<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'vehicle_number',
        'license_number',
        'photo',
        'is_active',
        'is_online',
        'phone_verified_at',
    ];

    /** @var array */
    protected $appends = ['rating'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_online' => 'boolean',
            'last_lat' => 'double',
            'last_lng' => 'double',
        ];
    }

    /**
     * Role helpers
     */
    public function isAdmin(): bool
    {
        return ($this->role ?? null) === 'admin';
    }

    public function isDriver(): bool
    {
        return ($this->role ?? null) === 'driver';
    }

    public function isPassenger(): bool
    {
        return ($this->role ?? null) === 'passenger';
    }

    public function isDeveloper(): bool
    {
        return ($this->role ?? null) === 'developer';
    }

    /**
     * Relationships
     */
    public function driverProfile()
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class, 'driver_id');
    }

    public function fcmTokens()
    {
        return $this->hasMany(FcmToken::class);
    }

    /**
     * Accessors
     */
    public function getPhotoAttribute($value)
    {
        if (!$value) {
            return null;
        }

        // Si c'est déjà une URL complète (ex: gravatar ou legacy), on la laisse
        if (str_starts_with($value, 'http')) {
            // Optionnel: on pourrait essayer de corriger les URLs localhost ici si on est en prod
            // mais il vaut mieux stocker des chemins relatifs.
            return $value;
        }

        return asset('storage/' . $value);
    }

    public function getRatingAttribute()
    {
        if ($this->role !== 'driver') {
            return null;
        }

        return (float) ($this->ratings()->avg('stars') ?? 0.0);
    }
}

