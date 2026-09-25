@extends('layouts.admin')
@section('title', 'White-label branding')
@section('heading', 'White-label branding')
@section('content')
<div class="ui-page">
    <div class="ui-page-intro"><div><h2>Make your workspace feel like yours</h2><p>Update your display name, support contact and visual identity.</p></div></div>
    <div class="ui-settings-layout">
        <form method="POST" enctype="multipart/form-data" action="{{ auth()->user()->isHorusAdministrator() ? route('admin.organizations.branding.update', $organization) : route('account.branding.update') }}" class="ui-form-surface">
            @csrf @method('PUT')
            <x-form-section title="Workspace details" number="01" description="These details help your team recognize the workspace.">
                <div class="ui-fields">
                    <x-form-field name="dashboard_title" label="Dashboard name" :value="old('dashboard_title', $organization->dashboard_title)" placeholder="Your company or publisher name" />
                    <x-form-field name="support_email" label="Support email" type="email" :value="old('support_email', $organization->support_email)" placeholder="support@example.com" />
                </div>
            </x-form-section>
            <x-form-section title="Visual identity" number="02" description="Use a clear logo that works at small sizes.">
                <div class="ui-fields">
                    <x-form-field name="primary_color" label="Accent color" type="color" :value="old('primary_color', $organization->primary_color ?: '#12499d')" help="Used as your workspace accent." />
                    <x-form-field name="logo" label="Upload a logo" type="file" accept="image/png,image/jpeg,image/webp" help="PNG, JPEG or WebP. Maximum 2 MB." />
                </div>
                @if($organization->logo_path)
                <div class="ui-brand-preview"><img class="brand-preview" src="{{ Storage::disk('public')->url($organization->logo_path) }}" alt="Current organization logo"><label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label></div>
                @endif
            </x-form-section>
            <x-form-actions help="Changes apply to this organization’s workspace."><button class="hm-button-primary" type="submit">Save branding</button></x-form-actions>
        </form>
        <aside class="ui-form-aside"><section class="ui-aside-card"><span class="ui-aside-icon"><x-ui-icon name="shield" /></span><h2>A consistent workspace</h2><p>Your logo and accent sit alongside the existing Horus interface. Use a transparent logo where possible.</p></section></aside>
    </div>
</div>
@endsection
