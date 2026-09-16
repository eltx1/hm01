const siteKey = process.argv[2] || 'hm_ircll0vkqq54jvt9gz85mrh2';
if (!/^[A-Za-z0-9_-]{3,64}$/.test(siteKey)) throw new Error('invalid site key');
const headers = { 'user-agent': 'Mozilla/5.0 Horus-Live-Inventory/1.1', accept: 'application/json' };

const manifestUrl = `https://cdn.horusmedia.net/configs/${encodeURIComponent(siteKey)}/manifest.json`;
const manifestResponse = await fetch(manifestUrl, { headers });
if (!manifestResponse.ok) throw new Error(`manifest HTTP ${manifestResponse.status}`);
const manifest = await manifestResponse.json();
const production = manifest?.environments?.production;
if (!production?.path) throw new Error('production manifest path missing');

const configUrl = new URL(production.path, 'https://cdn.horusmedia.net').href;
const configResponse = await fetch(configUrl, { headers });
if (!configResponse.ok) throw new Error(`production config HTTP ${configResponse.status}`);
const config = await configResponse.json();

const placements = (config.placements || []).map((p) => ({
  code: p.code ?? null,
  name: p.name ?? null,
  type: p.type ?? null,
  renderer: p.renderer ?? null,
  eligible: p.eligible ?? null,
  sizes: p.sizes ?? null,
  adUnitPath: p.adUnitPath ?? null,
  mount: p.mount ?? null,
  formatSettings: p.formatSettings ?? null,
}));

const direct = Object.entries(config.directDemand?.placements || {}).map(([code, p]) => ({
  code,
  enabled: p?.enabled ?? null,
  candidates: (p?.candidates || []).map((c) => ({
    network: c.network ?? null,
    mode: c.mode ?? null,
    priority: c.priority ?? null,
    gamManaged: c.gamManaged ?? null,
    publicPlacementId: c.tag?.publicPlacementId ?? null,
    format: c.tag?.format ?? null,
    allowedSizes: c.tag?.render?.allowedSizes ?? null,
    adUnitPath: c.tag?.container?.attributes?.['data-hm-gpt-ad-unit-path'] ?? null,
    containerId: c.tag?.container?.attributes?.['data-hm-gpt-inner-id'] ?? null,
    runtime: c.tag?.scripts?.[0]?.url ?? null,
  })),
}));

console.log('LIVE_CONFIG_SUMMARY=' + JSON.stringify({
  siteKey,
  configVersion: config.configVersion ?? null,
  status: config.status ?? null,
  servingMode: config.servingMode ?? null,
  engines: config.engines ?? null,
  placements,
  directDemandEnabled: config.directDemandEnabled ?? null,
  direct,
}, null, 2));
