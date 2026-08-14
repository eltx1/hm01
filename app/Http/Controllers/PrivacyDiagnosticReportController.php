<?php

namespace App\Http\Controllers;

use App\Services\Privacy\PrivacyReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PrivacyDiagnosticReportController extends Controller
{
    public function __invoke(Request $request, PrivacyReadinessService $privacy): JsonResponse
    {
        $allowedKeys = ['loaderVersion', 'configVersion', 'hostname', 'timestamp', 'tcf', 'gpp', 'gpcDetected', 'configuredTimeoutAction', 'prebid', 'privacyGateRespected'];
        if (array_diff(array_keys($request->all()), $allowedKeys) !== []) {
            throw ValidationException::withMessages(['payload' => 'The privacy diagnostic payload contains unsupported fields.']);
        }
        $data = $request->validate([
            'loaderVersion' => ['required', 'string', 'max:64'],
            'configVersion' => ['nullable', 'integer', 'min:0'],
            'hostname' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'timestamp' => ['required', 'date'],
            'tcf' => ['required', 'array:detected,responded,cmpId,eventStatus'],
            'tcf.detected' => ['required', 'boolean'],
            'tcf.responded' => ['required', 'boolean'],
            'tcf.cmpId' => ['nullable', 'integer', 'between:0,4294967295'],
            'tcf.eventStatus' => ['nullable', Rule::in(['tcloaded', 'useractioncomplete', 'cmpuishown', 'loading', 'loaded', 'stub'])],
            'gpp' => ['required', 'array:detected,responded,applicableSections'],
            'gpp.detected' => ['required', 'boolean'],
            'gpp.responded' => ['required', 'boolean'],
            'gpp.applicableSections' => ['present', 'array', 'max:20'],
            'gpp.applicableSections.*' => ['integer', 'between:-1,1000'],
            'gpcDetected' => ['required', 'boolean'],
            'configuredTimeoutAction' => ['required', Rule::in(['LIMITED_ADS', 'BLOCK_ADS', 'PROCEED'])],
            'prebid' => ['required', 'array:modulesPresent,consentConfigured,storageControlConfigured,activityControlsConfigured'],
            'prebid.modulesPresent' => ['present', 'array', 'max:40'],
            'prebid.modulesPresent.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'prebid.consentConfigured' => ['required', 'boolean'],
            'prebid.storageControlConfigured' => ['required', 'boolean'],
            'prebid.activityControlsConfigured' => ['required', 'boolean'],
            'privacyGateRespected' => ['required', 'boolean'],
        ]);

        $token = (string) $request->header('X-Horus-Diagnostic-Token');
        abort_if($token === '' || strlen($token) > 160, 401, 'Privacy diagnostic token is required.');
        $evidence = $privacy->acceptDiagnostic($token, (string) $request->header('Origin'), $data);

        return response()->json([
            'accepted' => true,
            'status' => $evidence->result_status,
            'resultHash' => $evidence->result_hash,
        ], 202);
    }
}
