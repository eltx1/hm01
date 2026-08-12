from pathlib import Path
import subprocess

loader = Path('public/assets/hm-loader.js')
text = loader.read_text()
text = text.replace(
    "timeoutMs: Math.max(500, Math.min(10000, Number(render.timeoutMs || tag.renderTimeoutMs || 2500))),",
    "timeoutMs: Math.max(0, Math.min(10000, Number(render.timeoutMs || tag.renderTimeoutMs || 2500))),"
)
text = text.replace(
    "}, Math.max(500, Math.min(10000, Number((candidate.tag && directRenderPolicy(candidate.tag).timeoutMs) || 2500))));",
    "}, Math.max(100, Math.min(10000, Number((candidate.tag && directRenderPolicy(candidate.tag).timeoutMs) || 2500))));"
)
old = '''        iframe.srcdoc = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="' + safeCsp + '"></head><body>' + String(isolation.html) + '</body></html>';
        if (entry.element.appendChild) entry.element.appendChild(iframe);
        return new Promise(function (resolve) {
            var settled = false;
            var timeout = directRenderPolicy(tag).timeoutMs;
            var timer = window.setTimeout(function () { if (!settled) { settled = true; resolve(false); } }, timeout);
            iframe.onload = function () {
                if (settled || !canRequestAds(config)) return;
                settled = true; window.clearTimeout(timer); resolve(true);
            };
            iframe.onerror = function () { if (!settled) { settled = true; window.clearTimeout(timer); resolve(false); } };
        });
'''
new = '''        iframe.srcdoc = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="' + safeCsp + '"></head><body>' + String(isolation.html) + '</body></html>';
        return new Promise(function (resolve) {
            var settled = false;
            var timeout = directRenderPolicy(tag).timeoutMs;
            var timer = window.setTimeout(function () { if (!settled) { settled = true; resolve(false); } }, timeout);
            iframe.onload = function () {
                if (settled || !canRequestAds(config)) return;
                settled = true; window.clearTimeout(timer); resolve(true);
            };
            iframe.onerror = function () { if (!settled) { settled = true; window.clearTimeout(timer); resolve(false); } };
            if (entry.element.appendChild) entry.element.appendChild(iframe);
        });
'''
if old not in text:
    raise SystemExit('isolated iframe marker missing')
loader.write_text(text.replace(old, new, 1))

php_files = [
    'app/Services/Demand/AbstractDemandConnector.php',
    'app/Services/Demand/DemandConfigurationBuilder.php',
    'app/Services/Demand/DirectTagRecipeParser.php',
    'app/Services/Demand/MgidConnector.php',
    'app/Services/Demand/OutbrainConnector.php',
    'app/Services/Demand/TaboolaConnector.php',
    'app/Services/Inventory/SiteConfigurationBuilder.php',
]
for file in php_files:
    subprocess.run(['php', '-l', file], check=True)
subprocess.run(['node', '--check', 'public/assets/hm-loader.js'], check=True)

Path('scripts/task17_materialize.py').unlink(missing_ok=True)
Path('scripts/task17_finalize.py').unlink(missing_ok=True)
Path('.github/workflows/task17-materialize.yml').unlink(missing_ok=True)
