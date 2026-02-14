<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Incident extends Model
{
    protected $fillable = [
        'logs',
        'preprocessed',
        'likely_cause',
        'confidence',
        'next_steps',
    ];

    protected function casts(): array
    {
        return [
            'logs' => 'array',
            'preprocessed' => 'array',
            'confidence' => 'decimal:4',
        ];
    }

    public function metric(): HasOne
    {
        return $this->hasOne(Metric::class);
    }
}
