@if(auth()->user()?->hasPermission('video_player.manage'))
<article id="video-inventory" class="workspace-section">
    <p class="eyebrow">This website only · {{ $site->primary_domain }}</p>
    <h3>Video ad type / <span lang="ar" dir="rtl">نوع إعلان الفيديو</span></h3>
    <form class="form-stack" style="max-width:42rem" method="POST" action="{{ route('admin.sites.config.video-inventory', $site) }}">
        @csrf @method('PUT')
        <label for="video-inventory-type"><span>Inventory classification / <span lang="ar" dir="rtl">تصنيف الفيديو</span></span></label>
        <select class="hm-input" id="video-inventory-type" style="min-height:44px" name="video_inventory_type" aria-describedby="video-inventory-help{{ $errors->has('video_inventory_type') ? ' video-inventory-error' : '' }}" aria-invalid="{{ $errors->has('video_inventory_type') ? 'true' : 'false' }}" required>
            <option value="accompanying" @selected(old('video_inventory_type', \App\Services\Inventory\VideoAdFormat::inventoryType($site)) === 'accompanying')>Accompanying content (2)</option>
            <option value="instream" @selected(old('video_inventory_type', \App\Services\Inventory\VideoAdFormat::inventoryType($site)) === 'instream')>Instream (1)</option>
        </select>
        <p id="video-inventory-help" class="muted">Accompanying (2): video supports the page's main content (default). Instream (1): video is the focus of the visit or explicitly requested by the viewer. Match the actual viewing experience. This does not enable placements or change the global audio preference.</p>
        @error('video_inventory_type')<p id="video-inventory-error" class="error" role="alert">{{ $message }}</p>@enderror
        <button class="hm-button-secondary">Save video ad type / <span lang="ar" dir="rtl">حفظ نوع الإعلان</span></button>
    </form>
</article>
@endif
