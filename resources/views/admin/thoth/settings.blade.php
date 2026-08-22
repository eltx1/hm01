@extends('layouts.admin')
@section('title', 'AI Control Center')
@section('heading', 'AI Control Center')
@section('content')
@php
    $providerOrder = ['GEMINI', 'OPENAI'];
    $activeReady = $activeConnection?->isReady() ?? false;
@endphp

<section class="hero workspace-section">
    <div>
        <p class="eyebrow">THOTH AI &amp; Automation</p>
        <h2>Connect, test, and activate AI.</h2>
        <p>Add a Gemini or OpenAI API key securely, run a real connection test, then activate the provider for future publisher quality reviews.</p>
    </div>
    <x-status-badge :status="$settings->enabled && $activeReady ? 'ENABLED' : 'DISABLED'" />
</section>

<ol class="ai-setup-progress" aria-label="AI setup progress">
    <li class="ai-step {{ $connections->contains(fn ($connection) => $connection->hasCredential()) ? 'is-complete' : '' }}">
        <span>1</span><div><strong>Add API key</strong><small>Encrypted at rest</small></div>
    </li>
    <li class="ai-step {{ $connections->contains(fn ($connection) => $connection->isReady()) ? 'is-complete' : '' }}">
        <span>2</span><div><strong>Test connection</strong><small>Real provider request</small></div>
    </li>
    <li class="ai-step {{ $settings->enabled && $activeReady ? 'is-complete' : '' }}">
        <span>3</span><div><strong>Activate THOTH</strong><small>Select the live provider</small></div>
    </li>
</ol>

<section class="ai-provider-grid" aria-label="AI providers">
@foreach($providerOrder as $provider)
    @php
        $connection = $connections->get($provider);
        $configured = $connection?->hasCredential() ?? false;
        $ready = $connection?->isReady() ?? false;
        $isActive = $settings->active_provider === $provider;
        $providerName = $provider === 'GEMINI' ? 'Gemini API' : 'OpenAI API';
    @endphp
    <article class="ai-provider-card {{ $isActive ? 'is-active' : '' }}" id="provider-{{ strtolower($provider) }}">
        <div class="workspace-heading">
            <div>
                <p class="eyebrow">{{ $provider === 'GEMINI' ? 'Google AI' : 'OpenAI' }}</p>
                <h2>{{ $providerName }}</h2>
            </div>
            <x-status-badge :status="$ready ? 'READY' : ($configured ? ($connection?->status ?? 'UNTESTED') : 'NOT_CONFIGURED')" />
        </div>

        <div class="ai-provider-health">
            <div><span>API key</span><strong>{{ $configured ? 'Configured · hidden' : 'Not configured' }}</strong></div>
            <div><span>Model</span><strong>{{ $connection?->model ?? config('thoth.default_models.'.$provider) }}</strong></div>
            <div><span>Last successful test</span><strong>{{ $connection?->last_connected_at?->diffForHumans() ?? 'Never' }}</strong></div>
        </div>

        @if($connection?->last_error_code)
            <p class="notice error">Connection error: {{ $connection->last_error_code }}</p>
        @endif

        @can('thoth.credentials.manage')
        <section class="ai-config-block">
            <span class="ai-config-number">1</span>
            <div>
                <h3>{{ $configured ? 'Replace API key' : 'Add API key' }}</h3>
                <p>The key is encrypted before storage and is never displayed again.</p>
                <form method="POST" action="{{ route('admin.thoth.credentials.update', $provider) }}" class="form-stack safe-submit" autocomplete="off">
                    @csrf @method('PUT')
                    <label>
                        {{ $providerName }} key
                        <input class="hm-input" type="password" name="credential" required minlength="10" maxlength="1000" autocomplete="new-password" placeholder="Paste the API key here">
                    </label>
                    <button class="hm-button-primary" type="submit">Encrypt and save key</button>
                </form>
                @if($connection?->hasAdminCredential())
                <form method="POST" action="{{ route('admin.thoth.credentials.destroy', $provider) }}" class="safe-submit">
                    @csrf @method('DELETE')
                    <button class="text-button" type="submit">Remove saved key</button>
                </form>
                @endif
            </div>
        </section>
        @endcan

        @can('thoth.settings.manage')
        <section class="ai-config-block">
            <span class="ai-config-number">2</span>
            <div>
                <h3>Select model and test</h3>
                <form method="POST" action="{{ route('admin.thoth.connections.update', $provider) }}" class="form-stack safe-submit">
                    @csrf @method('PUT')
                    <label>
                        Supported model
                        <select class="hm-input" name="model">
                        @foreach($models[$provider] as $model)
                            <option value="{{ $model }}" @selected(($connection?->model ?? config('thoth.default_models.'.$provider)) === $model)>{{ $model }}</option>
                        @endforeach
                        </select>
                    </label>
                    <button class="hm-button-secondary" type="submit">Save model</button>
                </form>
                @if($configured)
                <form method="POST" action="{{ route('admin.thoth.connections.test', $provider) }}" class="safe-submit">
                    @csrf
                    <button class="hm-button-primary" type="submit">Test {{ $provider === 'GEMINI' ? 'Gemini' : 'OpenAI' }} connection</button>
                </form>
                @else
                    <button class="hm-button-primary" type="button" disabled>Test {{ $provider === 'GEMINI' ? 'Gemini' : 'OpenAI' }} connection</button>
                    <p class="muted">Save an API key first to enable the connection test.</p>
                @endif
            </div>
        </section>
        @endcan

        <p class="muted">Credential source: {{ $connection?->effectiveCredentialSource() ?? 'NOT_CONFIGURED' }}. Provider changes affect future reviews only.</p>
    </article>
@endforeach
</section>

@can('thoth.settings.manage')
<article class="ai-activation-panel workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Final step</p><h2>Activate AI reviews</h2></div>
        <x-status-badge :status="$activeReady ? 'READY' : 'TEST_REQUIRED'" />
    </div>
    <p>Select a provider whose connection test passed. Activation remains blocked until the selected provider is ready.</p>
    <form method="POST" action="{{ route('admin.thoth.settings.update') }}" class="form-grid safe-submit">
        @csrf @method('PUT')
        <label>
            Active provider
            <select class="hm-input" name="active_provider">
            @foreach($providerOrder as $provider)
                <option value="{{ $provider }}" @selected($settings->active_provider === $provider)>{{ $provider === 'GEMINI' ? 'Google Gemini' : 'OpenAI' }}</option>
            @endforeach
            </select>
        </label>
        <label>
            Timeout seconds
            <input class="hm-input" type="number" name="timeout_seconds" min="5" max="60" value="{{ $settings->timeout_seconds }}">
        </label>
        <label>
            Maximum output tokens
            <input class="hm-input" type="number" name="max_output_tokens" min="500" max="8000" value="{{ $settings->max_output_tokens }}">
        </label>
        <label class="ai-enable-check">
            <input type="checkbox" name="enabled" value="1" @checked($settings->enabled)>
            <span><strong>Enable THOTH</strong><small>Use the selected provider for future AI-assisted reviews.</small></span>
        </label>
        <div class="full"><button class="hm-button-primary" type="submit">Save and activate</button></div>
    </form>
</article>
@endcan

<article class="workspace-section">
    <p class="eyebrow">Safety boundary</p>
    <h2>AI advises. Horus administrators decide.</h2>
    <p>THOTH cannot approve or reject publishers, suspend accounts, serve ads, change finance, or alter historical evidence. Every final decision remains human and audited.</p>
</article>
@endsection
