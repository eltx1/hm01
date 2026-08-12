from pathlib import Path


def replace_once(path, old, new):
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'marker missing in {path}')
    p.write_text(text.replace(old, new, 1))

replace_once(
    'app/Services/Demand/DemandConfigurationBuilder.php',
    """final class DemandConfigurationBuilder
{
    public function __construct(
        private readonly DemandConnectorManager $connectors,
        private readonly PlatformControlService $controls,
    ) {
    }
""",
    """final class DemandConfigurationBuilder
{
    private readonly PlatformControlService $controls;

    public function __construct(
        private readonly DemandConnectorManager $connectors,
        ?PlatformControlService $controls = null,
    ) {
        // Keep the pre-Task17 one-argument construction valid for existing
        // callers/tests while normal container resolution still injects the
        // operational control service explicitly.
        $this->controls = $controls ?? app(PlatformControlService::class);
    }
""",
)

replace_once(
    'app/Services/Demand/DemandConfigurationBuilder.php',
    """        $scripts = collect((array) ($tag['scripts'] ?? []))
            ->take(8)
""",
    """        $rawScripts = (array) ($tag['scripts'] ?? []);
        // Schema-v3/legacy callers may still provide the original flattened
        // scriptUrl shape. Normalize it into the v4 recipe before sanitizing.
        if ($rawScripts === [] && filled($tag['scriptUrl'] ?? null)) {
            $rawScripts[] = [
                'url' => (string) $tag['scriptUrl'],
                'async' => true,
                'defer' => false,
                'attributes' => [],
            ];
        }

        $scripts = collect($rawScripts)
            ->take(8)
""",
)

replace_once(
    'app/Services/Demand/DemandConfigurationBuilder.php',
    "preg_match('/^(?:data|aria)-[a-z0-9_.:-]+$/i', (string) $key)",
    "preg_match('/^data-[a-z0-9_.:-]+$/i', (string) $key)",
)

p = Path('tests/Feature/InventoryConfigurationTest.php')
text = p.read_text()
text = text.replace("$this->assertSame(3, $config['schemaVersion']);", "$this->assertSame(4, $config['schemaVersion']);")
text = text.replace("$this->assertSame(3, $rollback->payload['schemaVersion']);", "$this->assertSame(4, $rollback->payload['schemaVersion']);")
p.write_text(text)

Path('scripts/task17_ci_fix.py').unlink(missing_ok=True)
Path('.github/workflows/task17-ci-fix.yml').unlink(missing_ok=True)
