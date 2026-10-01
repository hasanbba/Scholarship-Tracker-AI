<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlerWorkerCredential extends Model
{
    protected $fillable = ['worker_id', 'token_hash', 'scopes', 'issued_by_user_id', 'rotated_from_id', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CrawlerWorker::class, 'worker_id');
    }
}
