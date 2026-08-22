<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProviderConnection;
use App\Models\ThothSetting;
use App\Services\Audit\AuditRecorder;
use App\Services\Thoth\AiProviderManager;
use App\Services\Thoth\Data\PublisherQualityAiRequest;
use App\Services\Thoth\ThothProviderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class ThothSettingsController extends Controller
{
    public function index(): View
    {
        $settings = ThothSetting::current();

        foreach (['OPENAI', 'GEMINI'] as $provider) {
            AiProviderConnection::query()->firstOrCreate(
                ['provider' => $provider],
                ['model' => config('thoth.default_models.'.$provider), 'credential_source' => 'NONE', 'status' => 'NOT_CONFIGURED'],
            );
        }

        $connections = AiProviderConnection::query()->get()->keyBy('provider');

        return view('admin.thoth.settings', [
            'settings' => $settings,
            'connections' => $connections,
            'activeConnection' => $connections->get($settings->active_provider),
            'models' => config('thoth.models'),
        ]);
    }

    public function updateConnection(Request $request, string $provider, AuditRecorder $audit): RedirectResponse
    {
        $provider = strtoupper($provider);
        abort_unless(in_array($provider, ['OPENAI', 'GEMINI'], true), 404);
        $data = $request->validate(['model' => ['required', Rule::in(config('thoth.models.'.$provider))]]);
        $connection = AiProviderConnection::query()->firstOrNew(['provider' => $provider]);
        $before = $connection->exists ? $connection->only(['model', 'status']) : [];
        $connection->fill(['model' => $data['model'], 'status' => 'UNTESTED', 'last_error_code' => null, 'updated_by' => $request->user()->id]);
        $connection->credential_source = $connection->hasAdminCredential() ? 'DATABASE' : (config('thoth.credentials.'.$provider) ? 'ENVIRONMENT' : 'NONE');
        $connection->save();
        $audit->record('thoth.connection.updated', null, $request->user(), $connection, $before, $connection->only(['provider', 'model', 'status', 'credential_source']));

        return back()->with('status', $provider.' model updated; test the connection before activation.');
    }

    public function updateCredential(Request $request, string $provider, AuditRecorder $audit): RedirectResponse
    {
        $provider = strtoupper($provider);
        abort_unless(in_array($provider, ['OPENAI', 'GEMINI'], true), 404);
        $data = $request->validate(['credential' => ['required', 'string', 'min:10', 'max:1000', 'regex:/^\S+$/']]);
        $connection = AiProviderConnection::query()->firstOrCreate(['provider' => $provider], ['model' => config('thoth.default_models.'.$provider)]);
        $replacing = $connection->hasAdminCredential();
        $connection->update(['encrypted_credential' => trim($data['credential']), 'credential_source' => 'DATABASE', 'status' => 'UNTESTED', 'last_error_code' => null, 'updated_by' => $request->user()->id]);
        $audit->record($replacing ? 'thoth.credential.replaced' : 'thoth.credential.added', null, $request->user(), $connection, metadata: ['provider' => $provider, 'source' => 'SECURE_ADMIN_CREDENTIAL']);

        return back()->with('status', $provider.' API key encrypted and saved. Test the connection before activation.');
    }

    public function removeCredential(Request $request, string $provider, AuditRecorder $audit): RedirectResponse
    {
        $connection = AiProviderConnection::query()->where('provider', strtoupper($provider))->firstOrFail();
        $connection->update(['encrypted_credential' => null, 'credential_source' => config('thoth.credentials.'.$connection->provider) ? 'ENVIRONMENT' : 'NONE', 'status' => 'UNTESTED', 'last_error_code' => null, 'updated_by' => $request->user()->id]);
        $audit->record('thoth.credential.removed', null, $request->user(), $connection, metadata: ['provider' => $connection->provider, 'effective_source' => $connection->effectiveCredentialSource()]);

        return back()->with('status', 'Admin-managed credential removed.');
    }

    public function test(Request $request, string $provider, AiProviderManager $providers, AuditRecorder $audit): RedirectResponse
    {
        $connection = AiProviderConnection::query()->where('provider', strtoupper($provider))->firstOrFail();
        $started = hrtime(true);
        $connection->update(['last_tested_at' => now(), 'status' => 'TESTING', 'last_error_code' => null]);
        try {
            if (! $connection->credential()) {
                throw new ThothProviderException('CREDENTIAL_MISSING');
            }
            if (! $providers->for($connection->provider)->supportsModel($connection->model)) {
                throw new ThothProviderException('MODEL_INCOMPATIBLE');
            }
            $settings = ThothSetting::current();
            $providers->for($connection->provider)->analyze(new PublisherQualityAiRequest(['synthetic_test' => true, 'website_evidence' => [], 'evidence_gaps' => ['Connection validation only.']], config('thoth.policy_version'), config('thoth.schema_version')), $connection->model, $connection->credential(), $settings->timeout_seconds, min(800, $settings->max_output_tokens));
            $connection->update(['status' => 'CONNECTED', 'last_connected_at' => now(), 'last_test_latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'last_error_code' => null]);
            $message = 'Provider connection verified.';
        } catch (Throwable $exception) {
            $code = $exception instanceof ThothProviderException ? $exception->safeCode : 'CONNECTION_TEST_FAILED';
            $connection->update(['status' => 'ERROR', 'last_test_latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'last_error_code' => $code]);
            $message = 'Connection test failed: '.$code;
        }
        $audit->record('thoth.connection.tested', null, $request->user(), $connection, metadata: ['provider' => $connection->provider, 'status' => $connection->status, 'error_code' => $connection->last_error_code]);

        return back()->with($connection->status === 'CONNECTED' ? 'status' : 'error', $message);
    }

    public function update(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['nullable', 'boolean'], 'active_provider' => ['required', 'in:OPENAI,GEMINI'], 'timeout_seconds' => ['required', 'integer', 'between:5,60'], 'max_output_tokens' => ['required', 'integer', 'between:500,8000']]);
        $enabled = $request->boolean('enabled');
        if ($enabled) {
            $connection = AiProviderConnection::query()->where('provider', $data['active_provider'])->first();
            if (! $connection?->credential() || ! $connection->isReady()) {
                throw ValidationException::withMessages(['enabled' => 'Test the selected provider successfully before enabling THOTH.']);
            }
        }
        $settings = ThothSetting::current();
        $before = $settings->only(['enabled', 'active_provider', 'timeout_seconds', 'max_output_tokens']);
        $settings->update(array_merge($data, ['enabled' => $enabled, 'updated_by' => $request->user()->id]));
        $audit->record('thoth.settings.updated', null, $request->user(), $settings, $before, $settings->only(array_keys($before)));

        return back()->with('status', 'THOTH settings updated.');
    }
}
