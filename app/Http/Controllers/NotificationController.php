<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\HorusNotification;
use App\Services\Notifications\HorusNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->horusNotifications()
            ->where('in_app_visible', true)
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('notifications.index', compact('notifications'));
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $item = $this->owned($request, $notification);
        $item->update(['read_at' => $item->read_at ?? now()]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function unread(Request $request, string $notification): RedirectResponse
    {
        $this->owned($request, $notification)->update(['read_at' => null]);

        return back()->with('status', 'Notification marked as unread.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->horusNotifications()->where('in_app_visible', true)->unread()->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public function preferences(Request $request, HorusNotificationService $service): View
    {
        $request->user()->load('notificationPreferences');
        $preferences = collect(NotificationCategory::cases())->mapWithKeys(
            fn (NotificationCategory $category): array => [$category->value => $service->preference($request->user(), $category)],
        );

        return view('notifications.preferences', compact('preferences'));
    }

    public function updatePreferences(Request $request, HorusNotificationService $service): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['array'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
        ]);
        foreach (NotificationCategory::cases() as $category) {
            $values = $data['preferences'][$category->value] ?? [];
            $service->savePreference(
                $request->user(), $category,
                filter_var($values['in_app'] ?? false, FILTER_VALIDATE_BOOL),
                filter_var($values['email'] ?? false, FILTER_VALIDATE_BOOL),
            );
        }

        return back()->with('status', 'Notification preferences saved.');
    }

    private function owned(Request $request, string $id): HorusNotification
    {
        return $request->user()->horusNotifications()->where('in_app_visible', true)->whereKey($id)->firstOrFail();
    }
}
