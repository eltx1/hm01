from pathlib import Path


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'marker not found in {path}: {old[:120]!r}')
    p.write_text(text.replace(old, new, 1))

# ----- AbstractDemandConnector: structured recipes + safe parser + isolated custom mode -----
path = 'app/Services/Demand/AbstractDemandConnector.php'
old = '''    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $scriptUrl = trim((string) ($configuration['script_url'] ?? ''));

        if ($scriptUrl === '') {
            throw new RuntimeException($this->code().' direct delivery requires an approved script_url.');
        }

        $this->assertAllowedScriptUrl($scriptUrl);

        $containerId = (string) (
            $configuration['container_id']
            ?? $widget?->widget_code
            ?? $placement->placement_code
            ?? 'hm-native-'.$placement->id
        );

        return [
            'scriptUrl' => $scriptUrl,
            'containerId' => preg_replace('/[^A-Za-z0-9_:-]/', '-', $containerId),
            'containerClass' => (string) ($configuration['container_class'] ?? 'hm-native-container'),
            'attributes' => $this->publicAttributes((array) ($configuration['attributes'] ?? [])),
            'renderTimeoutMs' => max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500)))),
            'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
        ];
    }
'''
new = '''    public function parseDirectTag(string $tag): array
    {
        $parsed = (new DirectTagRecipeParser())->parse($tag);
        $warnings = (array) ($parsed['securityWarnings'] ?? []);

        if ((bool) ($parsed['containsSensitiveMaterial'] ?? false)) {
            return $this->tagReview($parsed, null, array_values(array_unique($warnings)));
        }

        $scripts = (array) ($parsed['detectedScripts'] ?? []);
        $containers = (array) ($parsed['detectedContainers'] ?? []);
        foreach ($scripts as $script) {
            try {
                $this->assertAllowedScriptUrl((string) ($script['url'] ?? ''));
            } catch (Throwable $exception) {
                $warnings[] = $exception->getMessage();
            }
        }
        if (count($containers) !== 1) {
            $warnings[] = 'A structured Direct Demand tag must resolve to exactly one render container.';
        }

        try {
            $initialization = $this->trustedInitializationForParsedTag($parsed);
        } catch (Throwable $exception) {
            $initialization = ['type' => 'NONE', 'parameters' => []];
            $warnings[] = $exception->getMessage();
        }

        if ((array) ($parsed['inlineCode'] ?? []) !== [] && ($initialization['type'] ?? 'NONE') === 'NONE') {
            $warnings[] = 'Unsupported inline JavaScript cannot execute in structured Direct Demand mode.';
        }

        $recipe = null;
        if ($scripts !== [] && count($containers) === 1 && $warnings === []) {
            $container = $containers[0];
            $recipe = [
                'recipeVersion' => 1,
                'executionMode' => 'STRUCTURED',
                'format' => 'DISPLAY',
                'scripts' => collect($scripts)->map(fn (array $script): array => [
                    'url' => $script['url'],
                    'async' => (bool) ($script['async'] ?? true),
                    'defer' => (bool) ($script['defer'] ?? false),
                    'dedupeKey' => hash('sha256', (string) $script['url']),
                    'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
                ])->values()->all(),
                'container' => [
                    'element' => $container['element'] ?? 'div',
                    'id' => $container['id'] ?? null,
                    'class' => $container['class'] ?? null,
                    'attributes' => $this->publicAttributes((array) ($container['attributes'] ?? [])),
                ],
                'publicPlacementId' => data_get($parsed, 'detectedPublicIdentifiers.0'),
                'initialization' => $initialization,
                'render' => [
                    'timeoutMs' => (int) config('demand.direct_render_timeout_ms', 2500),
                    'successSelector' => null,
                    'assumeLoadedIsSuccess' => false,
                    'allowedFormats' => [],
                    'allowedSizes' => [],
                ],
                'isolation' => null,
            ];
        }

        return $this->tagReview($parsed, $recipe, array_values(array_unique($warnings)));
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);

        if (is_array($configuration['direct_recipe'] ?? null)) {
            return $this->normalizeDirectRecipe((array) $configuration['direct_recipe'], $placement);
        }

        if ($this->code() === 'CUSTOM_THIRD_PARTY_TAG' && $widget?->direct_tag_template) {
            return $this->isolatedThirdPartyRecipe($widget->direct_tag_template, $configuration, $placement);
        }

        $configuredScripts = (array) ($configuration['scripts'] ?? []);
        $scriptUrl = trim((string) ($configuration['script_url'] ?? ''));
        if ($configuredScripts === [] && $scriptUrl !== '') {
            $configuredScripts[] = [
                'url' => $scriptUrl,
                'async' => (bool) ($configuration['script_async'] ?? true),
                'defer' => (bool) ($configuration['script_defer'] ?? false),
                'attributes' => (array) ($configuration['script_attributes'] ?? []),
            ];
        }
        if ($configuredScripts === []) {
            throw new RuntimeException($this->code().' direct delivery requires at least one approved external script.');
        }

        $scripts = [];
        foreach ($configuredScripts as $script) {
            $script = is_string($script) ? ['url' => $script] : (array) $script;
            $url = trim((string) ($script['url'] ?? ''));
            $this->assertAllowedScriptUrl($url);
            $scripts[] = [
                'url' => $url,
                'async' => (bool) ($script['async'] ?? true),
                'defer' => (bool) ($script['defer'] ?? false),
                'dedupeKey' => preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) ($script['dedupe_key'] ?? hash('sha256', $url))),
                'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
            ];
        }

        $containerId = (string) (
            $configuration['container_id']
            ?? $widget?->widget_code
            ?? $placement->placement_code
            ?? 'hm-direct-'.$placement->id
        );
        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', $containerId);
        $containerClass = (string) ($configuration['container_class'] ?? 'hm-direct-demand-container');
        $attributes = $this->publicAttributes((array) ($configuration['attributes'] ?? []));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $sizes = $placement->placement->sizes
            ->where('is_active', true)
            ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => [(int) $size->width, (int) $size->height])
            ->values()->all();

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => $format,
            'scripts' => $scripts,
            'container' => [
                'element' => strtolower((string) ($configuration['container_element'] ?? 'div')),
                'id' => $containerId,
                'class' => $containerClass,
                'attributes' => $attributes,
            ],
            'publicPlacementId' => (string) ($configuration['public_placement_id'] ?? $widget?->remote_widget_id ?? $placement->remote_placement_id ?? $placement->placement_code ?? $containerId),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
                'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
                'allowedFormats' => [$format],
                'allowedSizes' => $sizes,
            ],
            'isolation' => null,

            // Legacy flattened tag fields are retained during the schema-v4 rollout.
            'scriptUrl' => $scripts[0]['url'],
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $attributes,
            'renderTimeoutMs' => $timeout,
            'successSelector' => isset($configuration['success_selector']) ? (string) $configuration['success_selector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($configuration['assume_loaded_is_success'] ?? false),
        ];
    }

    /** @return array{type:string,parameters:array<string,mixed>} */
    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        if ((array) ($parsed['inlineCode'] ?? []) !== []) {
            throw new RuntimeException('Inline JavaScript is not a trusted recipe for this provider.');
        }

        return ['type' => 'NONE', 'parameters' => []];
    }

    /** @return array<int, string> */
    protected function allowedInitializationTypes(): array
    {
        return ['NONE'];
    }

    private function normalizeDirectRecipe(array $recipe, DemandPlacement $placement): array
    {
        $encoded = json_encode($recipe, JSON_UNESCAPED_SLASHES) ?: '';
        if (preg_match('/(?:env|file):|secret|password|credential|authorization|api[_-]?key|access[_-]?token|private[_-]?key/i', $encoded)) {
            throw new RuntimeException('Direct Demand recipe contains private or credential material.');
        }
        if (strtoupper((string) ($recipe['executionMode'] ?? 'STRUCTURED')) !== 'STRUCTURED') {
            throw new RuntimeException('Only reviewed structured recipes may be stored in direct_recipe.');
        }
        $scripts = [];
        foreach ((array) ($recipe['scripts'] ?? []) as $script) {
            $script = (array) $script;
            $url = trim((string) ($script['url'] ?? ''));
            $this->assertAllowedScriptUrl($url);
            $scripts[] = [
                'url' => $url,
                'async' => (bool) ($script['async'] ?? true),
                'defer' => (bool) ($script['defer'] ?? false),
                'dedupeKey' => preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string) ($script['dedupeKey'] ?? hash('sha256', $url))),
                'attributes' => $this->publicAttributes((array) ($script['attributes'] ?? [])),
            ];
        }
        if ($scripts === []) throw new RuntimeException('Structured Direct Demand recipe has no approved script.');
        $initialization = (array) ($recipe['initialization'] ?? ['type' => 'NONE', 'parameters' => []]);
        $type = strtoupper((string) ($initialization['type'] ?? 'NONE'));
        if (! in_array($type, $this->allowedInitializationTypes(), true)) {
            throw new RuntimeException('The configured initialization action is not trusted by this provider connector.');
        }
        $container = (array) ($recipe['container'] ?? []);
        $containerId = preg_replace('/[^A-Za-z0-9_:-]/', '-', (string) ($container['id'] ?? 'hm-direct-'.$placement->id));
        $containerClass = preg_replace('/[^A-Za-z0-9_\\- ]/', '-', (string) ($container['class'] ?? 'hm-direct-demand-container'));
        $attrs = $this->publicAttributes((array) ($container['attributes'] ?? []));
        $render = (array) ($recipe['render'] ?? []);
        $timeout = max(500, min(10000, (int) ($render['timeoutMs'] ?? config('demand.direct_render_timeout_ms', 2500))));
        $format = strtoupper((string) ($recipe['format'] ?? $placement->placement->type->value));

        return [
            'recipeVersion' => 1,
            'executionMode' => 'STRUCTURED',
            'format' => $format,
            'scripts' => $scripts,
            'container' => [
                'element' => strtolower((string) ($container['element'] ?? 'div')),
                'id' => $containerId,
                'class' => $containerClass,
                'attributes' => $attrs,
            ],
            'publicPlacementId' => (string) ($recipe['publicPlacementId'] ?? $containerId),
            'initialization' => [
                'type' => $type,
                'parameters' => collect((array) ($initialization['parameters'] ?? []))
                    ->filter(fn ($value, $key) => is_scalar($value) && ! preg_match('/secret|token|password|credential|authorization|api[_-]?key|private/i', (string) $key))
                    ->all(),
            ],
            'render' => [
                'timeoutMs' => $timeout,
                'successSelector' => isset($render['successSelector']) ? (string) $render['successSelector'] : null,
                'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? false),
                'allowedFormats' => (array) ($render['allowedFormats'] ?? [$format]),
                'allowedSizes' => (array) ($render['allowedSizes'] ?? []),
            ],
            'isolation' => null,
            'scriptUrl' => $scripts[0]['url'],
            'containerId' => $containerId,
            'containerClass' => $containerClass,
            'attributes' => $attrs,
            'renderTimeoutMs' => $timeout,
            'successSelector' => isset($render['successSelector']) ? (string) $render['successSelector'] : null,
            'assumeLoadedIsSuccess' => (bool) ($render['assumeLoadedIsSuccess'] ?? false),
        ];
    }

    private function isolatedThirdPartyRecipe(string $html, array $configuration, DemandPlacement $placement): array
    {
        if (strlen($html) > 60_000 || preg_match('/(?:env|file):|secret|password|credential|authorization|api[_-]?key|access[_-]?token|private[_-]?key/i', $html)) {
            throw new RuntimeException('Custom third-party tag contains private or credential material.');
        }
        $this->assertSafeThirdPartyHtml($html);
        $origins = collect((array) ($configuration['isolation_allowed_origins'] ?? []))
            ->map(fn ($origin) => strtolower(rtrim((string) $origin, '/')))
            ->filter(function (string $origin): bool {
                if (! filter_var($origin, FILTER_VALIDATE_URL) || strtolower((string) parse_url($origin, PHP_URL_SCHEME)) !== 'https') return false;
                $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
                return $host !== 'app.horusmedia.net' && ! str_ends_with($host, '.app.horusmedia.net');
            })->unique()->values();
        if ($origins->isEmpty()) {
            throw new RuntimeException('Custom isolated tags require explicit provider CSP origins.');
        }
        $originList = $origins->implode(' ');
        $csp = "default-src 'none'; script-src 'unsafe-inline' {$originList}; connect-src {$originList}; img-src https: data:; style-src 'unsafe-inline'; frame-src {$originList};";
        $format = strtoupper((string) ($configuration['format'] ?? $placement->placement->type->value));
        $timeout = max(500, min(10000, (int) ($configuration['render_timeout_ms'] ?? config('demand.direct_render_timeout_ms', 2500))));

        return [
            'recipeVersion' => 1,
            'executionMode' => 'ISOLATED_IFRAME',
            'format' => $format,
            'scripts' => [],
            'container' => ['element' => 'div', 'id' => 'hm-isolated-'.$placement->id, 'class' => 'hm-direct-demand-isolated', 'attributes' => []],
            'publicPlacementId' => (string) ($placement->remote_placement_id ?? $placement->placement_code ?? $placement->id),
            'initialization' => ['type' => 'NONE', 'parameters' => []],
            'render' => ['timeoutMs' => $timeout, 'successSelector' => null, 'assumeLoadedIsSuccess' => true, 'allowedFormats' => [$format], 'allowedSizes' => []],
            'isolation' => ['html' => $html, 'csp' => $csp, 'sandbox' => ['allow-scripts']],
            'scriptUrl' => '',
            'containerId' => 'hm-isolated-'.$placement->id,
            'containerClass' => 'hm-direct-demand-isolated',
            'attributes' => [],
            'renderTimeoutMs' => $timeout,
            'successSelector' => null,
            'assumeLoadedIsSuccess' => true,
        ];
    }

    private function tagReview(array $parsed, ?array $recipe, array $warnings): array
    {
        return [
            'safe' => $recipe !== null && $warnings === [],
            'recipe' => $recipe,
            'detectedScripts' => $parsed['detectedScripts'] ?? [],
            'detectedContainers' => $parsed['detectedContainers'] ?? [],
            'detectedPublicIdentifiers' => $parsed['detectedPublicIdentifiers'] ?? [],
            'detectedAttributes' => data_get($parsed, 'detectedContainers.0.attributes', []),
            'unsupportedInlineCode' => $parsed['unsupportedInlineCode'] ?? [],
            'securityWarnings' => $warnings,
        ];
    }
'''
replace_once(path, old, new)

