<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    protected $fillable = [
        'incident_id',
        'cpu',
        'db_latency',
        'requests_per_sec',
    ];

    protected function casts(): array
    {
        return [
            'cpu' => 'integer',
            'db_latency' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
