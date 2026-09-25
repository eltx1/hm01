@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Profile')
@section('heading', 'Profile')
@section('content')
@include('account._tabs')
<div class="ui-page ui-narrow-form">
    <div class="ui-page-intro"><div><h2>Your personal profile</h2><p>Keep the name your team sees up to date.</p></div></div>
    <form method="POST" action="{{ route('account.profile.update') }}" class="ui-form-surface">
        @csrf @method('PATCH')
        <x-form-section title="Personal details" description="Changes here apply to your own account.">
            <div class="ui-fields">
                <x-form-field id="account-name" name="name" label="Name" :value="old('name', $user->name)" autocomplete="name" maxlength="120" required />
                <x-form-field id="account-email" name="email_display" label="Email" type="email" :value="$user->email" autocomplete="email" readonly aria-readonly="true" help="Email changes are managed separately. Contact support if you need help." />
            </div>
        </x-form-section>
        <x-form-actions :cancel-href="route('account.index')" help="Your organization, roles and permissions stay the same."><button class="hm-button-primary" type="submit">Save profile</button></x-form-actions>
    </form>
</div>
@endsection
