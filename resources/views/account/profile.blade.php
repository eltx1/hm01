@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Profile')
@section('heading', 'Profile')
@section('content')
@include('account._tabs')

<section class="workspace-section" aria-labelledby="profile-heading">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">Personal details</p>
            <h2 id="profile-heading">Profile</h2>
            <p class="muted">You can update your own display name. Email, organization, roles, and permissions are not editable here.</p>
        </div>
    </div>

    <article>
        <form method="POST" action="{{ route('account.profile.update') }}" class="form-stack">
            @csrf
            @method('PATCH')
            <label for="account-name">
                <span class="field-label">Name</span>
                <input id="account-name" class="hm-input" name="name" type="text" value="{{ old('name', $user->name) }}" autocomplete="name" maxlength="120" required @error('name') aria-invalid="true" aria-describedby="account-name-error" @enderror>
            </label>
            @error('name')<p class="field-error" id="account-name-error" role="alert">{{ $message }}</p>@enderror

            <label for="account-email">
                <span class="field-label">Email</span>
                <input id="account-email" class="hm-input" type="email" value="{{ $user->email }}" autocomplete="email" readonly aria-readonly="true">
            </label>
            <p class="muted">Email changes require a separately controlled identity workflow and are not available from this page.</p>

            <button class="hm-button-primary" type="submit">Save profile</button>
        </form>
    </article>
</section>
@endsection
