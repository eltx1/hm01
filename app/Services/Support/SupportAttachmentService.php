<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Uploads\SecureUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SupportAttachmentService
{
    public function __construct(private readonly SecureUploadService $uploads) {}

    public function store(UploadedFile $file, SupportTicket $ticket, SupportTicketMessage $message, User $actor): SupportTicketAttachment
    {
        $mimeMap = config('support.attachment_mimes');
        $detectedMime = strtolower((string) $file->getMimeType());
        $expectedExtension = $mimeMap[$detectedMime] ?? null;
        $clientExtension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = $expectedExtension === 'jpg' ? ['jpg', 'jpeg'] : [$expectedExtension];
        if (! $expectedExtension || ! in_array($clientExtension, $allowedExtensions, true)) {
            throw ValidationException::withMessages(['attachment' => 'The attachment extension does not match an allowed file type.']);
        }

        $stored = $this->uploads->storeRandomized(
            $file,
            'support/'.$ticket->organization_id.'/'.$ticket->id,
            $mimeMap,
            (int) config('support.attachment_max_bytes'),
        );

        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename($file->getClientOriginalName()));
        $name = mb_substr(trim((string) $name), 0, 240) ?: 'attachment.'.$stored['extension'];

        try {
            return SupportTicketAttachment::query()->create([
                'support_ticket_id' => $ticket->id,
                'support_ticket_message_id' => $message->id,
                'uploaded_by' => $actor->id,
                'storage_path' => $stored['path'],
                'original_name' => Str::of($name)->replace(['/', '\\'], '-')->value(),
                'mime_type' => $stored['mime'],
                'extension' => $stored['extension'],
                'checksum_sha256' => $stored['checksum'],
                'size_bytes' => $stored['size'],
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored['path']);

            throw $exception;
        }
    }
}
