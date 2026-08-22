<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class AiProviderConnection extends Model
{
    use HasUlids;

    protected $fillable = ['provider', 'model', 'encrypted_credential', 'credential_source', 'status', 'last_tested_at', 'last_connected_at', 'last_test_latency_ms', 'last_error_code', 'updated_by'];

    protected $hidden = ['encrypted_credential'];

    protected function casts(): array
    {
        return ['encrypted_credential' => 'encrypted', 'last_tested_at' => 'datetime', 'last_connected_at' => 'datetime'];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function credential(): ?string
    {
        if ($this->getRawOriginal('encrypted_credential') !== null) {
            try {
                return $this->encrypted_credential;
            } catch (Throwable) {
                return null;
            }
        }

        return config('thoth.credentials.'.$this->provider);
    }

    public function effectiveCredentialSource(): string
    {
        return $this->getRawOriginal('encrypted_credential') !== null ? 'SECURE_ADMIN_CREDENTIAL' : (config('thoth.credentials.'.$this->provider) ? 'SERVER_CONFIGURATION' : 'NOT_CONFIGURED');
    }

    public function hasAdminCredential(): bool
    {
        return $this->getRawOriginal('encrypted_credential') !== null;
    }

    public function hasCredential(): bool
    {
        return $this->hasAdminCredential() || filled(config('thoth.credentials.'.$this->provider));
    }

    public function isReady(): bool
    {
        return $this->status === 'CONNECTED' && $this->last_connected_at?->gt(now()->subMinutes(config('thoth.connection_max_age_minutes')));
    }

    public function readiness(): string
    {
        if (! $this->hasCredential()) {
            return 'CREDENTIAL_MISSING';
        }
        if ($this->status === 'ERROR') {
            return $this->last_error_code ?: 'CONNECTION_FAILED';
        }
        if (! $this->isReady()) {
            return 'CONNECTION_UNTESTED';
        }

        return 'READY';
    }
}
