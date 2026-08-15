from pathlib import Path


def replace(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if new in text:
        return
    if old not in text:
        raise SystemExit(f"expected Task 32 patch target not found: {path}")
    p.write_text(text.replace(old, new, 1))


replace(
    "tests/Feature/StaticDeliveryTest.php",
    """        [$site] = $this->siteWithPrimaryHorus();
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'seller_id' => 'publisher-42',
            'seller_type' => 'PUBLISHER',
            'name' => 'Publisher Example',
            'domain' => $site->primary_domain,
            'status' => 'ACTIVE',
        ]);""",
    """        [$site] = $this->siteWithPrimaryHorus();
        $site->publisher()->update(['business_domain' => 'publisher.example']);
        SellerDeclaration::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'publisher_id' => $site->publisher_id,
            'site_id' => null,
            'seller_id' => 'publisher-42',
            'seller_type' => 'PUBLISHER',
            'ads_txt_relationship' => 'DIRECT',
            'name' => 'Publisher Example',
            'domain' => 'publisher.example',
            'status' => 'ACTIVE',
        ]);""",
)
replace(
    "tests/Feature/StaticDeliveryTest.php",
    """        $this->assertStringContainsString('OWNERDOMAIN='.$site->primary_domain, $adsTxt);
        $this->assertStringContainsString('MANAGERDOMAIN=', $adsTxt);""",
    """        $this->assertStringContainsString('OWNERDOMAIN=publisher.example', $adsTxt);
        $this->assertStringNotContainsString('MANAGERDOMAIN=', $adsTxt);""",
)

replace(
    "tests/Feature/SupplyChainIdentityTest.php",
    "public function test_legacy_publisher_keeps_artifact_with_explicit_review_required_fallback(): void",
    "public function test_legacy_publisher_keeps_internal_review_fallback_but_does_not_publish_unreviewed_ownerdomain(): void",
)
replace(
    "tests/Feature/SupplyChainIdentityTest.php",
    '$this->assertStringContainsString("OWNERDOMAIN=legacy-news.example\\n", $adsTxt);',
    "$this->assertStringNotContainsString('OWNERDOMAIN=', $adsTxt);",
)

replace(
    "app/Services/Inventory/SiteConfigurationBuilder.php",
    "use App\\Services\\SupplyChain\\SupplyChainInvariantService;",
    "use App\\Services\\SupplyChain\\SupplyChainStandardsContract;",
)
replace(
    "app/Services/Inventory/SiteConfigurationBuilder.php",
    "private readonly SupplyChainInvariantService $supplyChain,",
    "private readonly SupplyChainStandardsContract $supplyChain,",
)

replace(
    "app/Services/SupplyChain/PublicExtensionGuard.php",
    """        if (! is_array($value)) {
            return [$path.' must be a JSON object.'];
        }
        if ($depth > 4) {""",
    """        if (! is_array($value)) {
            return [$path.' must be a JSON object.'];
        }
        if ($value !== [] && array_is_list($value)) {
            return [$path.' must be a JSON object, not an array.'];
        }
        if ($depth > 4) {""",
)

p = Path("tests/Feature/SupplyChainStandardsContractTest.php")
text = p.read_text()
method = """    public function test_runtime_static_config_uses_the_same_canonical_seller_contract(): void
    {
        app(SupplyChainInvariantService::class)->saveSellerDeclaration($this->publisher, $this->site, [
            'seller_id' => 'site-runtime-100',
            'seller_type' => 'PUBLISHER',
            'name' => 'Canonical Publisher LLC',
            'domain' => 'canonical-publisher.example',
            'is_confidential' => false,
        ], $this->admin)->forceFill(['ads_txt_relationship' => 'DIRECT'])->save();

        $config = app(\\App\\Services\\Inventory\\SiteConfigurationBuilder::class)
            ->build($this->site, \\App\\Enums\\ConfigEnvironment::Production, 1);

        $this->assertArrayNotHasKey('supplyChain', $config);
        $this->assertStringNotContainsString('site-runtime-100', json_encode($config, JSON_THROW_ON_ERROR));
    }

"""
marker = "    private function globalSeller(string $sellerId, ?string $relationship, string $sellerType = 'PUBLISHER'): SellerDeclaration\n"
if method not in text:
    if marker not in text:
        raise SystemExit("Task 32 runtime test insertion marker not found")
    p.write_text(text.replace(marker, method + marker, 1))
