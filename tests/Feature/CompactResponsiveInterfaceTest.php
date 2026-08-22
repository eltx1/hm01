<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CompactResponsiveInterfaceTest extends TestCase
{
    public function test_compact_workspace_layer_loads_after_existing_interface_styles(): void
    {
        $entrypoint = File::get(resource_path('js/app.js'));

        $this->assertMatchesRegularExpression(
            "/publisher-application\\.css';\\s*import '..\\/css\\/ux-launch\\.css';\\s*import '..\\/css\\/interface-density\\.css';/",
            $entrypoint,
        );
    }

    public function test_density_layer_covers_desktop_tablet_mobile_forms_cards_and_navigation(): void
    {
        $css = File::get(resource_path('css/interface-density.css'));

        foreach ([
            '.admin-shell',
            '.navigation-links a',
            '.metric-grid',
            '.action-card',
            '.hm-input',
            '.ai-provider-grid',
            '.table-wrap table',
            '@media (max-width: 1100px)',
            '@media (max-width: 760px)',
            '@media (max-width: 430px)',
            'body.navigation-open',
        ] as $contract) {
            $this->assertStringContainsString($contract, $css);
        }

        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit', $css);
        $this->assertStringContainsString('min-height: 2.25rem', $css);
        $this->assertStringNotContainsString('overflow-x: visible', $css);
    }
}
