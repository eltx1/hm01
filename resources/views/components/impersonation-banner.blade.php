@if(session()->has('impersonator_id') && auth()->check())
<section class="impersonation-banner" aria-label="Temporary publisher login">
    <div><strong>Temporary publisher login</strong><span>{{ auth()->user()->organization?->name }} · {{ auth()->user()->name }}</span><small>{{ auth()->user()->email }} · This changes the account in all tabs in this browser. Actions are audited.</small></div>
    <form method="POST" action="{{ route('admin.impersonate.stop') }}">@csrf @method('DELETE')<button class="hm-button-primary" type="submit" data-submitting-label="Returning…">Return to admin</button></form>
</section>
@endif
