<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

use InvalidArgumentException;
use Throwable;

class JobProcessor
{
    public function __construct(
        private readonly Config $config,
        private readonly ReportBuilder $reportBuilder = new ReportBuilder(),
        private readonly HookScanner $hookScanner = new HookScanner()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(string $body): array
    {
        $receivedAt = gmdate(DATE_ATOM);
        $job = $this->parseJob($body);

        return [
            'runner' => $this->config->runnerName,
            'plugin' => $job->plugin,
            'version' => $job->version,
            'source' => $job->source,
            'src' => $job->src,
            'report' => $this->doAction($job),
            'received_at' => $receivedAt,
            'completed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function parseJob(string $body): Job
    {
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Message body is not valid JSON.', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new InvalidArgumentException('Message body must decode to a JSON object.');
        }

        return Job::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function doAction(Job $job): array
    {
        // Scan the plugin for hooks
        $hookData = $this->hookScanner->scan($job->src, $job->plugin);

        // Group hooks by name for better presentation
        $actionsUsedGrouped = $this->hookScanner->groupHooksByName($hookData['actions_used']);
        $filtersUsedGrouped = $this->hookScanner->groupHooksByName($hookData['filters_used']);
        $actionsProvidedGrouped = $this->hookScanner->groupHooksByName($hookData['actions_provided']);
        $filtersProvidedGrouped = $this->hookScanner->groupHooksByName($hookData['filters_provided']);

        // Calculate documentation metrics
        $docMetrics = $this->calculateDocumentationMetrics($actionsProvidedGrouped, $filtersProvidedGrouped);

        // Calculate score based on documentation quality
        $score = $this->calculateScore($hookData, $docMetrics);

        // Build issues
        $issues = $this->buildIssues($hookData, $job, $docMetrics);

        // Build presentation data
        $presentation = $this->buildPresentation(
            $actionsUsedGrouped,
            $filtersUsedGrouped,
            $actionsProvidedGrouped,
            $filtersProvidedGrouped
        );

        // Build detailed metrics
        $metrics = [
            'total_actions_used' => $hookData['total_actions_used'],
            'total_filters_used' => $hookData['total_filters_used'],
            'total_actions_provided' => $hookData['total_actions_provided'],
            'total_filters_provided' => $hookData['total_filters_provided'],
            'total_hooks_used' => $hookData['total_actions_used'] + $hookData['total_filters_used'],
            'total_hooks_provided' => $hookData['total_actions_provided'] + $hookData['total_filters_provided'],
            'unique_actions_used' => count($actionsUsedGrouped),
            'unique_filters_used' => count($filtersUsedGrouped),
            'unique_actions_provided' => count($actionsProvidedGrouped),
            'unique_filters_provided' => count($filtersProvidedGrouped),
            'documented_hooks_count' => $docMetrics['documented_count'],
            'documented_hooks_percentage' => $docMetrics['documented_percentage'],
            'well_documented_hooks_count' => $docMetrics['well_documented_count'],
        ];

        // Build capabilities
        $capabilities = [
            'provides_hooks' => $hookData['total_actions_provided'] > 0 || $hookData['total_filters_provided'] > 0,
            'extensible' => $hookData['total_actions_provided'] + $hookData['total_filters_provided'] >= 5,
            'actions_provided' => array_keys($actionsProvidedGrouped),
            'filters_provided' => array_keys($filtersProvidedGrouped),
            'documented_hooks' => $docMetrics['has_documentation'],
            'documentation_quality' => $docMetrics['quality'],
        ];

        return $this->reportBuilder->build(
            score: $score,
            details: [
                'actions_used' => $actionsUsedGrouped,
                'filters_used' => $filtersUsedGrouped,
                'actions_provided' => $actionsProvidedGrouped,
                'filters_provided' => $filtersProvidedGrouped,
            ],
            metrics: $metrics,
            capabilities: $capabilities,
            presentation: $presentation,
            issues: $issues
        );
    }

    /**
     * @param array<string, mixed> $hookData
     * @param array<string, mixed> $docMetrics
     * @return array{grade: string, percentage: float, reasoning: string}
     */
    private function calculateScore(array $hookData, array $docMetrics): array
    {
        $totalHooksProvided = $hookData['total_actions_provided'] + $hookData['total_filters_provided'];

        // If plugin doesn't provide hooks, no score (not applicable)
        if ($totalHooksProvided === 0) {
            return [
                'grade' => 'N/A',
                'percentage' => 0.0,
                'reasoning' => 'Plugin does not provide extensibility hooks',
            ];
        }

        // Score based on documentation quality of provided hooks
        $documentedPercentage = $docMetrics['documented_percentage'];
        $wellDocumentedCount = $docMetrics['well_documented_count'];
        $documentedCount = $docMetrics['documented_count'];
        $quality = $docMetrics['quality'];

        $percentage = 0.0;
        $reasoning = '';

        // Grade based on documentation coverage and quality
        if ($quality === 'excellent') {
            $percentage = 95.0;
            $reasoning = sprintf(
                'Excellent hook documentation: %d of %d hooks documented (%.0f%%), with comprehensive @since and @param tags',
                $documentedCount,
                $totalHooksProvided,
                $documentedPercentage
            );
        } elseif ($quality === 'good') {
            $percentage = 85.0;
            $reasoning = sprintf(
                'Good hook documentation: %d of %d hooks documented (%.0f%%)',
                $documentedCount,
                $totalHooksProvided,
                $documentedPercentage
            );
        } elseif ($quality === 'fair') {
            $percentage = 65.0;
            $reasoning = sprintf(
                'Fair hook documentation: %d of %d hooks documented (%.0f%%), but many lack complete details',
                $documentedCount,
                $totalHooksProvided,
                $documentedPercentage
            );
        } elseif ($quality === 'poor') {
            $percentage = 45.0;
            $reasoning = sprintf(
                'Poor hook documentation: Only %d of %d hooks documented (%.0f%%)',
                $documentedCount,
                $totalHooksProvided,
                $documentedPercentage
            );
        } else {
            $percentage = 30.0;
            $reasoning = sprintf(
                'No hook documentation: Plugin provides %d extensibility hook%s but none are documented',
                $totalHooksProvided,
                $totalHooksProvided === 1 ? '' : 's'
            );
        }

        // Determine grade
        $grade = match (true) {
            $percentage >= 90 => 'A',
            $percentage >= 80 => 'B',
            $percentage >= 70 => 'C',
            $percentage >= 60 => 'D',
            default => 'F',
        };

        return [
            'grade' => $grade,
            'percentage' => $percentage,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * @param array<string, mixed> $hookData
     * @param array<string, mixed> $docMetrics
     * @return array<string, mixed>
     */
    private function buildIssues(array $hookData, Job $job, array $docMetrics): array
    {
        $issuesHigh = 0;
        $issuesMedium = 0;
        $issuesLow = 0;
        $issuesTrivial = 0;
        $topIssues = [];

        // Note: We don't report on hook usage as it's context-dependent
        // A simple utility plugin doesn't need many hooks

        // Documentation issues
        $totalHooksProvided = $hookData['total_actions_provided'] + $hookData['total_filters_provided'];
        if ($totalHooksProvided > 0) {
            $documentedPercentage = $docMetrics['documented_percentage'];
            $documentedCount = $docMetrics['documented_count'];
            $undocumentedCount = $totalHooksProvided - $documentedCount;

            if ($documentedPercentage === 0.0) {
                $issuesHigh++;
                $topIssues[] = [
                    'code' => 'hooks.no_documentation',
                    'message' => sprintf('Plugin provides %d extensibility hook%s but none are documented',
                        $totalHooksProvided,
                        $totalHooksProvided === 1 ? '' : 's'
                    ),
                    'severity' => 'high',
                ];
            } elseif ($documentedPercentage < 50.0) {
                $issuesMedium++;
                $topIssues[] = [
                    'code' => 'hooks.poor_documentation',
                    'message' => sprintf('Only %d of %d provided hooks (%.0f%%) are documented',
                        $documentedCount,
                        $totalHooksProvided,
                        $documentedPercentage
                    ),
                    'severity' => 'medium',
                ];
            } elseif ($documentedPercentage < 80.0) {
                $issuesLow++;
                $topIssues[] = [
                    'code' => 'hooks.incomplete_documentation',
                    'message' => sprintf('%d of %d provided hooks lack documentation',
                        $undocumentedCount,
                        $totalHooksProvided
                    ),
                    'severity' => 'low',
                ];
            }

            // Check for missing important documentation elements
            $wellDocumentedCount = $docMetrics['well_documented_count'];
            if ($documentedCount > 0 && $wellDocumentedCount < ($documentedCount * 0.5)) {
                $issuesTrivial++;
                $topIssues[] = [
                    'code' => 'hooks.low_quality_documentation',
                    'message' => 'Many documented hooks are missing @since or @param tags',
                    'severity' => 'trivial',
                ];
            }
        }

        return [
            'high' => $issuesHigh,
            'medium' => $issuesMedium,
            'low' => $issuesLow,
            'trivial' => $issuesTrivial,
            'top' => $topIssues,
        ];
    }

    /**
     * Calculate documentation metrics for provided hooks.
     *
     * @param array<string, array{count: int, locations: array, documentation: array|null}> $actionsProvided
     * @param array<string, array{count: int, locations: array, documentation: array|null}> $filtersProvided
     * @return array{documented_count: int, documented_percentage: float, well_documented_count: int, has_documentation: bool, quality: string}
     */
    private function calculateDocumentationMetrics(array $actionsProvided, array $filtersProvided): array
    {
        $allProvidedHooks = array_merge($actionsProvided, $filtersProvided);
        $totalHooks = count($allProvidedHooks);

        if ($totalHooks === 0) {
            return [
                'documented_count' => 0,
                'documented_percentage' => 0.0,
                'well_documented_count' => 0,
                'has_documentation' => false,
                'quality' => 'none',
            ];
        }

        $documentedCount = 0;
        $wellDocumentedCount = 0;

        foreach ($allProvidedHooks as $hook) {
            $doc = $hook['documentation'];

            if ($doc !== null && $doc['description'] !== '') {
                $documentedCount++;

                // Well-documented: has description, @since, and at least one @param
                if ($doc['since'] !== null && !empty($doc['params'])) {
                    $wellDocumentedCount++;
                }
            }
        }

        $documentedPercentage = ($documentedCount / $totalHooks) * 100;

        // Determine quality level
        $quality = 'none';
        if ($documentedPercentage >= 80 && $wellDocumentedCount >= ($totalHooks * 0.5)) {
            $quality = 'excellent';
        } elseif ($documentedPercentage >= 60 && $wellDocumentedCount >= ($totalHooks * 0.3)) {
            $quality = 'good';
        } elseif ($documentedPercentage >= 40) {
            $quality = 'fair';
        } elseif ($documentedPercentage > 0) {
            $quality = 'poor';
        }

        return [
            'documented_count' => $documentedCount,
            'documented_percentage' => round($documentedPercentage, 1),
            'well_documented_count' => $wellDocumentedCount,
            'has_documentation' => $documentedCount > 0,
            'quality' => $quality,
        ];
    }

    /**
     * @param array<string, array{count: int, locations: array}> $actionsUsed
     * @param array<string, array{count: int, locations: array}> $filtersUsed
     * @param array<string, array{count: int, locations: array}> $actionsProvided
     * @param array<string, array{count: int, locations: array}> $filtersProvided
     * @return array<string, mixed>
     */
    private function buildPresentation(
        array $actionsUsed,
        array $filtersUsed,
        array $actionsProvided,
        array $filtersProvided
    ): array {
        $presentation = [];

        // Separate WordPress hooks from plugin-specific hooks
        $wpActions = [];
        $pluginActions = [];
        foreach ($actionsUsed as $hookName => $data) {
            if (isset($actionsProvided[$hookName])) {
                $pluginActions[$hookName] = $data;
            } else {
                $wpActions[$hookName] = $data;
            }
        }

        $wpFilters = [];
        $pluginFilters = [];
        foreach ($filtersUsed as $hookName => $data) {
            if (isset($filtersProvided[$hookName])) {
                $pluginFilters[$hookName] = $data;
            } else {
                $wpFilters[$hookName] = $data;
            }
        }

        // WordPress actions used
        if (!empty($wpActions)) {
            $wpActionsRows = [];
            foreach (array_slice($wpActions, 0, 10, true) as $hookName => $data) {
                $wpActionsRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['wordpress_actions_used'] = $this->reportBuilder->createTable(
                'WordPress Actions Used',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $wpActionsRows
            );
        }

        // Plugin actions used
        if (!empty($pluginActions)) {
            $pluginActionsRows = [];
            foreach (array_slice($pluginActions, 0, 10, true) as $hookName => $data) {
                $pluginActionsRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['plugin_actions_used'] = $this->reportBuilder->createTable(
                'Plugin Actions Used',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $pluginActionsRows
            );
        }

        // WordPress filters used
        if (!empty($wpFilters)) {
            $wpFiltersRows = [];
            foreach (array_slice($wpFilters, 0, 10, true) as $hookName => $data) {
                $wpFiltersRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['wordpress_filters_used'] = $this->reportBuilder->createTable(
                'WordPress Filters Used',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $wpFiltersRows
            );
        }

        // Plugin filters used
        if (!empty($pluginFilters)) {
            $pluginFiltersRows = [];
            foreach (array_slice($pluginFilters, 0, 10, true) as $hookName => $data) {
                $pluginFiltersRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['plugin_filters_used'] = $this->reportBuilder->createTable(
                'Plugin Filters Used',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $pluginFiltersRows
            );
        }

        // Actions provided by plugin
        if (!empty($actionsProvided)) {
            $actionsProvidedRows = [];
            foreach ($actionsProvided as $hookName => $data) {
                $row = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];

                // Add documentation description
                if ($data['documentation'] !== null && $data['documentation']['description'] !== '') {
                    $doc = $data['documentation'];
                    $row['description'] = mb_strlen($doc['description']) > 80
                        ? mb_substr($doc['description'], 0, 77) . '...'
                        : $doc['description'];
                } else {
                    $row['description'] = '';
                }

                $actionsProvidedRows[] = $row;
            }

            $presentation['actions_provided'] = $this->reportBuilder->createTable(
                'Actions Provided (Plugin-specific hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'description', 'label' => 'Description'],
                    ['key' => 'count', 'label' => 'Occurrences'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $actionsProvidedRows
            );
        }

        // Filters provided by plugin
        if (!empty($filtersProvided)) {
            $filtersProvidedRows = [];
            foreach ($filtersProvided as $hookName => $data) {
                $row = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];

                // Add documentation description
                if ($data['documentation'] !== null && $data['documentation']['description'] !== '') {
                    $doc = $data['documentation'];
                    $row['description'] = mb_strlen($doc['description']) > 80
                        ? mb_substr($doc['description'], 0, 77) . '...'
                        : $doc['description'];
                } else {
                    $row['description'] = '';
                }

                $filtersProvidedRows[] = $row;
            }

            $presentation['filters_provided'] = $this->reportBuilder->createTable(
                'Filters Provided (Plugin-specific hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'description', 'label' => 'Description'],
                    ['key' => 'count', 'label' => 'Occurrences'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $filtersProvidedRows
            );
        }

        return $presentation;
    }
}