# ----- SiteConfigurationBuilder: additive schema v4 / directDemand aliases -----
path = 'app/Services/Inventory/SiteConfigurationBuilder.php'
replace_once(path,
"""            // v3 is additive. All schema-v2 fields below remain present so old
            // deployed Loaders and immutable rollback targets continue to work.
            'schemaVersion' => 3,
""",
"""            // v4 adds Direct Demand terminology/recipe metadata without removing
            // schema-v3 or legacy nativeDemand fields used by deployed Loaders.
            'schemaVersion' => 4,
""")
replace_once(path,
"""                'directJsDisabled' => $directJsDisabled,
                'nativeDemandDisabled' => $legacyNativeDisabled,
""",
"""                'directJsDisabled' => $directJsDisabled,
                'directDemandDisabled' => $directJsDisabled,
                'nativeDemandDisabled' => $legacyNativeDisabled,
""")
replace_once(path,
"""            'nativeDemandEnabled' => $legacyNativeEnabled,
            'nativeDemand' => array_replace($publicNative, ['enabled' => $legacyNativeEnabled]),
""",
"""            'directDemandEnabled' => $legacyNativeEnabled,
            'directDemand' => array_replace($publicNative, ['enabled' => $legacyNativeEnabled]),
            // Rollout compatibility aliases; do not remove until old Loader/config
            // rollback targets have aged out.
            'nativeDemandEnabled' => $legacyNativeEnabled,
            'nativeDemand' => array_replace($publicNative, ['enabled' => $legacyNativeEnabled]),
""")

