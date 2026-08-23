@extends('layouts.admin')
@section('title', 'Settings')
@section('heading', 'Settings & Governance')
@section('content')
@if(session('status'))<section><p>{{ session('status') }}</p></section>@endif
<section>
    <h2>Controlled global settings</h2>
    <p class="muted">Only registered safe business/product settings are editable here. Credentials, deployment configuration, emergency controls, publisher commercial terms, and payment destinations remain in their dedicated systems.</p>
</section>
<section>
    <p class="eyebrow">Client Traffic Gate</p>
    <h2>Fixed gate origin</h2>
    <p><code>{{ $trafficGateOrigin }}</code></p>
    <p class="muted">This HTTPS Horus-controlled origin comes from constrained server configuration and is read-only here. The Client Traffic Gate is a client-only soft traffic filter; it is not described as human verification, valid-traffic verification, bot verification, or IVT clearance.</p>
</section>

@foreach(collect($settings)->groupBy(fn($row) => $row['definition']->group) as $group => $rows)
<section>
    <h2>{{ $group }}</h2>
    <div class="table-wrap"><table>
        <thead><tr><th>Setting</th><th>Current / source</th><th>Change</th><th>Last changed</th></tr></thead>
        <tbody>
        @foreach($rows as $row)
            @php($definition = $row['definition'])
            <tr>
                <td>
                    <strong>{{ $definition->label }}</strong><br>
                    <code>{{ $definition->key }}</code>
                    <p class="muted">{{ $definition->description }}</p>
                    @if($definition->highImpact)<p><strong>High impact:</strong> {{ $definition->impact }}</p>@endif
                </td>
                <td>
                    <code>{{ is_bool($row['value']) ? ($row['value'] ? 'true' : 'false') : ($row['value'] ?? '—') }}</code><br>
                    <span class="muted">{{ str_replace('_', ' ', $row['source']) }}</span><br>
                    <span class="muted">Fallback: {{ is_bool($row['default']) ? ($row['default'] ? 'true' : 'false') : ($row['default'] ?? '—') }}</span>
                </td>
                <td>
                    @can('settings.manage')
                    <form method="POST" action="{{ route('admin.settings.update', ['key' => $definition->key]) }}" class="safe-submit">@csrf @method('PUT')
                        @if($definition->type === 'boolean')
                            <label>Value<select name="value"><option value="1" @selected((bool)$row['value'])>Enabled</option><option value="0" @selected(!(bool)$row['value'])>Disabled</option></select></label>
                        @elseif($definition->type === 'enum')
                            <label>Value<select name="value">@foreach($definition->allowedValues as $option)<option value="{{ $option }}" @selected($row['value'] === $option)>{{ $option }}</option>@endforeach</select></label>
                        @else
                            <label>Value<input type="{{ $definition->type === 'integer' ? 'number' : ($definition->type === 'email' ? 'email' : 'text') }}" name="value" value="{{ $row['value'] }}" @if(!in_array('nullable', $definition->rules, true)) required @endif></label>
                        @endif
                        @if($definition->highImpact)
                            <label>Reason<textarea name="reason" required maxlength="500" placeholder="Why this high-impact change is required"></textarea></label>
                            <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
                            <label>Confirmation<input name="impact_confirmation" required autocomplete="off" placeholder="CHANGE {{ strtoupper(str_replace(['.', '_'], ' ', $definition->key)) }}"></label>
                        @endif
                        <button type="submit">Save setting</button>
                    </form>
                    @if($row['source'] === 'DATABASE_OVERRIDE')
                    <form method="POST" action="{{ route('admin.settings.reset', ['key' => $definition->key]) }}" class="inline safe-submit">@csrf @method('DELETE')
                        @if($definition->highImpact)
                            <input name="reason" required maxlength="500" placeholder="Reset reason">
                            <input type="password" name="current_password" required autocomplete="current-password" placeholder="Password">
                            <input name="impact_confirmation" required autocomplete="off" placeholder="CHANGE {{ strtoupper(str_replace(['.', '_'], ' ', $definition->key)) }}">
                        @endif
                        <button type="submit">Reset to fallback</button>
                    </form>
                    @endif
                    @else<span class="muted">Read only</span>@endcan
                </td>
                <td>{{ $row['changed_at'] ?: 'Never overridden' }}<br><span class="muted">{{ $row['changed_by'] ?: 'Config fallback' }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endforeach
@endsection
