<?php

namespace Tests\Unit;

use App\Services\Audit\AuditRecorder;
use App\Services\Reporting\SiteGamReportScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SiteGamReportScopeTest extends TestCase
{
    #[DataProvider('hostnames')]
    public function test_registered_hostname_normalization_preserves_exact_subdomain(string $input, string $expected): void
    {
        $this->assertSame($expected, (new SiteGamReportScope(new AuditRecorder))->hostname($input));
    }

    public static function hostnames(): array
    {
        return [
            ['news.publisher.example', 'news.publisher.example'],
            ['https://NEWS.PUBLISHER.EXAMPLE/articles/1', 'news.publisher.example'],
            ['publisher.example/path', 'publisher.example'],
            ['http://www.publisher.example/', 'www.publisher.example'],
            ['news.publisher.example.', 'news.publisher.example'],
            ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
        ];
    }

    #[DataProvider('invalidHostnames')]
    public function test_invalid_hosts_are_not_broadened_into_a_site_scope(string $input): void
    {
        $this->expectException(\RuntimeException::class);
        (new SiteGamReportScope(new AuditRecorder))->hostname($input);
    }

    public static function invalidHostnames(): array
    {
        return [[''], ['localhost'], ['https://user:secret@publisher.example'], ['ftp://publisher.example'], ['news..publisher.example'], ['*.publisher.example'], ['publisher.example..'], ['bücher.example']];
    }
}
