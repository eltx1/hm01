@extends('layouts.admin')
@section('title', 'Create Support Ticket')
@section('heading', 'Create Support Ticket')
@section('content')
<section class="hero"><div><p class="eyebrow">Secure first-party support</p><h2>How can we help?</h2><p>Describe one issue clearly. Payment, reporting, website, contract, and campaign resources can be linked only when they belong to your organization.</p></div></section>
<article class="workspace-section">
    <form method="post" action="{{ route('support.tickets.store') }}" enctype="multipart/form-data" class="form-grid">
        @csrf
        <label class="full">Subject<input class="hm-input" name="subject" value="{{ old('subject') }}" maxlength="255" required></label>
        <label>Category<select class="hm-input" name="category" required>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
        <label>Priority<select class="hm-input" name="priority" required>@foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected(old('priority', 'NORMAL') === $priority->value)>{{ str($priority->value)->title() }}</option>@endforeach</select><small>Urgent operational priority is assigned by Horus Support.</small></label>
        <label class="full">Related resource<select class="hm-input" name="linked_resource"><option value="">No linked resource</option>@foreach($resources as $resource)<option value="{{ $resource['type'] }}|{{ $resource['id'] }}" @selected(old('linked_resource') === $resource['type'].'|'.$resource['id'])>{{ $resource['label'] }}</option>@endforeach</select></label>
        <label class="full">Description<textarea class="hm-input" name="description" rows="10" maxlength="10000" required>{{ old('description') }}</textarea><small>Plain text only. Customer-supplied HTML is never rendered.</small></label>
        <label class="full">Optional attachment<input class="hm-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"><small>PDF, JPG, PNG, WebP, TXT, or CSV. Maximum 10 MB.</small></label>
        <div class="full status-row"><button class="hm-button-primary">Create secure ticket</button><a class="text-link" href="{{ route('support.tickets.index') }}">Cancel</a></div>
    </form>
</article>
@endsection
