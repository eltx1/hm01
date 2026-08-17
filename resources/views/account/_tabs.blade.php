<nav class="workspace-tabs" aria-label="Account settings">
    <a href="{{ route('account.index') }}" @if(request()->routeIs('account.index')) aria-current="page" class="active" @endif>Overview</a>
    <a href="{{ route('account.profile.edit') }}" @if(request()->routeIs('account.profile.*')) aria-current="page" class="active" @endif>Profile</a>
    <a href="{{ route('account.security') }}" @if(request()->routeIs('account.security*')) aria-current="page" class="active" @endif>Security</a>
</nav>
