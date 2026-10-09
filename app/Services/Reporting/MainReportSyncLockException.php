<?php

namespace App\Services\Reporting;

use RuntimeException;

/** A main-sync worker must stop; this is not a provider/report failure. */
class MainReportSyncLockException extends RuntimeException {}
