<form class="form-stack" method="post" action="{{ route($prefix.'.units', $app) }}">@csrf
@if($unit)<input type="hidden" name="id" value="{{ $unit->id }}">@endif
<label>Ad unit name<input class="hm-input" name="name" value="{{ $unit?->name }}" maxlength="255" required></label>
<label>Format<select class="hm-input" name="format">@foreach(config('mobile-apps.formats') as $key => $label)<option value="{{ $key }}" @selected($unit?->format === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Google Ad Manager SDK ad unit path<input class="hm-input" name="ad_unit_path" value="{{ $unit?->ad_unit_path }}" placeholder="/123456/app_banner" required></label>
<small>The same ad unit path can be used for different formats in this app. Each path and format combination can be added once.</small>
<label class="checkbox"><input type="checkbox" name="is_enabled" value="1" @checked(!$unit || $unit->is_enabled)> Enabled</label><button class="hm-button-secondary">Save ad unit</button>
</form>
