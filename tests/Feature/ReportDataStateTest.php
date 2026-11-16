<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ReportDataStateTest extends TestCase
{
    public function test_finality_labels_never_hide_partial_coverage_or_invent_data(): void
    {
        $view = '<x-report-data-state :available="$available" :has-estimates="$hasEstimates" :coverage="$coverage" />';
        $coverage = ['expected_count' => 2, 'reported_count' => 1, 'missing_count' => 1];
        $html = Blade::render($view, ['available' => true, 'hasEstimates' => false, 'coverage' => $coverage]);
        $this->assertStringContainsString('Finalized imported rows', $html);
        $this->assertStringContainsString('Partial source coverage', $html);
        $html = Blade::render($view, ['available' => true, 'hasEstimates' => true, 'coverage' => $coverage]);
        $this->assertStringContainsString('Includes estimates', $html);
        $this->assertStringContainsString('Partial source coverage', $html);
        $this->assertStringNotContainsString('Finalized imported rows', $html);
        $html = Blade::render($view, ['available' => false, 'hasEstimates' => false, 'coverage' => $coverage]);
        $this->assertStringContainsString('Awaiting imported data', $html);
        $this->assertStringNotContainsString('Finalized', $html);
        $html = Blade::render($view, ['available' => true, 'hasEstimates' => false, 'coverage' => []]);
        $this->assertStringContainsString('Finalized imported rows', $html);
        $this->assertStringNotContainsString('complete', strtolower($html));
    }
}
