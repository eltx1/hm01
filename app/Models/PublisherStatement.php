<?php

namespace App\Models;

use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublisherStatement extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'publisher_id', 'financial_period_id', 'statement_number',
        'status', 'currency', 'opening_balance_minor', 'gross_revenue_minor',
        'deductions_minor', 'net_revenue_minor', 'publisher_earnings_minor',
        'paid_minor', 'balance_due_minor', 'carry_forward_minor',
        'payment_threshold_minor', 'revenue_rule_version_id', 'line_items',
        'snapshot', 'snapshot_hash', 'finalized_at', 'finalized_by',
        'publisher_invoice_number', 'publisher_invoice_path',
        'publisher_invoice_uploaded_at', 'publisher_invoice_uploaded_by',
        'publisher_invoice_status', 'publisher_invoice_reviewed_at',
        'publisher_invoice_reviewed_by', 'publisher_invoice_review_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublisherStatementStatus::class,
            'publisher_invoice_status' => PublisherInvoiceStatus::class,
            'line_items' => 'array', 'snapshot' => 'array',
            'finalized_at' => 'datetime', 'publisher_invoice_uploaded_at' => 'datetime',
            'publisher_invoice_reviewed_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancialPeriod::class, 'financial_period_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PublisherPayment::class);
    }

    public function invoiceRequired(): bool
    {
        return $this->publisher_invoice_status !== PublisherInvoiceStatus::NotRequired;
    }

    public function scopeLatestPerPublisherCurrency(Builder $query): Builder
    {
        return $query->whereRaw(<<<'SQL'
            publisher_statements.financial_period_id = (
                SELECT latest_statements.financial_period_id
                FROM publisher_statements AS latest_statements
                INNER JOIN financial_periods AS latest_periods
                    ON latest_periods.id = latest_statements.financial_period_id
                WHERE latest_statements.publisher_id = publisher_statements.publisher_id
                    AND latest_statements.currency = publisher_statements.currency
                ORDER BY latest_periods.ends_on DESC, latest_statements.created_at DESC, latest_statements.id DESC
                LIMIT 1
            )
            SQL);
    }
}
