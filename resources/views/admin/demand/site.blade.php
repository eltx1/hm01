@extends('layouts.admin')
@section('title', $site->display_name.' Native demand')
@section('heading', 'Native demand · '.$site->display_name)
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Website-controlled activation</p>
        <h2>{{ $site->primary_domain }}</h2>
        <p>Horus Loader reads this website’s static configuration. Adding, disabling, or reordering a native connector never changes publisher installation code.</p>
    </div>
    <div>
        <div class="status-row">
            <span class="pill">{{ $site->native_demand_enabled ? 'NATIVE ENABLED' : 'NATIVE DISABLED' }}</span>
            <span class="pill">{{ $mappings->count() }} account mappings</span>
        </div>
        <form method="POST" action="{{ route('admin.sites.demand.status', $site) }}">@csrf @method('PATCH')
            <input type="hidden" name="enabled" value="{{ $site->native_demand_enabled ? 0 : 1 }}">
            <button class="hm-button-secondary">{{ $site->native_demand_enabled ? 'Disable website native demand' : 'Enable website native demand' }}</button>
        </form>
    </div>
</section>

<article>
    <p class="eyebrow">Assign account</p>
    <h3>Add native demand to this website</h3>
    @forelse($accounts as $account)
    <form class="form-grid domain-card" method="POST" action="{{ route('admin.sites.demand.assign', [$site, $account]) }}">@csrf
        <label>Account<strong>{{ $account->name }} · {{ $account->network->code->value }}</strong></label>
        <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($status->value === 'NOT_SUBMITTED')>{{ $status->value }}</option>@endforeach</select></label>
        <label>Mode override<select class="hm-input" name="integration_mode"><option value="">Account default</option>@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ $mode->value }}</option>@endforeach</select></label>
        <label>Priority<input class="hm-input" type="number" name="fallback_priority" value="{{ $account->fallback_priority }}"></label>
        <label>Remote site ID<input class="hm-input" name="remote_site_id" placeholder="Provider-approved website ID"></label>
        <label class="full">Site configuration JSON<textarea class="hm-input" rows="3" name="configuration_json">{}</textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1"> Default</label>
        <div><button class="hm-button-secondary">Assign and publish</button></div>
    </form>
    @empty<p class="muted">Create a demand account first.</p>@endforelse
</article>

