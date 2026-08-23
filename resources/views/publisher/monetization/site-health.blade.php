@if($internal)
    @include('admin.sites.quality-review')
@endif

<article id="monetization-health" class="workspace-section">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">Monetization readiness</p>
            <h2>{{ $internal ? 'Site monetization health' : 'Monetization health' }}</h2>
            <p>{{ $monetization['overall']['reason'] }}</p>
        </div>
        <x-status-badge :status="$monetization['overall']['status']" />
    </div>

    @if($monetization['overall']['action_required'])
        <p><strong>Next action:</strong> {{ $monetization['overall']['action_required'] }}</p>
    @endif

    @php
        $diagnosticValue = static function (mixed $value): string {
            if (!is_array($value)) {
                return (string) ($value ?? '—');
            }

            return collect($value)->map(static function (mixed $item, mixed $key): string {
                if (is_array($item)) {
                    return (string) $key.': '.json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }

                return is_string($key) ? $key.': '.(string) ($item ?? '—') : (string) ($item ?? '—');
            })->implode(', ');
        };
    @endphp

    <div class="health-grid">
        @foreach($monetization['modules'] as $module)
            <div>
                <span class="muted">{{ $module['title'] }} · {{ $module['dependency'] }}</span>
                <x-status-badge :status="$module['status']" />
                <small>{{ $module['reason'] }}</small>
                @if($module['action_required'])
                    <small><strong>Next action:</strong> {{ $module['action_required'] }}</small>
                @endif
                @if(!$internal && $module['action_route'])
                    <small><a href="{{ $module['action_route'] }}">Open required action</a></small>
                @endif
                @if($module['last_update'])
                    <small class="muted">Last known update: {{ $module['last_update'] }}</small>
                @endif
                @if($internal && !empty($module['diagnostics']))
                    <details>
                        <summary>Admin diagnostics</summary>
                        <dl>
                            @foreach($module['diagnostics'] as $key => $value)
                                <dt>{{ str($key)->replace('_', ' ')->headline() }}</dt>
                                <dd>{{ $diagnosticValue($value) }}</dd>
                            @endforeach
                        </dl>
                    </details>
                @endif
            </div>
        @endforeach
    </div>

    @if($internal)
        <details>
            <summary>Site 360 technical readiness</summary>
            <dl>
                @foreach($monetization['diagnostics'] as $key => $value)
                    <dt>{{ str($key)->replace('_', ' ')->headline() }}</dt>
                    <dd>{{ $diagnosticValue($value) }}</dd>
                @endforeach
            </dl>
        </details>
        <p class="muted">
            Manage rather than duplicate configuration:
            @if(auth()->user()->hasPermission('inventory.view'))<a href="{{ route('admin.sites.inventory.index', $site) }}">Inventory &amp; configuration</a> · @endif
            @if(auth()->user()->hasPermission('gam.connections.view'))<a href="{{ route('admin.gam.connections.index') }}">GAM connections</a> · @endif
            @if(auth()->user()->hasPermission('supply_chain.ads_txt.view'))<a href="{{ route('admin.compliance.ads-txt.show', $site) }}">Ads.txt</a> · @endif
            @if(auth()->user()->hasPermission('operations.view'))<a href="{{ route('admin.operations.index') }}">Operations</a>@endif
        </p>
    @else
        <p><a class="hm-button-secondary button-link" href="{{ route('publisher.monetization.index') }}">All website monetization health</a></p>
    @endif
</article>
