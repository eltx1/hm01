<?php

namespace App\Services\Thoth;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class QualityResultSchema
{
    public static function jsonSchema(): array
    {
        $finding = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['code' => ['type' => 'string'], 'severity' => ['type' => 'string', 'enum' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']], 'explanation' => ['type' => 'string'], 'evidence' => ['type' => 'string']], 'required' => ['code', 'severity', 'explanation', 'evidence']];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => ['recommended_decision' => ['type' => 'string', 'enum' => ['APPROVE', 'LIMITED', 'REJECT', 'REVIEW_REQUIRED']], 'risk_level' => ['type' => 'string', 'enum' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']], 'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'categories' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['CONTENT_QUALITY', 'TRAFFIC_QUALITY', 'POLICY_RISK', 'PRIVACY_READINESS', 'SITE_TRANSPARENCY', 'MONETIZATION_RISK']]], 'findings' => ['type' => 'array', 'items' => $finding], 'positive_signals' => ['type' => 'array', 'items' => ['type' => 'string']], 'concerns' => ['type' => 'array', 'items' => ['type' => 'string']], 'recommended_admin_checks' => ['type' => 'array', 'items' => ['type' => 'string']], 'summary' => ['type' => 'string'], 'limitations' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['recommended_decision', 'risk_level', 'confidence', 'categories', 'findings', 'positive_signals', 'concerns', 'recommended_admin_checks', 'summary', 'limitations']];
    }

    public static function validate(array $result): array
    {
        $validator = Validator::make($result, [
            'recommended_decision' => ['required', 'in:APPROVE,LIMITED,REJECT,REVIEW_REQUIRED'], 'risk_level' => ['required', 'in:LOW,MEDIUM,HIGH,CRITICAL'],
            'confidence' => ['required', 'integer', 'between:0,100'], 'summary' => ['required', 'string', 'max:4000'], 'categories' => ['present', 'array', 'max:6'], 'categories.*' => ['in:CONTENT_QUALITY,TRAFFIC_QUALITY,POLICY_RISK,PRIVACY_READINESS,SITE_TRANSPARENCY,MONETIZATION_RISK'],
            'findings' => ['present', 'array', 'max:30'], 'findings.*.code' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', 'in:LOW,MEDIUM,HIGH,CRITICAL'], 'findings.*.explanation' => ['required', 'string', 'max:2000'],
            'findings.*.evidence' => ['required', 'string', 'max:2000'], 'positive_signals' => ['present', 'array', 'max:30'], 'positive_signals.*' => ['string', 'max:1000'], 'concerns' => ['present', 'array', 'max:30'], 'concerns.*' => ['string', 'max:1000'], 'recommended_admin_checks' => ['present', 'array', 'max:30'], 'recommended_admin_checks.*' => ['string', 'max:1000'],
            'limitations' => ['present', 'array', 'max:30'], 'limitations.*' => ['string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['provider_response' => 'The AI provider returned an invalid structured result.']);
        }

        return $validator->validated();
    }
}
