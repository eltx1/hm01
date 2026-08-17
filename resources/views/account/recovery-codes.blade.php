@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Recovery codes')
@section('heading', 'Recovery codes')
@section('content')
@include('account._tabs')

<section class="workspace-section" aria-labelledby="recovery-codes-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Two-factor authentication</p><h2 id="recovery-codes-heading">Save your recovery codes</h2><p class="muted">These codes are shown once. Store them in a secure password manager or another protected location.</p></div></div>
    <div class="notice error" role="alert"><strong>Each recovery code can be used only once.</strong> Generating another set invalidates these codes.</div>
    <div id="account-recovery-codes" class="recovery-grid" aria-label="Two-factor recovery codes">@foreach($codes as $code)<code>{{ $code }}</code>@endforeach</div>
    <div class="wizard-actions">
        <button class="hm-button-secondary" type="button" data-copy-target="account-recovery-codes" data-copy-label="Copy all codes">Copy all codes</button>
        <a class="hm-button-primary" href="{{ route('account.security') }}">I saved the codes</a>
    </div>
</section>
@endsection