@forelse($mappings as $mapping)
<article>
    <div class="section-heading">
        <div>
            <p class="eyebrow">{{ $mapping->account->network->code->value }}</p>
            <h2>{{ $mapping->account->name }}</h2>
        </div>
        <div class="status-row">
            <span class="pill">{{ $mapping->approval_status->value }}</span>
            <span class="pill">{{ $mapping->is_enabled ? 'enabled' : 'disabled' }}</span>
            <span class="pill">{{ $mapping->sync_status->value }}</span>
        </div>
    </div>

    <form class="form-grid" method="POST" action="{{ route('admin.sites.demand.mappings.update', [$site, $mapping]) }}">@csrf @method('PUT')
        <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($mapping->approval_status === $status)>{{ $status->value }}</option>@endforeach</select></label>
        <label>Mode<select class="hm-input" name="integration_mode"><option value="">Account default</option>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected($mapping->integration_mode === $mode)>{{ $mode->value }}</option>@endforeach</select></label>
        <label>Revenue share %<input class="hm-input" type="number" step="0.001" min="0" max="100" name="revenue_share_percent" value="{{ $mapping->revenue_share_percent }}"></label>
        <label>Priority<input class="hm-input" type="number" name="fallback_priority" value="{{ $mapping->fallback_priority }}"></label>
        <label>Remote site ID<input class="hm-input" name="remote_site_id" value="{{ $mapping->remote_site_id }}"></label>
        <label class="full">Site configuration JSON<textarea class="hm-input" rows="4" name="configuration_json">{{ json_encode($mapping->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($mapping->is_enabled)> Enabled</label>
        <label><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked($mapping->is_default)> Default</label>
        <div><button class="hm-button-secondary">Save and publish</button></div>
    </form>

    <div class="status-row">
        <form method="POST" action="{{ route('admin.sites.demand.mappings.sync', [$site, $mapping]) }}">@csrf<input type="hidden" name="dry_run" value="1"><button class="hm-button-secondary">Provider dry-run</button></form>
        <form method="POST" action="{{ route('admin.sites.demand.mappings.sync', [$site, $mapping]) }}">@csrf<input type="hidden" name="dry_run" value="0"><button class="hm-button-secondary">Synchronize provider site</button></form>
        <form method="POST" action="{{ route('admin.sites.demand.mappings.status', [$site, $mapping]) }}">@csrf<button class="hm-button-secondary">Refresh provider approval</button></form>
        <form method="POST" action="{{ route('admin.sites.demand.ads_txt', [$site, $mapping]) }}">@csrf<button class="hm-button-secondary">Synchronize ads.txt</button></form>
    </div>

    <section class="detail-grid">
        <article>
            <p class="eyebrow">GAM deployment plan</p>
            <h3>{{ data_get($plans, $mapping->id.'.pendingObjects', 0) }} pending objects</h3>
            @foreach(data_get($plans, $mapping->id.'.issues', []) as $issue)<p class="error">{{ $issue }}</p>@endforeach
            <div class="status-row">
                <span class="pill">{{ data_get($plans, $mapping->id.'.estimatedObjects', 0) }} total</span>
                <span class="pill">{{ data_get($plans, $mapping->id.'.existingObjects', 0) }} existing</span>
            </div>
            <form method="POST" action="{{ route('admin.sites.demand.gam.deploy', [$site, $mapping]) }}">@csrf<input type="hidden" name="dry_run" value="1"><button class="hm-button-secondary">Run GAM dry-run</button></form>
            <form class="form-stack danger-zone" method="POST" action="{{ route('admin.sites.demand.gam.deploy', [$site, $mapping]) }}">@csrf
                <input type="hidden" name="dry_run" value="0">
                <label><input type="checkbox" name="confirm_external_writes" value="1" required> Confirm external GAM writes</label>
                <button class="hm-button-primary">Deploy native GAM objects</button>
            </form>
        </article>
        <article>
            <p class="eyebrow">ads.txt</p>
            <h3>{{ $mapping->account->adsTxtRecords->count() }} stored records</h3>
            @foreach($mapping->account->adsTxtRecords as $record)<code style="display:block;margin:.4rem 0">{{ $record->raw_record }}</code>@endforeach
        </article>
    </section>

    <h3>Placement mappings</h3>
    @foreach($site->placements as $placement)
        @php($demandPlacement = $mapping->placements->firstWhere('placement_id', $placement->id))
        @if(!$demandPlacement)
        <form class="form-grid domain-card" method="POST" action="{{ route('admin.sites.demand.placements.assign', [$site, $mapping, $placement]) }}">@csrf
            <label>Placement<strong>{{ $placement->name }}</strong></label>
            <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}">{{ $status->value }}</option>@endforeach</select></label>
            <label>Mode<select class="hm-input" name="integration_mode"><option value="">Inherited</option>@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ $mode->value }}</option>@endforeach</select></label>
            <label>Priority<input class="hm-input" type="number" name="fallback_priority" value="{{ $mapping->fallback_priority ?? $mapping->account->fallback_priority }}"></label>
            <label>Remote placement ID<input class="hm-input" name="remote_placement_id"></label>
            <label>Placement/widget code<input class="hm-input" name="placement_code"></label>
            <label class="full">Configuration JSON<textarea class="hm-input" rows="3" name="configuration_json">{}</textarea></label>
            <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
            <div><button class="hm-button-secondary">Assign placement</button></div>
        </form>
        @else
        <div class="domain-card">
            <div>
                <strong>{{ $placement->name }}</strong>
                <span class="pill">{{ $demandPlacement->approval_status->value }}</span>
                <span class="pill">{{ $demandPlacement->is_enabled ? 'enabled' : 'disabled' }}</span>
                <span class="pill">{{ $demandPlacement->integration_mode?->value ?? 'INHERITED' }}</span>
            </div>
            <form class="form-grid" method="POST" action="{{ route('admin.sites.demand.placements.update', [$site, $demandPlacement]) }}">@csrf @method('PUT')
                <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($demandPlacement->approval_status === $status)>{{ $status->value }}</option>@endforeach</select></label>
                <label>Mode<select class="hm-input" name="integration_mode"><option value="">Inherited</option>@foreach($modes as $mode)<option value="{{ $mode->value }}" @selected($demandPlacement->integration_mode === $mode)>{{ $mode->value }}</option>@endforeach</select></label>
                <label>Priority<input class="hm-input" type="number" name="fallback_priority" value="{{ $demandPlacement->fallback_priority }}"></label>
                <label>Remote placement ID<input class="hm-input" name="remote_placement_id" value="{{ $demandPlacement->remote_placement_id }}"></label>
                <label>Code<input class="hm-input" name="placement_code" value="{{ $demandPlacement->placement_code }}"></label>
                <label class="full">Configuration JSON<textarea class="hm-input" rows="3" name="configuration_json">{{ json_encode($demandPlacement->configuration ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
                <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($demandPlacement->is_enabled)> Enabled</label>
                <div><button class="hm-button-secondary">Save placement</button></div>
            </form>
            <div class="status-row">
                <form method="POST" action="{{ route('admin.sites.demand.placements.sync', [$site, $demandPlacement]) }}">@csrf<input type="hidden" name="dry_run" value="0"><button class="hm-button-secondary">Synchronize provider placement</button></form>
                <form method="POST" action="{{ route('admin.sites.demand.placements.status', [$site, $demandPlacement]) }}">@csrf<input type="hidden" name="enabled" value="{{ $demandPlacement->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $demandPlacement->is_enabled ? 'Pause' : 'Activate' }}</button></form>
            </div>
            <form class="form-grid" method="POST" action="{{ route('admin.sites.demand.widgets.store', [$site, $demandPlacement]) }}">@csrf
                <label>Widget name<input class="hm-input" name="name" value="{{ $mapping->account->network->name }} widget" required></label>
                <label>Remote widget ID<input class="hm-input" name="remote_widget_id"></label>
                <label>Widget code<input class="hm-input" name="widget_code"></label>
                <label>Mode<select class="hm-input" name="integration_mode"><option value="">Inherited</option>@foreach($modes as $mode)<option value="{{ $mode->value }}">{{ $mode->value }}</option>@endforeach</select></label>
                <label>Approval<select class="hm-input" name="approval_status">@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($status->value === 'APPROVED')>{{ $status->value }}</option>@endforeach</select></label>
                <label class="full">Widget configuration JSON<textarea class="hm-input" rows="3" name="configuration_json" placeholder='{"script_url":"https://allowlisted.example/widget.js","container_id":"widget-123"}'>{}</textarea></label>
                <input type="hidden" name="is_enabled" value="1">
                <div><button class="hm-button-secondary">Save widget</button></div>
            </form>
        </div>
        @endif
    @endforeach
</article>
@empty
<article><p class="muted">No native demand account is assigned to this website.</p></article>
@endforelse
@endsection
