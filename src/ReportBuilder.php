<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

class ReportBuilder
{
    /**
     * Build a complete report structure.
     *
     * @param array{grade: string, percentage: float, reasoning: string} $score
     * @param array<string, mixed> $details
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $presentation
     * @param array<mixed> $issues
     * @return array<string, mixed>
     */
    public function build(
        array $score,
        array $details = [],
        array $metrics = [],
        array $capabilities = [],
        array $presentation = [],
        array $issues = []
    ): array {
        return [
            'score' => $score,
            'metrics' => $metrics,
            'capabilities' => $capabilities,
            'issues' => $issues,
            'details' => $details,
            'presentation' => $presentation,
        ];
    }

    /**
     * Create a presentation table structure.
     *
     * @param string $label
     * @param array<array{key: string, label: string}> $columns
     * @param array<array<string, string>> $rows
     * @return array<string, mixed>
     */
    public function createTable(string $label, array $columns, array $rows): array
    {
        return [
            'type' => 'table',
            'label' => $label,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Create a presentation list structure.
     *
     * @param string $label
     * @param array<string> $items
     * @param string $display
     * @return array<string, mixed>
     */
    public function createList(string $label, array $items, string $display = 'badges'): array
    {
        return [
            'type' => 'list',
            'label' => $label,
            'items' => $items,
            'display' => $display,
        ];
    }
}