# ----- Loader: Direct Demand alias, script dedupe, trusted init, isolated iframe, SPA-safe IDs -----
path = 'public/assets/hm-loader.js'
replace_once(path,
"""        nativeAttempts: {},
        nativeRendered: {},
        standaloneEntries: {},
""",
"""        nativeAttempts: {},
        nativeRendered: {},
        directScripts: {},
        directTaboolaFlushScheduled: false,
        elementSequence: 0,
        standaloneEntries: {},
""")
replace_once(path,
"""        element.id = 'hm-' + safeSite + '-' + safePlacement + '-' + Object.keys(state.slots).length;
""",
"""        state.elementSequence = Number(state.elementSequence || 0) + 1;
        element.id = 'hm-' + safeSite + '-' + safePlacement + '-' + state.elementSequence;
""")
replace_once(path,
"""    function nativeDefinition(config, code) {
        var native = config.nativeDemand || {};
        return native.enabled && native.placements ? native.placements[code] || null : null;
    }
""",
"""    function nativeDefinition(config, code) {
        var direct = config.directDemand || config.nativeDemand || {};
        return direct.enabled && direct.placements ? direct.placements[code] || null : null;
    }
""")
old = '''    function candidateRank(config, candidate) {
        var fallback = config.nativeDemand && config.nativeDemand.fallbackOrder || [];
        var index = fallback.indexOf(candidate.network);
        return Number(candidate.priority || 1000) * 100 + (index === -1 ? 99 : index);
    }

    function directCandidates(config, entry) {
        var candidates = entry.native && Array.isArray(entry.native.candidates) ? entry.native.candidates.slice() : [];
        return candidates.filter(function (candidate) {
            return candidate && !candidate.gamManaged && candidate.tag && candidate.tag.scriptUrl;
        }).sort(function (left, right) {
            return candidateRank(config, left) - candidateRank(config, right);
        });
    }

    function setCandidateAttributes(script, attributes) {
        if (!attributes) return;
        Object.keys(attributes).forEach(function (key) {
            if (/^[A-Za-z_:][-A-Za-z0-9_:.]*$/.test(key)) script.setAttribute(key, String(attributes[key]));
        });
    }

    function nativeContainer(entry, candidate) {
        var tag = candidate.tag || {};
        var container = document.createElement('div');
        container.id = String(tag.containerId || (entry.element.id + '-native')).replace(/[^A-Za-z0-9_:-]/g, '-');
        container.className = String(tag.containerClass || 'hm-native-container');
        container.setAttribute('data-hm-native-network', String(candidate.network || 'CUSTOM'));
        if (entry.element.appendChild) entry.element.appendChild(container);
        return container;
    }

    function nativeRendered(container, tag) {
        if (tag.successSelector && document.querySelector) {
            try {
                if (document.querySelector(tag.successSelector)) return true;
            } catch (error) {
                return false;
            }
        }
        if (tag.assumeLoadedIsSuccess) return true;
        if (container && container.childNodes && container.childNodes.length) return true;
        return Boolean(container && typeof container.innerHTML === 'string' && container.innerHTML.replace(/\\s/g, '') !== '');
    }

    function renderHouse(config, entry) {
        var house = entry.native && entry.native.house;
        if (!house || !house.html) {
            entry.element.setAttribute('data-hm-native', 'exhausted');
            entry.element.setAttribute('data-hm-status', 'empty');
            return false;
        }
        if (typeof entry.element.innerHTML === 'string') entry.element.innerHTML = house.html;
        entry.element.setAttribute('data-hm-native', 'HOUSE');
        entry.element.setAttribute('data-hm-status', 'rendered');
        state.nativeRendered[entry.element.id] = 'HOUSE';
        log(config, 'Native fallback rendered house content', entry.placement.code);
        return true;
    }

    function runNativeFallback(config, entry) {
        if (!canRequestAds(config) || !entry || !entry.native || !entry.native.enabled) return Promise.resolve(false);
        var key = entry.element.id || ensureElementId(entry.element, config, entry.placement);
        if (state.nativeRendered[key]) return Promise.resolve(true);
        if (state.nativeAttempts[key]) return state.nativeAttempts[key];

        var candidates = directCandidates(config, entry);
        state.nativeAttempts[key] = new Promise(function (resolve) {
            function tryCandidate(index) {
                if (!canRequestAds(config)) { resolve(false); return; }
                if (index >= candidates.length) {
                    resolve(renderHouse(config, entry));
                    return;
                }

                var candidate = candidates[index];
                var tag = candidate.tag || {};
                var container = nativeContainer(entry, candidate);
                var script = document.createElement('script');
                script.async = true;
                script.src = tag.scriptUrl;
                script.setAttribute('data-hm-native-script', String(candidate.network || 'CUSTOM'));
                setCandidateAttributes(script, tag.attributes || {});
                var settled = false;

                function failed(reason) {
                    if (settled) return;
                    settled = true;
                    entry.element.setAttribute('data-hm-native-last-error', String(reason || 'no-fill'));
                    log(config, 'Native candidate failed', candidate.network, reason);
                    tryCandidate(index + 1);
                }

                script.onerror = function () { failed('script-error'); };
                script.onload = function () {
                    var timeout = Math.max(0, Number(tag.renderTimeoutMs || 1500));
                    window.setTimeout(function () {
                        if (settled) return;
                        if (!nativeRendered(container, tag)) {
                            failed('no-render');
                            return;
                        }
                        settled = true;
                        state.nativeRendered[key] = candidate.network;
                        entry.element.setAttribute('data-hm-native', String(candidate.network));
                        entry.element.setAttribute('data-hm-status', 'rendered');
                        log(config, 'Native candidate rendered', candidate.network, entry.placement.code);
                        resolve(true);
                    }, timeout);
                };

                try {
                    (document.head || document.documentElement).appendChild(script);
                } catch (error) {
                    failed(error && error.message || 'injection-error');
                }
            }

            tryCandidate(0);
        }).finally(function () {
            delete state.nativeAttempts[key];
        });

        return state.nativeAttempts[key];
    }
'''
new = '''    function directDemandConfig(config) {
        return config.directDemand || config.nativeDemand || {};
    }

    function candidateRank(config, candidate) {
        var fallback = directDemandConfig(config).fallbackOrder || [];
        var index = fallback.indexOf(candidate.network);
        return Number(candidate.priority || 1000) * 100 + (index === -1 ? 99 : index);
    }

    function directCandidates(config, entry) {
        var candidates = entry.native && Array.isArray(entry.native.candidates) ? entry.native.candidates.slice() : [];
        return candidates.filter(function (candidate) {
            if (!candidate || candidate.gamManaged || !candidate.tag) return false;
            var tag = candidate.tag;
            if (String(tag.executionMode || 'STRUCTURED') === 'ISOLATED_IFRAME') return Boolean(tag.isolation && tag.isolation.html && tag.isolation.csp);
            return Boolean((Array.isArray(tag.scripts) && tag.scripts.length) || tag.scriptUrl);
        }).sort(function (left, right) {
            return candidateRank(config, left) - candidateRank(config, right);
        });
    }

    function setCandidateAttributes(target, attributes) {
        if (!attributes || !target || !target.setAttribute) return;
        Object.keys(attributes).forEach(function (key) {
            if (/^(?:data|aria)-[A-Za-z0-9_.:-]+$/.test(key)) target.setAttribute(key, String(attributes[key]));
        });
    }

    function directContainer(entry, candidate) {
        var tag = candidate.tag || {};
        var recipe = tag.container || {};
        var elementName = String(recipe.element || 'div').toLowerCase();
        if (['div', 'span', 'aside', 'section'].indexOf(elementName) === -1) elementName = 'div';
        var container = document.createElement(elementName);
        container.id = String(recipe.id || tag.containerId || (entry.element.id + '-direct')).replace(/[^A-Za-z0-9_:-]/g, '-');
        container.className = String(recipe.class || tag.containerClass || 'hm-direct-demand-container');
        container.setAttribute('data-hm-direct-network', String(candidate.network || 'CUSTOM'));
        container.setAttribute('data-hm-native-network', String(candidate.network || 'CUSTOM'));
        setCandidateAttributes(container, recipe.attributes || tag.attributes || {});
        if (entry.element.appendChild) entry.element.appendChild(container);
        return container;
    }

    function nativeContainer(entry, candidate) { return directContainer(entry, candidate); }

    function directRenderPolicy(tag) {
        var render = tag && tag.render || {};
        return {
            timeoutMs: Math.max(500, Math.min(10000, Number(render.timeoutMs || tag.renderTimeoutMs || 2500))),
            successSelector: render.successSelector || tag.successSelector || null,
            assumeLoadedIsSuccess: Boolean(render.assumeLoadedIsSuccess || tag.assumeLoadedIsSuccess)
        };
    }

    function nativeRendered(container, tag) {
        var policy = directRenderPolicy(tag || {});
        if (policy.successSelector && document.querySelector) {
            try {
                if (document.querySelector(policy.successSelector)) return true;
            } catch (error) {
                return false;
            }
        }
        if (policy.assumeLoadedIsSuccess) return true;
        if (container && container.childNodes && container.childNodes.length) return true;
        return Boolean(container && typeof container.innerHTML === 'string' && container.innerHTML.replace(/\\s/g, '') !== '');
    }

    function directScriptSpecs(tag) {
        if (Array.isArray(tag.scripts) && tag.scripts.length) return tag.scripts.slice();
        return tag.scriptUrl ? [{ url: tag.scriptUrl, async: true, defer: false, attributes: tag.attributes || {} }] : [];
    }

    function loadDirectScript(config, candidate, spec) {
        spec = spec || {};
        var url = String(spec.url || '');
        if (!url) return Promise.reject(new Error('missing-script-url'));
        try {
            var parsed = new URL(url, window.location.href);
            if (parsed.protocol !== 'https:' || parsed.hostname === 'app.horusmedia.net') return Promise.reject(new Error('unsafe-script-url'));
        } catch (error) { return Promise.reject(new Error('invalid-script-url')); }
        var key = String(spec.dedupeKey || url);
        if (state.directScripts[key]) return state.directScripts[key];

        state.directScripts[key] = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.async = spec.async !== false;
            script.defer = Boolean(spec.defer);
            script.src = url;
            script.setAttribute('data-hm-direct-script', String(candidate.network || 'CUSTOM'));
            script.setAttribute('data-hm-native-script', String(candidate.network || 'CUSTOM'));
            setCandidateAttributes(script, spec.attributes || {});
            var settled = false;
            var timer = window.setTimeout(function () {
                if (settled) return;
                settled = true;
                reject(new Error('script-timeout'));
            }, Math.max(500, Math.min(10000, Number((candidate.tag && directRenderPolicy(candidate.tag).timeoutMs) || 2500))));
            script.onload = function () {
                if (settled) return;
                settled = true; window.clearTimeout(timer); resolve(script);
            };
            script.onerror = function () {
                if (settled) return;
                settled = true; window.clearTimeout(timer); reject(new Error('script-error'));
            };
            try { (document.head || document.documentElement).appendChild(script); }
            catch (error) { window.clearTimeout(timer); settled = true; reject(error); }
        }).catch(function (error) {
            delete state.directScripts[key];
            throw error;
        });
        return state.directScripts[key];
    }

    function loadDirectScripts(config, candidate) {
        return directScriptSpecs(candidate.tag || {}).reduce(function (promise, spec) {
            return promise.then(function () {
                if (!canRequestAds(config)) throw new Error('blocked');
                return loadDirectScript(config, candidate, spec);
            });
        }, Promise.resolve());
    }

    function runDirectInitialization(config, candidate, container) {
        var init = candidate.tag && candidate.tag.initialization || { type: 'NONE', parameters: {} };
        var type = String(init.type || 'NONE').toUpperCase();
        var parameters = init.parameters || {};
        if (type === 'NONE') return true;
        if (type === 'MGID_QUEUE_LOAD') {
            window._mgq = window._mgq || [];
            window._mgq.push(['_mgc.load']);
            return true;
        }
        if (type === 'OUTBRAIN_RESEARCH') {
            if (!window.OBR || !window.OBR.extern || typeof window.OBR.extern.researchWidget !== 'function') return false;
            window.OBR.extern.researchWidget();
            return true;
        }
        if (type === 'TABOOLA_QUEUE') {
            var containerId = String(parameters.container || container && container.id || '');
            if (!containerId || !parameters.mode || !parameters.placement || !parameters.target_type) return false;
            window._taboola = window._taboola || [];
            window._taboola.push({
                mode: String(parameters.mode), container: containerId,
                placement: String(parameters.placement), target_type: String(parameters.target_type)
            });
            if (!state.directTaboolaFlushScheduled) {
                state.directTaboolaFlushScheduled = true;
                Promise.resolve().then(function () {
                    if (window._taboola && window._taboola.push) window._taboola.push({ flush: true });
                    state.directTaboolaFlushScheduled = false;
                });
            }
            return true;
        }
        return false;
    }

    function renderIsolatedDirect(config, entry, candidate) {
        var tag = candidate.tag || {};
        var isolation = tag.isolation || {};
        if (!canRequestAds(config) || String(tag.executionMode || '') !== 'ISOLATED_IFRAME') return Promise.resolve(false);
        if (!isolation.html || !isolation.csp || !Array.isArray(isolation.sandbox) || isolation.sandbox.length !== 1 || isolation.sandbox[0] !== 'allow-scripts') return Promise.resolve(false);
        var iframe = document.createElement('iframe');
        iframe.title = 'Advertisement';
        iframe.setAttribute('aria-label', 'Advertisement');
        iframe.setAttribute('data-hm-direct-frame', '1');
        if (iframe.sandbox && iframe.sandbox.add) iframe.sandbox.add('allow-scripts');
        else iframe.setAttribute('sandbox', 'allow-scripts');
        var safeCsp = String(isolation.csp).replace(/["<>]/g, '');
        iframe.srcdoc = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="' + safeCsp + '"></head><body>' + String(isolation.html) + '</body></html>';
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
    }

    function renderHouse(config, entry) {
        var house = entry.native && entry.native.house;
        if (!house || !house.html) {
            entry.element.setAttribute('data-hm-native', 'exhausted');
            entry.element.setAttribute('data-hm-direct', 'exhausted');
            entry.element.setAttribute('data-hm-status', 'empty');
            return false;
        }
        if (typeof entry.element.innerHTML === 'string') entry.element.innerHTML = house.html;
        entry.element.setAttribute('data-hm-native', 'HOUSE');
        entry.element.setAttribute('data-hm-direct', 'HOUSE');
        entry.element.setAttribute('data-hm-status', 'rendered');
        state.nativeRendered[entry.element.id] = 'HOUSE';
        log(config, 'Direct Demand fallback rendered house content', entry.placement.code);
        return true;
    }

    function runNativeFallback(config, entry) {
        if (!canRequestAds(config) || !entry || !entry.native || !entry.native.enabled) return Promise.resolve(false);
        var key = entry.element.id || ensureElementId(entry.element, config, entry.placement);
        if (state.nativeRendered[key]) return Promise.resolve(true);
        if (state.nativeAttempts[key]) return state.nativeAttempts[key];

        var candidates = directCandidates(config, entry);
        state.nativeAttempts[key] = new Promise(function (resolve) {
            function tryCandidate(index) {
                if (!canRequestAds(config)) { resolve(false); return; }
                if (index >= candidates.length) { resolve(renderHouse(config, entry)); return; }

                var candidate = candidates[index];
                var tag = candidate.tag || {};
                var settled = false;
                var container = null;

                function failed(reason) {
                    if (settled) return;
                    settled = true;
                    entry.element.setAttribute('data-hm-native-last-error', String(reason || 'no-fill'));
                    entry.element.setAttribute('data-hm-direct-last-error', String(reason || 'no-fill'));
                    log(config, 'Direct Demand candidate failed', candidate.network, reason);
                    tryCandidate(index + 1);
                }

                function rendered() {
                    if (settled || !canRequestAds(config)) return;
                    settled = true;
                    state.nativeRendered[key] = candidate.network;
                    entry.element.setAttribute('data-hm-native', String(candidate.network));
                    entry.element.setAttribute('data-hm-direct', String(candidate.network));
                    entry.element.setAttribute('data-hm-status', 'rendered');
                    log(config, 'Direct Demand candidate rendered', candidate.network, entry.placement.code);
                    discoverClickGuardIframes(config);
                    resolve(true);
                }

                if (String(tag.executionMode || 'STRUCTURED') === 'ISOLATED_IFRAME') {
                    renderIsolatedDirect(config, entry, candidate).then(function (ok) { if (ok) rendered(); else failed('isolated-no-render'); }).catch(function () { failed('isolated-error'); });
                    return;
                }

                container = directContainer(entry, candidate);
                loadDirectScripts(config, candidate).then(function () {
                    if (!canRequestAds(config)) { failed('blocked'); return; }
                    if (!runDirectInitialization(config, candidate, container)) { failed('initialization-failed'); return; }
                    var timeout = directRenderPolicy(tag).timeoutMs;
                    window.setTimeout(function () {
                        if (settled) return;
                        if (!nativeRendered(container, tag)) { failed('no-render'); return; }
                        rendered();
                    }, timeout);
                }).catch(function (error) {
                    failed(error && error.message || 'script-error');
                });
            }
            tryCandidate(0);
        }).finally(function () { delete state.nativeAttempts[key]; });

        return state.nativeAttempts[key];
    }
'''
replace_once(path, old, new)

# More descriptive direct-only status while preserving old attributes.
replace_once(path,
"""            item.element.setAttribute('data-hm-status', 'fallback');
""",
"""            item.element.setAttribute('data-hm-status', 'direct-demand');
""")

print('Task 17 materialization complete')
