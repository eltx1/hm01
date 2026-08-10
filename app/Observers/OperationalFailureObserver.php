<?php

namespace App\Observers;

use App\Enums\GamOperationStatus;
use App\Enums\ReportImportStatus;
use App\Enums\StaticDeliveryStatus;
use App\Models\GamApiOperation;
use App\Models\ReportImportJob;
use App\Models\StaticDeliveryBatch;
use App\Services\Notifications\DomainNotificationService;

class OperationalFailureObserver
{
    public function __construct(private readonly DomainNotificationService $notifications) {}

    public function created(object $record): void
    {
        $this->emitIfFailed($record);
    }

    public function updated(object $record): void
    {
        if ($record->wasChanged('status')) {
            $this->emitIfFailed($record);
        }
    }

    private function emitIfFailed(object $record): void
    {
        if ($record instanceof ReportImportJob && in_array($record->status, [ReportImportStatus::Failed, ReportImportStatus::BlockedClosedPeriod], true)) {
            $this->notifications->operationFailed($record, 'REPORT_IMPORT', 'reporting.import', 'admin.reporting.index');
        } elseif ($record instanceof StaticDeliveryBatch && $record->status === StaticDeliveryStatus::Failed) {
            $this->notifications->operationFailed($record, 'STATIC_DELIVERY', 'operations.view', 'admin.operations.index');
        } elseif ($record instanceof GamApiOperation && $record->status === GamOperationStatus::Failed) {
            $this->notifications->operationFailed($record, 'GAM_OPERATION', 'operations.view', 'admin.operations.index');
        }
    }
}
