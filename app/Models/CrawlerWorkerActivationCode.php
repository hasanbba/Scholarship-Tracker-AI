<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlerWorkerActivationCode extends Model
{
    protected $fillable = ['code_hash', 'worker_label', 'issued_by_user_id', 'worker_id', 'expires_at', 'consumed_at', 'revoked_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CrawlerWorker::class);
    }
}
