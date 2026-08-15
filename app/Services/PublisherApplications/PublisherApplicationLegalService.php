<?php

namespace App\Services\PublisherApplications;

use App\Models\PublisherApplication;
use App\Models\PublisherApplicationLegalAcceptance;
use App\Models\PublisherApplicationMarketingConsent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublisherApplicationLegalService
{
    /** @return array<string, array{type:string, label:string, version:string, url:string, required:bool}> */
    public function documents(): array
    {
        return collect((array) config('publisher-applications.legal_documents', []))
            ->mapWithKeys(function (array $document, string $type): array {
                $version = trim((string) ($document['version'] ?? ''));
                $url = trim((string) ($document['url'] ?? ''));
                if ($version === '' || $url === '') {
                    return [];
                }

                return [$type => [
                    'type' => $type,
                    'label' => (string) ($document['label'] ?? str($type)->replace('_', ' ')->headline()),
                    'version' => $version,
                    'url' => $url,
                    'required' => (bool) ($document['required'] ?? true),
                ]];
            })->all();
    }

    /** @param array<string, mixed> $input */
    public function record(PublisherApplication $application, User $user, array $input, Request $request): void
    {
        $documents = $this->documents();
        $requestEvidenceHash = hash('sha256', (string) $request->userAgent());

        DB::transaction(function () use ($application, $user, $input, $documents, $requestEvidenceHash): void {
            foreach ($documents as $type => $document) {
                if ($document['required'] && ! filter_var($input['legal'][$type] ?? false, FILTER_VALIDATE_BOOL)) {
                    throw ValidationException::withMessages([
                        'legal.'.$type => 'You must explicitly accept the current '.$document['label'].' before submitting.',
                    ]);
                }

                if (! filter_var($input['legal'][$type] ?? false, FILTER_VALIDATE_BOOL)) {
                    continue;
                }

                $acceptedAt = now();
                $evidence = [
                    'application_id' => $application->id,
                    'user_id' => $user->id,
                    'document_type' => $type,
                    'document_version' => $document['version'],
                    'canonical_url' => $document['url'],
                    'accepted_at' => $acceptedAt->toISOString(),
                    'request_evidence_hash' => $requestEvidenceHash,
                ];

                PublisherApplicationLegalAcceptance::firstOrCreate([
                    'publisher_application_id' => $application->id,
                    'user_id' => $user->id,
                    'document_type' => $type,
                    'document_version' => $document['version'],
                ], [
                    'canonical_url' => $document['url'],
                    'accepted_at' => $acceptedAt,
                    'request_evidence_hash' => $requestEvidenceHash,
                    'evidence_hash' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ]);
            }

            $optedIn = filter_var($input['marketing_opt_in'] ?? false, FILTER_VALIDATE_BOOL);
            $recordedAt = now();
            $marketingEvidence = [
                'application_id' => $application->id,
                'user_id' => $user->id,
                'opted_in' => $optedIn,
                'recorded_at' => $recordedAt->toISOString(),
                'request_evidence_hash' => $requestEvidenceHash,
            ];
            PublisherApplicationMarketingConsent::create([
                'publisher_application_id' => $application->id,
                'user_id' => $user->id,
                'opted_in' => $optedIn,
                'recorded_at' => $recordedAt,
                'request_evidence_hash' => $requestEvidenceHash,
                'evidence_hash' => hash('sha256', json_encode($marketingEvidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);
        });
    }

    public function assertCurrentRequiredAccepted(PublisherApplication $application, User $user): void
    {
        $errors = [];
        foreach ($this->documents() as $type => $document) {
            if (! $document['required']) {
                continue;
            }
            $exists = PublisherApplicationLegalAcceptance::query()
                ->where('publisher_application_id', $application->id)
                ->where('user_id', $user->id)
                ->where('document_type', $type)
                ->where('document_version', $document['version'])
                ->where('canonical_url', $document['url'])
                ->exists();
            if (! $exists) {
                $errors['legal.'.$type] = 'Accept the current '.$document['label'].' before submitting.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
