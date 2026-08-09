<?php

namespace Tests\Unit;

use App\Enums\AdsTxtComplianceStatus;
use App\Services\Compliance\AdsTxtComparator;
use App\Services\Compliance\AdsTxtParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdsTxtParserTest extends TestCase
{
    #[Test]
    public function it_parses_comments_blank_lines_records_extensions_and_official_directives(): void
    {
        $content = "\xEF\xBB\xBF# comment\n\n OWNERDOMAIN = Owner.Example. # identity\n"
            ."MANAGERDOMAIN=Manager.Example, us\nSUBDOMAIN=ads.owner.example\n"
            ."INVENTORYPARTNERDOMAIN=partner.example\nCONTACT=mailto:ads@example.com\n"
            ."Exchange.Example, Seller-Case, direct, ABC123 ; inventorytype=video # live note\n";

        $result = app(AdsTxtParser::class)->parse($content);

        $this->assertCount(1, $result['records']);
        $this->assertSame('exchange.example, Seller-Case, DIRECT, abc123', $result['records'][0]['canonical']);
        $this->assertSame('inventorytype=video', $result['records'][0]['extension']);
        $this->assertSame([
            'OWNERDOMAIN=owner.example',
            'MANAGERDOMAIN=manager.example, US',
            'SUBDOMAIN=ads.owner.example',
            'INVENTORYPARTNERDOMAIN=partner.example',
            'CONTACT=mailto:ads@example.com',
        ], collect($result['directives'])->pluck('canonical')->all());
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['warnings']);
    }

    #[Test]
    public function it_reports_malformed_unknown_duplicate_and_invalid_relationship_lines(): void
    {
        $result = app(AdsTxtParser::class)->parse(<<<'TXT'
OWNERDOMAIN=owner.example
OWNERDOMAIN=other.example
UNKNOWN=value
exchange.example, seller, DIRECT
exchange.example, seller, direct
bad
exchange.example, seller-2, PARTNER
TXT);

        $this->assertCount(1, $result['records']);
        $this->assertSame(['DUPLICATE_RECORD'], collect($result['duplicates'])->pluck('code')->all());
        $this->assertSame(['MALFORMED_RECORD', 'INVALID_RECORD'], collect($result['invalid'])->pluck('code')->all());
        $this->assertSame(['ADDITIONAL_OWNERDOMAIN_IGNORED'], collect($result['warnings'])->pluck('code')->all());
        $this->assertFalse(collect($result['directives'])->firstWhere('name', 'UNKNOWN')['supported']);
    }

    #[Test]
    public function it_keeps_multiple_contact_values_and_recognizes_the_official_empty_file_placeholder(): void
    {
        $result = app(AdsTxtParser::class)->parse(<<<'TXT'
CONTACT=adops@example.com
CONTACT=https://example.com/contact
placeholder.example.com, placeholder, DIRECT, placeholder
TXT);

        $this->assertCount(2, $result['directives']);
        $this->assertTrue($result['records'][0]['is_placeholder']);
        $this->assertSame([], $result['invalid']);
        $this->assertSame([], $result['duplicates']);
        $this->assertSame(
            AdsTxtComplianceStatus::NotConfigured->value,
            app(AdsTxtComparator::class)->compare($result['directives'][0]['canonical']."\nplaceholder.example.com, placeholder, DIRECT, placeholder\n", "CONTACT=adops@example.com\nplaceholder.example.com, placeholder, DIRECT, placeholder\n")['status'],
        );
    }

    #[Test]
    public function comparator_computes_exact_diff_and_detects_identity_conflicts(): void
    {
        $required = "OWNERDOMAIN=owner.example\nMANAGERDOMAIN=horusmedia.net\nexchange.example, seller-1, DIRECT, abc\nother.example, seller-2, RESELLER\n";
        $live = "OWNERDOMAIN=owner.example\nMANAGERDOMAIN=horusmedia.net\nexchange.example, seller-1, RESELLER, abc\nextra.example, extra, DIRECT\n";

        $result = app(AdsTxtComparator::class)->compare($required, $live);

        $this->assertSame(AdsTxtComplianceStatus::Conflict->value, $result['status']);
        $this->assertCount(2, $result['missing']);
        $this->assertCount(2, $result['additional']);
        $this->assertCount(1, $result['conflicts']);
    }

    #[Test]
    public function comparator_treats_additional_valid_records_as_diagnostic_not_failure(): void
    {
        $required = "OWNERDOMAIN=owner.example\nMANAGERDOMAIN=horusmedia.net\nexchange.example, seller-1, DIRECT\n";
        $live = $required."another.example, seller-2, RESELLER\n";

        $result = app(AdsTxtComparator::class)->compare($required, $live);

        $this->assertSame(AdsTxtComplianceStatus::Compliant->value, $result['status']);
        $this->assertCount(1, $result['correct']);
        $this->assertCount(1, $result['additional']);
    }
}
