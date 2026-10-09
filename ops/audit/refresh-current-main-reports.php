<?php
/** Bounded current-day main estimates only; uses the existing distributed importer lock. */
ini_set('display_errors','0'); ini_set('log_errors','0');

function hmTodayPlanIdentity(array $plan): array
{
    return array_map(static fn(array $entry)=>array_intersect_key($entry,array_flip(['binding','connection','day','scope','identity'])),$plan);
}
function hmTodayState(array $plan, ?array $state, ?int $startedAt=null): array
{
    if (!$plan || count($plan)>1000) throw new RuntimeException();
    if ($state===null) return ['schema_version'=>1,'operation_started_at'=>$startedAt ?? time(),'cursor'=>0,'entries'=>array_map(static fn($entry)=>$entry+['stage'=>0,'next_attempt_at'=>0,'first_completed_identity'=>null],$plan)];
    if (($state['schema_version'] ?? null)!==1 || !is_int($state['operation_started_at'] ?? null) || $state['operation_started_at']<0 || !is_int($state['cursor'] ?? null) || $state['cursor']<0 || $state['cursor']>=count($plan)
        || !is_array($state['entries'] ?? null) || hmTodayPlanIdentity($state['entries'])!==hmTodayPlanIdentity($plan)) throw new RuntimeException();
    foreach ($state['entries'] as $entry) {
        if (!in_array($entry['stage'] ?? null,[0,1,2],true) || !is_int($entry['next_attempt_at'] ?? null) || $entry['next_attempt_at']<0
            || ($entry['stage']===0 && ($entry['first_completed_identity'] ?? null)!==null)
            || ($entry['stage']>0 && (!is_string($entry['first_completed_identity'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$entry['first_completed_identity'])))) throw new RuntimeException();
    }
    return $state;
}
function hmTodayNext(array $state,int $now): ?int
{
    $count=count($state['entries']);
    for($offset=0;$offset<$count;$offset++) {
        $index=($state['cursor']+$offset)%$count; $entry=$state['entries'][$index];
        if ($entry['stage']<2 && $entry['next_attempt_at']<=$now) return $index;
    }
    return null;
}
function hmTodayDone(array $state): bool
{
    foreach($state['entries'] as $entry) if($entry['stage']!==2) return false;
    return true;
}
function hmTodayCompletionAllowed(array $entry,array $proof,int $operationStartedAt): bool
{
    if (!is_string($proof['identity'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$proof['identity'])
        || ($proof['row_count'] ?? null)!==1 || ($proof['fact_present'] ?? null)!==true
        || !is_int($proof['completed_at'] ?? null) || $proof['completed_at']<0) return false;
    if ($entry['stage']===0) return true; // An older completed snapshot may be drained first.
    return $entry['stage']===1 && is_string($entry['first_completed_identity'] ?? null)
        && !hash_equals($entry['first_completed_identity'],$proof['identity'])
        && $proof['completed_at']>=$operationStartedAt;
}
function hmTodayAdvance(array $state,int $index,string $status,int $now,?int $retryAt=null,?array $proof=null): array
{
    if (!isset($state['entries'][$index]) || $state['entries'][$index]['stage']>=2) throw new RuntimeException();
    if ($status==='COMPLETED') {
        if ($proof===null || !hmTodayCompletionAllowed($state['entries'][$index],$proof,$state['operation_started_at'])) throw new RuntimeException();
        if ($state['entries'][$index]['stage']===0) $state['entries'][$index]['first_completed_identity']=$proof['identity'];
        $state['entries'][$index]['stage']++;
    }
    $state['entries'][$index]['next_attempt_at']=max($now+60,$retryAt ?? 0);
    $state['cursor']=($index+1)%count($state['entries']);
    return $state;
}

function hmTodayMode(string|false $value): string
{
    $mode=$value===false || $value==='' ? 'preview' : $value;
    if (!in_array($mode,['preview','apply'],true)) throw new RuntimeException();
    return $mode;
}
function hmTodayRelease(string $link,string $root,string $expected): void
{
    clearstatcache(true,$link);
    if (realpath($link)!==$root || realpath('.')!==$root) throw new RuntimeException();
    $marker=@file_get_contents('.horus-release');
    if (!is_string($marker) || preg_match_all('/^release_id=([a-f0-9]{40})$/m',$marker,$matches)!==1 || $matches[1][0]!==$expected) throw new RuntimeException();
}
function hmTodayBinding($binding): array
{
    $binding->load(['site','connection.source','gamConnection']);
    $connection=$binding->connection; $site=$binding->site; $gam=$binding->gamConnection;
    if (!$site || !$connection || !$gam || !$binding->active_site_id || $binding->active_site_id!==$site->id
        || $binding->site_id!==$site->id || $binding->organization_id!==$site->organization_id
        || $connection->organization_id!==$site->organization_id || !$site->publisher_id
        || $connection->connection_type!=='SITE_GAM_AD_UNIT' || $connection->connection_id!==$binding->id
        || $connection->source?->code?->value!=='GAM_AD_UNIT' || !$connection->is_enabled || !$connection->source->is_enabled
        || $connection->status->value==='DISABLED' || !$gam->is_enabled || $gam->id!==$binding->gam_connection_id
        || (string)$gam->network_code!==$binding->network_code || $connection->currency!=='USD'
        || !is_string($connection->timezone) || $connection->timezone==='') throw new RuntimeException();
    $today=Carbon\CarbonImmutable::now($connection->timezone)->toDateString();
    if ($binding->starts_on->toDateString()>$today || ($binding->ends_on && $binding->ends_on->toDateString()<$today)) throw new RuntimeException();
    $scope=data_get($connection->configuration,'site_report_scope');
    if (!is_array($scope)) throw new RuntimeException();
    // Validation only. Never call ensure(), bind(), or any scope-cutover writer here.
    app(App\Services\Reporting\SiteGamReportScope::class)->assertCurrent($binding,$scope);
    if ($scope['effective_from']>$today) throw new RuntimeException();
    $period=App\Models\FinancialPeriod::withoutGlobalScopes()->whereNull('organization_id')->where('currency','USD')
        ->whereDate('starts_on','<=',$today)->whereDate('ends_on','>=',$today)->get(['id','status']);
    if ($period->count()!==1 || $period->first()->status->value!=='OPEN') throw new RuntimeException();
    $identity=hash('sha256',json_encode([$binding->id,$connection->id,$site->id,$site->organization_id,$site->publisher_id,
        $gam->id,$binding->network_code,$binding->ad_unit_id,$connection->timezone,$scope['fingerprint']],JSON_THROW_ON_ERROR));
    return ['binding'=>$binding->id,'connection'=>$connection->id,'day'=>$today,'scope'=>$scope['fingerprint'],'identity'=>$identity];
}
function hmTodayPlan(): array
{
    $bindings=App\Models\SiteGamReportBinding::withoutGlobalScopes()->whereNotNull('active_site_id')
        ->whereHas('connection',static fn($q)=>$q->where('is_enabled',true)->where('status','!=','DISABLED')
            ->whereHas('source',static fn($s)=>$s->where('is_enabled',true)))
        ->whereHas('gamConnection',static fn($q)=>$q->where('is_enabled',true))->orderBy('id')->limit(1001)->get();
    $plan=[];
    foreach($bindings as $binding) $plan[]=hmTodayBinding($binding);
    if (!$plan || count($plan)>1000) throw new RuntimeException();
    return $plan;
}
function hmTodayLoad(string $file): ?array
{
    if (!file_exists($file)) return null;
    if (is_link($file) || !is_file($file) || filesize($file)>1048576) throw new RuntimeException();
    $bytes=file_get_contents($file);
    if ($bytes===false || $bytes==='') throw new RuntimeException();
    return json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
}
function hmTodaySave(string $directory,string $file,array $state): void
{
    $temporary=tempnam($directory,'.current-main-');
    if ($temporary===false) throw new RuntimeException();
    try {
        if (!chmod($temporary,0600)) throw new RuntimeException();
        $bytes=json_encode($state,JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary,$bytes)!==strlen($bytes) || !rename($temporary,$file)) throw new RuntimeException();
    } finally { if (is_file($temporary)) @unlink($temporary); }
}
// Pure functions only in fixtures; production environment variables cannot enable this.
if (defined('HORUS_TODAY_REFRESH_TEST_ONLY') && HORUS_TODAY_REFRESH_TEST_ONLY===true) return;

$output=['schema_version'=>1,'operation'=>'CURRENT_MAIN_ESTIMATES','status'=>'UNAVAILABLE'];
$db=null; $lock=null;
try {
    $mode=hmTodayMode(getenv('HM_CURRENT_MAIN_MODE'));
    $expected=(string)getenv('HM_EXPECTED_RELEASE_SHA'); $token=(string)getenv('HM_CURRENT_MAIN_OPERATION');
    $link=(string)getenv('HM_CURRENT_MAIN_APP_LINK');
    if (!preg_match('/^[a-f0-9]{40}$/D',$expected) || !preg_match('/^[a-f0-9]{64}$/D',$token)
        || !preg_match('~^/[A-Za-z0-9/_.-]+$~D',$link) || !is_string($root=realpath($link))) throw new RuntimeException();
    hmTodayRelease($link,$root,$expected);
    require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $originalCache=[];
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,function($app) use (&$originalCache): void {
        $config=$app->make('config'); $config->set(['logging.default'=>'null','logging.deprecations.channel'=>'null','app.debug'=>false]);
        $originalCache=$config->get('cache');
        if (!$app->make('config_loaded_from_cache')) throw new RuntimeException();
        $packagesPath=$app->getCachedPackagesPath(); $servicesPath=$app->getCachedServicesPath();
        if (!is_file($packagesPath) || !is_readable($packagesPath) || !is_file($servicesPath) || !is_readable($servicesPath)) throw new RuntimeException();
        $packages=require $packagesPath; $services=require $servicesPath;
        if (!is_array($packages) || !is_array($services) || !is_array($services['providers'] ?? null)) throw new RuntimeException();
        $manifest=$app->make(Illuminate\Foundation\PackageManifest::class); $manifest->manifest=$packages;
        $providers=(new Illuminate\Support\Collection($config->get('app.providers')))->partition(static fn($provider)=>str_starts_with($provider,'Illuminate\\'));
        $providers->splice(1,0,[$manifest->providers()]);
        if ($services['providers']!=$providers->collapse()->toArray()) throw new RuntimeException();
    });
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,function() use (&$db): void {
        config(['cache.default'=>'array']); $db=Illuminate\Support\Facades\DB::connection();
        if ($db->getDriverName()!=='mysql') throw new RuntimeException();
        $db->statement('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!$db) throw new RuntimeException();
    $default=$originalCache['default'] ?? null;
    if (!is_string($default) || ($originalCache['stores'][$default]['driver'] ?? '')!=='database'
        || config('cache.stores.'.$default)!==$originalCache['stores'][$default]) throw new RuntimeException();
    $output['status']='BLOCKED';
    $plan=hmTodayPlan();
    $directory=storage_path('app/private/operations'); $file=$directory.'/current-main-'.$token.'.json';
    $state=hmTodayState($plan,hmTodayLoad($file));
    $db->rollBack();
    if ($mode==='preview') {
        $output['status']=hmTodayDone($state) ? 'COMPLETED' : 'ELIGIBLE';
    } else {
        // Restore exactly the existing shared cache before any importer call.
        config(['cache.default'=>$default]);
        if (!(Illuminate\Support\Facades\Cache::store($default)->getStore() instanceof Illuminate\Cache\DatabaseStore)) throw new RuntimeException();
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException();
        if (is_link($directory) || !is_writable($directory)) throw new RuntimeException();
        $lockFile=$directory.'/current-main-'.$token.'.lock';
        if (is_link($lockFile) || is_link($file)) throw new RuntimeException();
        $oldUmask=umask(0077);
        try { $lock=fopen($lockFile,'c+b'); } finally { umask($oldUmask); }
        if ($lock===false || !chmod($lockFile,0600)) throw new RuntimeException();
        if (!flock($lock,LOCK_EX|LOCK_NB)) {
            $output['status']='PENDING';
        } else {
            hmTodayRelease($link,$root,$expected);
            $state=hmTodayState(hmTodayPlan(),hmTodayLoad($file));
            if (hmTodayDone($state)) {
                $output['status']='COMPLETED';
            } elseif (($index=hmTodayNext($state,time()))===null) {
                $output['status']='PENDING';
            } else {
                // Persist the frozen plan before external work; retries cannot add scope.
                hmTodaySave($directory,$file,$state);
                $entry=$state['entries'][$index];
                $binding=App\Models\SiteGamReportBinding::withoutGlobalScopes()->findOrFail($entry['binding']);
                if (hmTodayPlanIdentity([hmTodayBinding($binding)])!==hmTodayPlanIdentity([$entry])) throw new RuntimeException();
                hmTodayRelease($link,$root,$expected);
                $connection=$binding->connection->fresh();
                $from=Carbon\CarbonImmutable::parse($entry['day'],$connection->timezone)->startOfDay();
                $audit=app(App\Services\Audit\AuditRecorder::class);
                $auditMetadata=['operation_id'=>$token,'stage'=>$entry['stage'],'source_date'=>$entry['day'],'purpose'=>'CURRENT_DAY_ESTIMATES_ONLY'];
                $audit->record('reporting.current_main_refresh.started',$binding->organization_id,auditable:$binding,metadata:$auditMetadata);
                $attemptStatus='UNAVAILABLE';
                try {
                    $job=app(App\Services\Reporting\ReportImportService::class)->runConnection($connection,$from,$from->endOfDay(),
                        App\Enums\ReportGranularity::Daily,App\Enums\ReportFinality::Estimated);
                    $attemptStatus=$job->status->value;
                } finally {
                    $audit->record('reporting.current_main_refresh.finished',$binding->organization_id,auditable:$binding,
                        metadata:$auditMetadata+['status'=>$attemptStatus]);
                }
                // Never start a second date or continue after a scope/identity change.
                hmTodayRelease($link,$root,$expected);
                if (hmTodayPlanIdentity([hmTodayBinding($binding->fresh())])!==hmTodayPlanIdentity([$entry])) throw new RuntimeException();
                if ($job->finality!==App\Enums\ReportFinality::Estimated || $job->settlement_eligible
                    || $job->period_start->toDateString()!==$entry['day'] || $job->period_end->toDateString()!==$entry['day']) throw new RuntimeException();
                $status=$job->status->value;
                $proof=null;
                if ($status==='COMPLETED') {
                    if (!is_string($job->external_report_id) || $job->external_report_id==='') throw new RuntimeException();
                    // Identical facts legitimately retain an earlier completed import pointer.
                    // Verify the current fact's provenance without reading amounts or counters.
                    $scope=data_get($binding->connection->configuration,'site_report_scope');
                    $factPresent=App\Models\DailyReport::withoutGlobalScopes()
                        ->where('organization_id',$binding->organization_id)->where('report_source_connection_id',$entry['connection'])
                        ->whereDate('report_date',$entry['day'])->where('currency','USD')
                        ->where('finality','ESTIMATED')->where('settlement_eligible',false)
                        ->whereHas('dimension',static fn($query)=>$query->where('organization_id',$binding->organization_id)
                            ->where('site_id',$binding->site_id)->where('gam_connection_id',$binding->gam_connection_id)
                            ->where('external_dimensions->gam_ad_unit_id',$binding->ad_unit_id)
                            ->where('external_dimensions->gam_report_site',$scope['hostname'])
                            ->where('external_dimensions->gam_report_scope',$entry['scope']))
                        ->whereHas('import',static fn($query)=>$query->where('organization_id',$binding->organization_id)
                            ->where('report_source_connection_id',$entry['connection'])->where('status','COMPLETED')
                            ->where('granularity','DAILY')->where('finality','ESTIMATED'))->exists();
                    $proof=['identity'=>hash('sha256',$job->external_report_id),'completed_at'=>$job->completed_at?->timestamp,
                        'row_count'=>(int)$job->row_count,'fact_present'=>$factPresent];
                }
                $state=hmTodayAdvance($state,$index,$status,time(),$job->next_retry_at?->timestamp,$proof);
                hmTodaySave($directory,$file,$state);
                $output['status']=hmTodayDone($state) ? 'COMPLETED' : match($status) {
                    'COMPLETED'=>'PROGRESS', 'PENDING'=>'PENDING', default=>'BLOCKED',
                };
            }
        }
    }
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    try { if ($db && $db->transactionLevel()>0) $db->rollBack(); } catch (Throwable) {}
    echo json_encode($output,JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    if (is_resource($lock)) { @flock($lock,LOCK_UN); fclose($lock); }
}
