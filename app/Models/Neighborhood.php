<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    protected $fillable = [
        'name',
        'arrondissement',
        'city',
        'country',
        'lat',
        'lng',
        'aliases',
        'is_active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Search neighborhoods by name or alias.
     */
    public static function search(string $query, int $limit = 10)
    {
        $query = trim($query);

        return self::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                    ->orWhere('aliases', 'LIKE', "%{$query}%")
                    ->orWhere('arrondissement', 'LIKE', "%{$query}%");
            })
            ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$query}%"])
            ->limit($limit)
            ->get();
    }
}
