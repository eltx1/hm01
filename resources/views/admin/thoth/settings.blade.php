@extends('layouts.admin')
@section('title', 'THOTH Quality Advisor')
@section('heading', 'THOTH AI Settings')
@section('content')
<section class="hero workspace-section"><div><p class="eyebrow">Advisory AI</p><h2>Publisher Quality Review Advisor</h2><p>THOTH provides structured evidence and recommendations. It cannot approve, reject, suspend, activate, serve ads, or alter finance. A Horus administrator always makes the final decision.</p></div><x-status-badge :status="$settings->enabled ? 'ENABLED' : 'DISABLED'" /></section>

@can('thoth.settings.manage')
<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Master control</p><h2>Runtime settings</h2></div></div>
<form method="POST" action="{{ route('admin.thoth.settings.update') }}" class="form-grid safe-submit">@csrf @method('PUT')
<label><input type="checkbox" name="enabled" value="1" @checked($settings->enabled)> Enable THOTH</label>
<label>Active provider<select name="active_provider">@foreach(['OPENAI','GEMINI'] as $provider)<option @selected($settings->active_provider === $provider)>{{ $provider }}</option>@endforeach</select></label>
<label>Timeout seconds<input type="number" name="timeout_seconds" min="5" max="60" value="{{ $settings->timeout_seconds }}"></label>
<label>Maximum output tokens<input type="number" name="max_output_tokens" min="500" max="8000" value="{{ $settings->max_output_tokens }}"></label>
<button class="hm-button-primary">Save runtime settings</button></form></article>
@endcan

@foreach(['OPENAI','GEMINI'] as $provider)
@php($connection = $connections->get($provider))
<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Production provider</p><h2>{{ $provider === 'OPENAI' ? 'OpenAI Responses API' : 'Gemini structured output' }}</h2></div><x-status-badge :status="$connection?->status ?? 'NOT_CONFIGURED'" /></div>
<div class="health-grid">
    <div><span class="muted">Model</span><strong>{{ $connection?->model ?? config('thoth.default_models.'.$provider) }}</strong><small>Structured output required</small></div>
    <div><span class="muted">Credential</span><strong>{{ ($connection?->credential() ?? config('thoth.credentials.'.$provider)) ? 'Configured · hidden' : 'Not configured' }}</strong><small>{{ $connection?->effectiveCredentialSource() ?? (config('thoth.credentials.'.$provider) ? 'SERVER_CONFIGURATION' : 'NOT_CONFIGURED') }}</small></div>
    <div><span class="muted">Readiness</span><strong>{{ $connection?->readiness() ?? 'CREDENTIAL_MISSING' }}</strong><small>{{ $connection?->last_connected_at?->toDayDateTimeString() ?? 'Never tested' }}{{ $connection?->last_test_latency_ms ? ' · '.$connection->last_test_latency_ms.' ms' : '' }}</small></div>
</div>
@if($connection?->last_error_code)
<p class="notice error">Safe error: {{ $connection->last_error_code }}</p>
@endif
@can('thoth.settings.manage')
<form method="POST" action="{{ route('admin.thoth.connections.update', $provider) }}" class="form-grid safe-submit">@csrf @method('PUT')<label>Supported model<select name="model">@foreach($models[$provider] as $model)<option @selected(($connection?->model ?? config('thoth.default_models.'.$provider)) === $model)>{{ $model }}</option>@endforeach</select></label><button>Save model</button></form>
@if($connection)
<form method="POST" action="{{ route('admin.thoth.connections.test', $provider) }}" class="inline safe-submit">@csrf<button>Test real connection</button></form>
@endif
@endcan
@can('thoth.credentials.manage')
<form method="POST" action="{{ route('admin.thoth.credentials.update', $provider) }}" class="form-grid safe-submit" autocomplete="off">@csrf @method('PUT')<label>Replace API credential<input type="password" name="credential" required autocomplete="new-password" value=""></label><button>Encrypt and replace</button></form>
@if($connection?->hasAdminCredential())
<form method="POST" action="{{ route('admin.thoth.credentials.destroy', $provider) }}" class="inline safe-submit">@csrf @method('DELETE')<button>Remove Admin credential</button></form>
@endif
@endcan
<p class="muted">Provider or model changes affect future reviews only. Historical advisories retain their provider, model, policy, and evidence metadata.</p>
</article>
@endforeach
@endsection
