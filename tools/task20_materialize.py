from pathlib import Path
import json

# Demand network enum.
p = Path('app/Enums/DemandNetworkCode.php')
s = p.read_text()
if "case ExoClick = 'EXOCLICK';" not in s:
    s = s.replace("    case Outbrain = 'OUTBRAIN';\n", "    case Outbrain = 'OUTBRAIN';\n    case ExoClick = 'EXOCLICK';\n")
p.write_text(s)

# Provider origins and direct fallback order. Only current officially documented
# ExoClick hosts are seeded; operators may review additional provider-issued
# origins through the existing Admin network policy workflow.
p = Path('config/demand.php')
s = p.read_text()
s = s.replace("'fallback_order' => ['GAM', 'MGID', 'TABOOLA', 'SPEAKOL', 'OUTBRAIN', 'HOUSE']", "'fallback_order' => ['GAM', 'MGID', 'TABOOLA', 'SPEAKOL', 'OUTBRAIN', 'EXOCLICK', 'HOUSE']")
if "'EXOCLICK' =>" not in s:
    s = s.replace("        'OUTBRAIN' => ['https://widgets.outbrain.com', 'https://odb.outbrain.com'],\n", "        'OUTBRAIN' => ['https://widgets.outbrain.com', 'https://odb.outbrain.com'],\n        'EXOCLICK' => ['https://a.magsrv.com', 'https://a.pemsrv.com'],\n")
p.write_text(s)

# Seed ExoClick as Direct JS only; do not claim unimplemented GAM/API capability.
p = Path('database/seeders/DemandNetworkSeeder.php')
s = p.read_text()
if 'use App\\Services\\Demand\\ExoClickConnector;' not in s:
    s = s.replace('use App\\Services\\Demand\\ConfiguredDemandConnector;\n', 'use App\\Services\\Demand\\ConfiguredDemandConnector;\nuse App\\Services\\Demand\\ExoClickConnector;\n')
if 'DemandNetworkCode::ExoClick->value' not in s:
    anchor = """            DemandNetworkCode::Outbrain->value => [
                'name' => 'Outbrain',
                'connector_class' => OutbrainConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => true,
                'supports_gam_line_item' => true,
                'supports_api' => true,
                'capabilities' => ['site_mapping', 'placement_mapping', 'ads_txt', 'api_placeholder', 'csv_reports'],
            ],
"""
    addition = anchor + """            DemandNetworkCode::ExoClick->value => [
                'name' => 'ExoClick',
                'connector_class' => ExoClickConnector::class,
                'default_integration_mode' => DemandIntegrationMode::DirectJs,
                'supports_direct_js' => true,
                'supports_gam_creative' => false,
                'supports_gam_line_item' => false,
                'supports_api' => false,
                'capabilities' => ['placement_mapping', 'configured_tag', 'csv_reports'],
            ],
"""
    if anchor not in s:
        raise SystemExit('DemandNetworkSeeder anchor missing')
    s = s.replace(anchor, addition, 1)
p.write_text(s)

# Parser recognizes ExoClick's documented data-zoneid spelling.
p = Path('app/Services/Demand/DirectTagRecipeParser.php')
s = p.read_text()
s = s.replace("foreach (['id', 'data-widget-id', 'data-zone-id', 'data-placement-id'] as $key)", "foreach (['id', 'data-widget-id', 'data-zone-id', 'data-zoneid', 'data-placement-id'] as $key)")
p.write_text(s)

# Optional OneTag bidder in the pinned Prebid build. No publisher ID is seeded.
p = Path('resources/prebid/horus-build.json')
data = json.loads(p.read_text())
mods = data.get('modules', [])
if 'onetagBidAdapter' not in mods:
    insert_at = next((i+1 for i,m in enumerate(mods) if m == 'tripleliftBidAdapter'), len(mods))
    mods.insert(insert_at, 'onetagBidAdapter')
data['modules'] = mods
p.write_text(json.dumps(data, indent=2) + '\n')

p = Path('database/seeders/PrebidSeeder.php')
s = p.read_text()
if "'code' => 'onetag'" not in s:
    anchor = "            ['code' => 'triplelift', 'display_name' => 'TripleLift', 'module_code' => 'tripleliftBidAdapter', 'publisher_parameter' => null, 'placement_parameter' => 'inventoryCode', 'required_public_parameters' => ['inventoryCode'], 'optional_public_parameters' => ['floor'], 'supported_media_types' => ['banner', 'native', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/triplelift.html'],\n"
    line = "            ['code' => 'onetag', 'display_name' => 'OneTag', 'module_code' => 'onetagBidAdapter', 'publisher_parameter' => 'pubId', 'placement_parameter' => null, 'required_public_parameters' => ['pubId'], 'optional_public_parameters' => ['ext'], 'supported_media_types' => ['banner', 'native', 'video'], 'supported_sizes' => [], 'documentation_url' => 'https://docs.prebid.org/dev-docs/bidders/onetag.html'],\n"
    if anchor not in s:
        raise SystemExit('PrebidSeeder adapter anchor missing')
    s = s.replace(anchor, anchor + line, 1)
p.write_text(s)

# Loader: trusted ExoClick queue action and failed-container cleanup to prevent a
# late provider render racing a succeeding Direct candidate.
p = Path('public/assets/hm-loader.js')
s = p.read_text()
if "type === 'EXOCLICK_SERVE'" not in s:
    anchor = """        if (type === 'OUTBRAIN_RESEARCH') {
            if (!window.OBR || !window.OBR.extern || typeof window.OBR.extern.researchWidget !== 'function') return false;
            window.OBR.extern.researchWidget();
            return true;
        }
"""
    replacement = anchor + """        if (type === 'EXOCLICK_SERVE') {
            window.AdProvider = window.AdProvider || [];
            if (!window.AdProvider || typeof window.AdProvider.push !== 'function') return false;
            window.AdProvider.push({ serve: {} });
            return true;
        }
"""
    if anchor not in s:
        raise SystemExit('Loader init anchor missing')
    s = s.replace(anchor, replacement, 1)

old = """                function failed(reason) {
                    if (settled) return;
                    settled = true;
                    entry.element.setAttribute('data-hm-native-last-error', String(reason || 'no-fill'));
                    entry.element.setAttribute('data-hm-direct-last-error', String(reason || 'no-fill'));
                    log(config, 'Direct Demand candidate failed', candidate.network, reason);
                    tryCandidate(index + 1);
                }
"""
new = """                function failed(reason) {
                    if (settled) return;
                    settled = true;
                    if (container && container.parentNode && container.parentNode.removeChild) {
                        container.parentNode.removeChild(container);
                    }
                    entry.element.setAttribute('data-hm-native-last-error', String(reason || 'no-fill'));
                    entry.element.setAttribute('data-hm-direct-last-error', String(reason || 'no-fill'));
                    log(config, 'Direct Demand candidate failed', candidate.network, reason);
                    tryCandidate(index + 1);
                }
"""
if old not in s:
    raise SystemExit('Loader failed-candidate anchor missing')
s = s.replace(old, new, 1)
p.write_text(s)
