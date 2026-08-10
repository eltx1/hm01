<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, SupportTicket $ticket, SupportTicketAttachment $attachment): StreamedResponse
    {
        $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->firstOrFail();
        if ($attachment->support_ticket_id !== $ticket->id) {
            abort(404);
        }

        $user = $request->user();
        $allowed = $user->isHorusAdministrator()
            ? $user->hasPermission('support.admin.view')
            : $user->hasPermission('support.tickets.view_own') && $user->organization_id === $ticket->organization_id;
        if (! $allowed) {
            throw new AuthorizationException;
        }
        abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404);

        return Storage::disk('local')->download($attachment->storage_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
