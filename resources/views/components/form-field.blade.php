@props(['name', 'label', 'value' => '', 'as' => 'input', 'type' => 'text', 'help' => null, 'required' => false, 'options' => [], 'placeholder' => null])
@php
    $id = $attributes->get('id', 'field-'.str($name)->slug());
    $invalid = $errors->has($name);
    $description = trim(($help ? $id.'-help ' : '').($invalid ? $id.'-error' : ''));
    $control = $attributes->except(['id', 'class'])->merge(['class' => 'hm-input', 'id' => $id, 'name' => $name, 'aria-invalid' => $invalid ? 'true' : 'false']);
    if ($description) $control = $control->merge(['aria-describedby' => $description]);
@endphp
<div class="ui-field {{ $attributes->get('class') }}">
    <label class="field-label" for="{{ $id }}">{{ $label }} @if($required)<span class="required-marker" aria-hidden="true">*</span>@endif</label>
    @if($as === 'select')
        <select {{ $control }} @required($required)>
            @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
            @foreach($options as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
        </select>
    @elseif($as === 'textarea')
        <textarea {{ $control }} @required($required) @if($placeholder)placeholder="{{ $placeholder }}"@endif>{{ $value }}</textarea>
    @else
        <input {{ $control }} type="{{ $type }}" @if($type !== 'file')value="{{ $value }}"@endif @required($required) @if($placeholder)placeholder="{{ $placeholder }}"@endif>
    @endif
    @if($help)<p id="{{ $id }}-help" class="field-help">{{ $help }}</p>@endif
    @if($invalid)<p id="{{ $id }}-error" class="field-error" role="alert">{{ $errors->first($name) }}</p>@endif
</div>
