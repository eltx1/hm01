<?php

use App\Enums\PublisherApplicationStatus;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentitySource;
use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->string('identity_source', 32)
                ->default(SellerIdentitySource::Manual->value)
                ->after('site_id')
                ->index();
            $table->timestamp('identity_issued_at')->nullable()->after('identity_source');
            $table->index(
                ['publisher_id', 'identity_source', 'site_id'],
                'seller_declarations_identity_scope_index',
            );
        });

        $approvedPublishers = DB::table('publisher_applications')
            ->join('publishers', 'publishers.id', '=', 'publisher_applications.publisher_id')
            ->where('publisher_applications.status', PublisherApplicationStatus::Approved->value)
            ->whereNull('publishers.deleted_at')
            ->select([
                'publishers.id',
                'publishers.organization_id',
                'publishers.legal_name',
                'publishers.business_domain',
            ])
            ->distinct()
            ->get();

        foreach ($approvedPublishers as $publisher) {
            $exists = DB::table('seller_declarations')
                ->where('publisher_id', $publisher->id)
                ->where('identity_source', SellerIdentitySource::HorusManaged->value)
                ->whereNull('site_id')
                ->exists();

            if ($exists) {
                continue;
            }

            $sellerId = $this->allocateSellerId();
            $now = now();
            DB::table('seller_declarations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $publisher->organization_id,
                'publisher_id' => $publisher->id,
                'site_id' => null,
                'identity_source' => SellerIdentitySource::HorusManaged->value,
                'identity_issued_at' => $now,
                'seller_id' => $sellerId,
                'seller_type' => SellerType::Publisher->value,
                'ads_txt_relationship' => 'DIRECT',
                'name' => trim((string) $publisher->legal_name),
                'domain' => $publisher->business_domain,
                'is_confidential' => false,
                'status' => SellerDeclarationStatus::Disabled->value,
                'review_status' => SupplyChainReviewStatus::ReviewRequired->value,
                'last_verified_at' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'metadata' => json_encode([
                    'lifecycle' => 'task_33_existing_approved_publisher_backfill',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('seller_declarations', function (Blueprint $table): void {
            $table->dropIndex('seller_declarations_identity_scope_index');
            $table->dropIndex(['identity_source']);
            $table->dropColumn(['identity_source', 'identity_issued_at']);
        });
    }

    private function allocateSellerId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'HMP-'.strtoupper((string) Str::ulid());
            if (! DB::table('seller_declarations')->where('seller_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique Horus public seller ID during migration.');
    }
};
