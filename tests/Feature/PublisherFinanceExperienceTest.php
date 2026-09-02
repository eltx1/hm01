<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportSourceCode;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\FinancialPeriod;
use App\Models\PublisherContract;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherStatement;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\ReportImportService;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherFinanceExperienceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_publisher_finance_pages_separate_estimated_finalized_and_currencies(): void
    {
        [$admin, $publisher, $publisherAdmin, , $site] = $this->context();
        $connection = $this->connection($admin->organization_id);
        $estimatedDate = now()->toImmutable();
        $finalizedDate = $estimatedDate;
        $euroDate = $estimatedDate;

        app(ReportImportService::class)->importRows($connection, [[
            'date' => $estimatedDate, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 1000, 'gross_revenue_minor' => 10000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Estimated, $estimatedDate, $estimatedDate, $admin, 'publisher-estimated');
        app(ReportImportService::class)->importRows($connection, [[
            'date' => $finalizedDate, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 2000, 'gross_revenue_minor' => 20000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $finalizedDate, $finalizedDate, $admin, 'publisher-finalized');
        app(ReportImportService::class)->importRows($connection, [[
            'date' => $euroDate, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 500, 'gross_revenue_minor' => 5000, 'currency' => 'EUR',
        ]], ReportGranularity::Daily, ReportFinality::Estimated, $euroDate, $euroDate, $admin, 'publisher-eur-estimated');

        $response = $this->actingAs($publisherAdmin)->get(route('publisher.finance.overview'));
        $response->assertOk()
            ->assertSee('Estimated earnings')
            ->assertSee('Finalized earnings')
            ->assertSee('USD 70.00')
            ->assertSee('USD 140.00')
            ->assertSee('EUR 35.00')
            ->assertSee('Every currency is shown separately');
        $this->get(route('publisher.finance.statements.index'))->assertOk();
        $this->get(route('publisher.finance.payment-method.edit'))->assertOk();
        $this->get(route('publisher.finance.payouts.index'))->assertOk();
        $this->get(route('publisher.reporting.index'))->assertOk();
    }

    public function test_payment_profile_is_encrypted_masked_audited_and_reverification_is_automatic(): void
    {
        [$finance, $publisher, $publisherAdmin] = $this->context();
        $sensitive = 'PRIVATE-DESTINATION-9876';

        $this->actingAs($publisherAdmin)->put(route('publisher.finance.payment-method.update'), [
            'beneficiary_name' => 'Publisher Beneficiary', 'payment_method' => 'BANK_TRANSFER',
            'currency' => 'USD', 'country' => 'US', 'billing_address' => 'Publisher address',
            'account_reference' => $sensitive, 'routing_reference' => 'PRIVATE-ROUTING',
            'tax_identifier' => 'PRIVATE-TAX-ID',
        ])->assertRedirect();

        $profile = PublisherPaymentProfile::withoutGlobalScopes()->where('publisher_id', $publisher->id)->firstOrFail();
        $this->assertSame(PublisherPaymentProfileStatus::PendingVerification, $profile->verification_status);
        $this->assertFalse($profile->is_verified);
        $this->assertSame('9876', $profile->account_last_four);
        $raw = DB::table('publisher_payment_profiles')->where('id', $profile->id)->first();
        $this->assertStringNotContainsString($sensitive, (string) $raw->payment_details);
        $this->assertStringNotContainsString('PRIVATE-TAX-ID', (string) $raw->tax_identifier);

        $page = $this->get(route('publisher.finance.payment-method.edit'));
        $page->assertOk()->assertSee('••••9876')->assertDontSee($sensitive)->assertDontSee('PRIVATE-ROUTING')->assertDontSee('PRIVATE-TAX-ID');

        $this->actingAs($finance)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.publishers.payment-profile.review', $publisher), ['verification_status' => 'VERIFIED'])
            ->assertRedirect();
        $this->assertSame(PublisherPaymentProfileStatus::Verified, $profile->fresh()->verification_status);

        $replacement = 'NEW-PRIVATE-DESTINATION-1234';
        $this->actingAs($publisherAdmin)->put(route('publisher.finance.payment-method.update'), [
            'beneficiary_name' => 'Publisher Beneficiary', 'payment_method' => 'BANK_TRANSFER',
            'currency' => 'USD', 'country' => 'US', 'billing_address' => 'Publisher address',
            'account_reference' => $replacement, 'routing_reference' => 'NEW-PRIVATE-ROUTING',
        ])->assertRedirect();

        $profile->refresh();
        $this->assertSame(PublisherPaymentProfileStatus::NeedsUpdate, $profile->verification_status);
        $this->assertFalse($profile->is_verified);
        $this->assertNull($profile->verified_at);
        $this->assertNull($profile->verified_by);
        $auditPayload = AuditLog::query()->where('event', 'publisher.payment_profile.destination_changed')->latest()->firstOrFail()->toJson();
        $this->assertStringNotContainsString($replacement, $auditPayload);
        $this->assertStringNotContainsString('NEW-PRIVATE-ROUTING', $auditPayload);
        $this->assertStringNotContainsString('PRIVATE-TAX-ID', AuditLog::query()->get()->toJson());
    }

    public function test_sensitive_profile_values_are_not_flashed_after_validation_failure(): void
    {
        [, , $publisherAdmin] = $this->context();
        $this->actingAs($publisherAdmin)->from(route('publisher.finance.payment-method.edit'))
            ->put(route('publisher.finance.payment-method.update'), [
                'beneficiary_name' => 'Publisher', 'payment_method' => 'BANK_TRANSFER',
                'currency' => 'USD', 'country' => 'INVALID',
                'account_reference' => 'MUST-NOT-FLASH', 'routing_reference' => 'SECRET-ROUTING',
                'tax_identifier' => 'SECRET-TAX',
            ])->assertRedirect(route('publisher.finance.payment-method.edit'))->assertSessionHasErrors('country');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('account_reference', $oldInput);
        $this->assertArrayNotHasKey('routing_reference', $oldInput);
        $this->assertArrayNotHasKey('tax_identifier', $oldInput);
    }

    public function test_statement_invoice_and_download_enforce_object_ownership_and_private_storage(): void
    {
        Storage::fake('local');
        [, $publisher, $publisherAdmin, $publisherViewer] = $this->context();
        $own = $this->statement($publisher, 'HM-OWN-STATEMENT', 12000, 10000, PublisherInvoiceStatus::Required);

        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherUser = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['display_name' => 'Other Publisher']);
        $other = $this->statement($otherPublisher, 'HM-OTHER-PRIVATE', 15000, 10000, PublisherInvoiceStatus::Received, 'publisher-invoices/other/private.pdf');
        Storage::disk('local')->put('publisher-invoices/other/private.pdf', 'other-private-invoice');

        $this->actingAs($publisherAdmin)->get(route('publisher.finance.statements.index'))
            ->assertOk()->assertSee('HM-OWN-STATEMENT')->assertDontSee('HM-OTHER-PRIVATE');
        $this->get(route('publisher.finance.statements.show', $other))->assertNotFound();
        $this->get(route('publisher.finance.statements.csv', $other))->assertNotFound();
        $this->get(route('publisher.finance.statements.invoice.download', $other))->assertNotFound();
        $this->post(route('publisher.finance.statements.invoice', $other), [
            'invoice_number' => 'TAMPERED',
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        $this->post(route('publisher.finance.statements.invoice', $own), [
            'invoice_number' => 'PUB-INV-100',
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $own->refresh();
        $this->assertSame(PublisherInvoiceStatus::Received, $own->publisher_invoice_status);
        Storage::disk('local')->assertExists($own->publisher_invoice_path);
        $this->get(route('publisher.finance.statements.invoice.download', $own))->assertOk();

        $this->actingAs($publisherViewer)->post(route('publisher.finance.statements.invoice', $own), [
            'invoice_number' => 'VIEWER-TAMPER',
            'invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_safe_statement_csv_neutralizes_formula_cells_and_excludes_internal_economics(): void
    {
        [, $publisher, $publisherAdmin] = $this->context();
        $statement = $this->statement($publisher, 'HM-CSV-SAFE', 5000, 10000, PublisherInvoiceStatus::NotRequired, null, [[
            'source' => '=PRIVATE_SOURCE', 'site' => '=HYPERLINK("bad")', 'impressions' => 10,
            'gross_revenue_minor' => 10000, 'net_revenue_minor' => 8000,
            'publisher_earnings_minor' => 5000,
        ]]);

        $response = $this->actingAs($publisherAdmin)->get(route('publisher.finance.statements.csv', $statement));
        $response->assertOk();
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('PRIVATE_SOURCE', $csv);
        $this->assertStringNotContainsString('Gross Revenue Minor', $csv);
        $this->assertStringNotContainsString('Net Revenue Minor', $csv);
    }

    public function test_partial_payout_history_uses_settled_amount_and_never_creation_as_payment(): void
    {
        [$finance, $publisher, $publisherAdmin] = $this->context();
        $approver = $this->makeUser($finance->organization, RoleName::FinanceAdmin);
        $profile = app(PublisherPaymentProfileService::class)->save($publisher, [
            'beneficiary_name' => 'Publisher Beneficiary', 'payment_method' => 'WISE',
            'currency' => 'USD', 'country' => 'US', 'account_reference' => 'WISE-1234',
        ], $publisherAdmin);
        app(PublisherPaymentProfileService::class)->review($profile, PublisherPaymentProfileStatus::Verified, $finance);
        $statement = $this->statement($publisher, 'HM-PAYOUT-STATEMENT', 20000, 10000, PublisherInvoiceStatus::Accepted, 'publisher-invoices/own.pdf');

        $payment = app(PublisherPaymentService::class)->create($statement, 10000, [
            'payment_method' => 'WISE', 'scheduled_on' => now()->addDay()->toDateString(),
        ], $finance);
        $this->actingAs($publisherAdmin)->get(route('publisher.finance.payouts.index'))
            ->assertOk()->assertSee('PENDING')->assertSee('USD 0.00')->assertSee('USD 100.00');

        app(PublisherPaymentService::class)->approve($payment, $approver);
        app(PublisherPaymentService::class)->markPaid($payment->fresh(), 'SAFE-SETTLEMENT-REF', $finance, 4000);
        $page = $this->get(route('publisher.finance.payouts.index'));
        $page->assertOk()->assertSee('PARTIALLY_PAID')->assertSee('Partial settlement')
            ->assertSee('USD 40.00')->assertSee('USD 100.00')->assertSee('SAFE-SETTLEMENT-REF');
        $this->assertSame(16000, $statement->fresh()->balance_due_minor);
    }

    public function test_viewer_can_view_own_finance_but_cannot_change_profile_or_verify_it(): void
    {
        [$finance, $publisher, , $publisherViewer] = $this->context();
        $this->actingAs($publisherViewer)->get(route('publisher.finance.overview'))->assertOk();
        $this->put(route('publisher.finance.payment-method.update'), [
            'beneficiary_name' => 'Tamper', 'payment_method' => 'OTHER', 'currency' => 'USD', 'country' => 'US',
        ])->assertForbidden();
        $this->post(route('admin.publishers.payment-profile.review', $publisher), [
            'verification_status' => 'VERIFIED',
        ])->assertForbidden();

        $this->actingAs($finance)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('publisher.finance.overview'))->assertForbidden();
    }

    private function context(): array
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $finance = $this->makeUser($horus, RoleName::FinanceAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $publisherAdmin = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisherViewer = $this->makeUser($publisherOrg, RoleName::PublisherViewer);
        $publisher = $this->makePublisherFor($publisherAdmin, ['display_name' => 'Publisher']);
        $site = $this->makeSiteFor($publisher, $publisherAdmin, ['display_name' => 'Publisher Site']);
        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
            'contract_reference' => 'FINANCE-EXPERIENCE', 'starts_at' => now()->subYear(),
            'auto_renews' => false, 'revenue_share_percent' => '70.00',
            'payment_threshold' => '100.00', 'currency' => 'USD', 'payment_terms' => 'NET_30',
            'status' => 'ACTIVE', 'created_by' => $finance->id,
        ]);

        return [$finance, $publisher, $publisherAdmin, $publisherViewer, $site];
    }

    private function connection(string $organizationId): ReportSourceConnection
    {
        $source = ReportSource::query()->where('code', ReportSourceCode::HorusGam->value)->firstOrFail();

        return ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $organizationId, 'report_source_id' => $source->id,
            'name' => 'Horus GAM', 'connection_type' => 'TEST', 'connection_id' => 'publisher-finance',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
    }

    private function statement(
        $publisher,
        string $number,
        int $balance,
        int $threshold,
        PublisherInvoiceStatus $invoiceStatus,
        ?string $invoicePath = null,
        array $lineItems = [],
    ): PublisherStatement {
        $period = FinancialPeriod::query()->firstOrCreate([
            'organization_id' => null, 'period_key' => now()->subMonthNoOverflow()->format('Y-m'), 'currency' => 'USD',
        ], [
            'starts_on' => now()->subMonthNoOverflow()->startOfMonth(),
            'ends_on' => now()->subMonthNoOverflow()->endOfMonth(), 'status' => 'CLOSED',
        ]);

        return PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
            'financial_period_id' => $period->id, 'statement_number' => $number,
            'status' => $balance >= $threshold ? 'PAYABLE' : 'BELOW_THRESHOLD', 'currency' => 'USD',
            'opening_balance_minor' => 1000, 'deductions_minor' => 500,
            'publisher_earnings_minor' => max(0, $balance - 1000), 'paid_minor' => 0,
            'balance_due_minor' => $balance, 'carry_forward_minor' => $balance < $threshold ? $balance : 0,
            'payment_threshold_minor' => $threshold, 'line_items' => $lineItems,
            'snapshot' => [], 'snapshot_hash' => hash('sha256', $number), 'finalized_at' => now(),
            'publisher_invoice_status' => $invoiceStatus, 'publisher_invoice_path' => $invoicePath,
            'publisher_invoice_number' => $invoicePath ? 'INV-'.$number : null,
            'publisher_invoice_uploaded_at' => $invoicePath ? now() : null,
        ]);
    }
}
