@extends('layouts.admin')
@php($editing = $connection->exists)
@php($credential = $connection->credential)
@section('title', $editing ? 'Edit GAM connection' : 'Add GAM connection')
@section('heading', $editing ? 'Edit GAM connection' : 'Add GAM connection')
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a class="active" href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
<a href="{{ route('admin.sites.index') }}">Websites</a>
@endsection
@section('content')
<article class="wizard-card">
<form class="form-grid" method="POST" action="{{ $editing ? route('admin.gam.connections.update', $connection) : route('admin.gam.connections.store') }}">
    @csrf
    @if($editing) @method('PUT') @endif
    <div class="full"><p class="eyebrow">Secure connection configuration</p><h2>{{ $editing ? $connection->name : 'New Google Ad Manager connection' }}</h2><p class="muted">Store only an <code>env:</code> or <code>file:</code> reference. Never paste a private key, refresh token, or credential JSON into this form.</p></div>

    <label>Owner organization<select class="hm-input" name="organization_id" required>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected(old('organization_id', $connection->organization_id ?: auth()->user()->organization_id) === $organization->id)>{{ $organization->name }} · {{ $organization->type->value }}</option>@endforeach</select></label>
    <label>Connection name<input class="hm-input" name="name" value="{{ old('name', $connection->name) }}" required></label>
    <label>Connection type<select class="hm-input" name="type" required>@foreach(\App\Enums\GamConnectionType::cases() as $type)<option value="{{ $type->value }}" @selected(old('type', $connection->type?->value ?? $connection->type) === $type->value)>{{ $type->value }}</option>@endforeach</select></label>
    <label>Credential type<select class="hm-input" name="credential_type" required>@foreach(\App\Enums\GamCredentialType::cases() as $type)<option value="{{ $type->value }}" @selected(old('credential_type', $connection->credential_type?->value ?? $connection->credential_type) === $type->value)>{{ $type->value }}</option>@endforeach</select></label>
    <label>Connector driver<select class="hm-input" name="driver"><option value="SOAP" @selected(old('driver', $connection->driver) === 'SOAP')>SOAP · production</option><option value="REST" @selected(old('driver', $connection->driver) === 'REST')>REST · isolated placeholder</option></select></label>
    <label>Network code<input class="hm-input" inputmode="numeric" name="network_code" value="{{ old('network_code', $connection->network_code) }}" placeholder="Optional until connection test"></label>
    <label>Application name<input class="hm-input" name="application_name" value="{{ old('application_name', $connection->application_name ?: config('gam.application_name')) }}" required></label>
    <label>Client email hint<input class="hm-input" type="email" name="client_email_hint" value="{{ old('client_email_hint', $credential?->client_email_hint) }}" placeholder="service-account@project.iam.gserviceaccount.com"></label>
    <label>OAuth client ID hint<input class="hm-input" name="oauth_client_id_hint" value="{{ old('oauth_client_id_hint', $credential?->oauth_client_id_hint) }}"></label>
    <label class="full">Credential reference<input class="hm-input" name="credential_reference" value="" placeholder="{{ $editing ? 'Leave blank to keep the encrypted reference' : 'env:GAM_HORUS_SERVICE_ACCOUNT_PATH' }}" @required(! $editing)><span class="muted">The resolved file must remain outside the public web directory.</span></label>
    <label class="full">OAuth scopes<input class="hm-input" name="scopes_text" value="{{ old('scopes_text', implode(',', $credential?->scopes ?? [config('gam.oauth.scope')])) }}"></label>
    <label class="full">Advanced configuration JSON<textarea class="hm-input" rows="6" name="configuration_json">{{ old('configuration_json', $connection->configuration ? json_encode($connection->configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea></label>

    <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $connection->is_enabled ?? true))> Enabled</label>
    <label><input type="hidden" name="dry_run_default" value="0"><input type="checkbox" name="dry_run_default" value="1" @checked(old('dry_run_default', $connection->dry_run_default ?? true))> Dry-run by default</label>
    <label><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $connection->is_primary))> Primary HORUS_GAM</label>

    <div class="full wizard-actions"><a class="hm-button-secondary button-link" href="{{ route('admin.gam.connections.index') }}">Cancel</a><button class="hm-button-primary" type="submit">{{ $editing ? 'Save connection' : 'Create connection' }}</button></div>
</form>
</article>
@endsection
