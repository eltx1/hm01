<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Financial recovery evidence; deliberately excluded from operational pruning. */
final class GamRevenueCorrectionReceipt extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'before' => 'array', 'after' => 'array', 'applied_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Correction receipts are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Correction receipts are immutable.'));
    }
}
