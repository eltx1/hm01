@extends('layouts.admin')
@section('title', 'Notification preferences')
@section('heading', 'Notification preferences')
@section('content')
<section class="hero"><div><p class="eyebrow">Channels</p><h2>Choose useful updates</h2><p>In-app notifications are durable. Email is delivered by the portable Laravel scheduler without a permanent queue worker.</p></div></section>
<form method="POST" action="{{ route('notifications.preferences.update') }}" class="workspace-section">@csrf @method('PUT')
<div class="table-wrap"><table><thead><tr><th>Category</th><th>In app</th><th>Email</th><th>Policy</th></tr></thead><tbody>
@foreach(\App\Enums\NotificationCategory::cases() as $category) @php($preference=$preferences[$category->value])
<tr><td><strong>{{ $category->label() }}</strong></td><td><input type="checkbox" name="preferences[{{ $category->value }}][in_app]" value="1" @checked($preference['in_app_enabled']) @disabled($category->mandatory())></td><td><input type="checkbox" name="preferences[{{ $category->value }}][email]" value="1" @checked($preference['email_enabled']) @disabled($category->mandatory())></td><td>{{ $category->mandatory() ? 'Required account communication' : 'Optional' }}</td></tr>
@if($category->mandatory())<input type="hidden" name="preferences[{{ $category->value }}][in_app]" value="1"><input type="hidden" name="preferences[{{ $category->value }}][email]" value="1">@endif
@endforeach
</tbody></table></div><button class="hm-button-primary">Save preferences</button></form>
@endsection
