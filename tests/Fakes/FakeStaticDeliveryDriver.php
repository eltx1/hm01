<?php

namespace Tests\Fakes;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\DB;

final class FakeStaticDeliveryDriver implements StaticDeliveryDriverInterface
{
    /** @var list<StaticDeliverySnapshot> */
    public array $snapshots = [];
    /** @var list<int> */
    public array $transactionLevels = [];
    public bool $fail = false;
    public bool $confirmed = true;

    public function name(): string { return 'fake-cloudflare'; }

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult
    {
        $this->transactionLevels[] = DB::transactionLevel();
        $this->snapshots[] = $snapshot;
        if ($this->fail) {
            throw new StaticDeliveryException('FAKE_UPLOAD_FAILED', 'Provider unavailable.');
        }

        return new StaticDeliveryResult(
            remoteId: 'deployment-'.$snapshot->manifestHash,
            remoteUrl: 'https://example.pages.dev',
            confirmedDeployed: $this->confirmed,
            metadata: ['manifest_hash' => $snapshot->manifestHash],
        );
    }
}
