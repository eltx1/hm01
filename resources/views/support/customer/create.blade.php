@extends('layouts.admin')
@section('title', 'Create Support Ticket')
@section('heading', 'Contact support')
@section('content')
<div class="ui-page">
    <div class="ui-page-intro"><div><h2>How can we help?</h2><p>Tell us what happened. We’ll keep the conversation together in one ticket.</p></div></div>
    <div class="ui-settings-layout">
        <form method="post" action="{{ route('support.tickets.store') }}" enctype="multipart/form-data" class="ui-form-surface">
            @csrf
            <x-form-section number="01" title="About your request" description="Choose the topic that best matches your question.">
                <div class="ui-fields">
                    <x-form-field class="full" name="subject" label="Subject" :value="old('subject')" maxlength="255" required placeholder="A short summary of your question" />
                    <x-form-field name="category" label="Category" as="select" :value="old('category', $categories[0]->value ?? '')" :options="collect($categories)->mapWithKeys(fn ($category) => [$category->value => $category->label()])->all()" required />
                    <x-form-field name="priority" label="Priority" as="select" :value="old('priority', 'NORMAL')" :options="collect($priorities)->mapWithKeys(fn ($priority) => [$priority->value => str($priority->value)->title()->toString()])->all()" required help="Horus Support assesses urgent operational issues." />
                    <x-form-field class="full" name="linked_resource" label="Related website or record" as="select" :value="old('linked_resource')" :options="collect($resources)->mapWithKeys(fn ($resource) => [$resource['type'].'|'.$resource['id'] => $resource['label']])->all()" placeholder="No linked resource" help="Optional. Select a record from your organization to give us context." />
                </div>
            </x-form-section>
            <x-form-section number="02" title="What happened?" description="Include what you expected and the steps that led to the issue.">
                <div class="ui-fields">
                    <x-form-field class="full" name="description" label="Message" as="textarea" :value="old('description')" rows="8" maxlength="10000" required placeholder="Describe your question or issue…" />
                    <x-form-field class="full" name="attachment" label="Attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv" help="Optional. PDF, JPG, PNG, WebP, TXT or CSV, up to 10 MB." />
                </div>
            </x-form-section>
            <x-form-actions :cancel-href="route('support.tickets.index')" help="You can follow up from your support inbox."><button class="hm-button-primary" type="submit" data-submitting-label="Sending…">Send support request</button></x-form-actions>
        </form>
        <aside class="ui-form-aside"><section class="ui-aside-card"><span class="ui-aside-icon"><x-ui-icon name="mail" /></span><h2>Help us help you</h2><ul class="ui-guidance-list"><li>Keep each ticket focused on one issue.</li><li>Include the website or report date where relevant.</li><li>A screenshot can help explain what you are seeing.</li></ul></section></aside>
    </div>
</div>
@endsection
