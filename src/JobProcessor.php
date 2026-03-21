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

        // Calculate score based on hook usage
        $score = $this->calculateScore($hookData);

        // Build issues
        $issues = $this->buildIssues($hookData, $job);

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
        ];

        // Build capabilities
        $capabilities = [
            'provides_hooks' => $hookData['total_actions_provided'] > 0 || $hookData['total_filters_provided'] > 0,
            'extensible' => $hookData['total_actions_provided'] + $hookData['total_filters_provided'] >= 5,
            'actions_provided' => array_keys($actionsProvidedGrouped),
            'filters_provided' => array_keys($filtersProvidedGrouped),
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
     * @return array{grade: string, percentage: float, reasoning: string}
     */
    private function calculateScore(array $hookData): array
    {
        $totalHooksProvided = $hookData['total_actions_provided'] + $hookData['total_filters_provided'];
        $totalHooksUsed = $hookData['total_actions_used'] + $hookData['total_filters_used'];

        // Score based on WordPress integration (how well plugin uses hooks)
        // Not judging extensibility - some plugins don't need to provide hooks
        $percentage = 0.0;
        $reasoning = '';

        if ($totalHooksUsed >= 20) {
            $percentage = 100.0;
            $reasoning = 'Plugin integrates extensively with WordPress (' . $totalHooksUsed . ' hooks used)';
        } elseif ($totalHooksUsed >= 10) {
            $percentage = 85.0;
            $reasoning = 'Plugin integrates well with WordPress (' . $totalHooksUsed . ' hooks used)';
        } elseif ($totalHooksUsed >= 5) {
            $percentage = 70.0;
            $reasoning = 'Plugin uses WordPress hooks (' . $totalHooksUsed . ' hooks used)';
        } elseif ($totalHooksUsed >= 1) {
            $percentage = 50.0;
            $reasoning = 'Plugin has minimal WordPress integration (' . $totalHooksUsed . ' hooks used)';
        } else {
            $percentage = 30.0;
            $reasoning = 'Plugin does not use WordPress hooks';
        }

        // Add note about extensibility if hooks are provided
        if ($totalHooksProvided > 0) {
            $reasoning .= sprintf('. Provides %d plugin-specific hook%s for extensibility',
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
     * @return array<string, mixed>
     */
    private function buildIssues(array $hookData, Job $job): array
    {
        $issuesHigh = 0;
        $issuesMedium = 0;
        $issuesLow = 0;
        $issuesTrivial = 0;
        $topIssues = [];

        // Only report if plugin uses very few WordPress hooks (integration issue)
        $totalHooksUsed = $hookData['total_actions_used'] + $hookData['total_filters_used'];

        if ($totalHooksUsed === 0) {
            $issuesLow++;
            $topIssues[] = [
                'code' => 'hooks.no_wp_integration',
                'message' => 'Plugin does not use any WordPress hooks',
                'severity' => 'low',
            ];
        } elseif ($totalHooksUsed < 3) {
            $issuesTrivial++;
            $topIssues[] = [
                'code' => 'hooks.minimal_wp_integration',
                'message' => sprintf('Plugin uses only %d WordPress hook%s', $totalHooksUsed, $totalHooksUsed === 1 ? '' : 's'),
                'severity' => 'trivial',
            ];
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

        // Top actions used
        if (!empty($actionsUsed)) {
            $topActionsRows = [];
            foreach (array_slice($actionsUsed, 0, 10, true) as $hookName => $data) {
                $topActionsRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['top_actions_used'] = $this->reportBuilder->createTable(
                'Top Actions Used (WordPress hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $topActionsRows
            );
        }

        // Top filters used
        if (!empty($filtersUsed)) {
            $topFiltersRows = [];
            foreach (array_slice($filtersUsed, 0, 10, true) as $hookName => $data) {
                $topFiltersRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['top_filters_used'] = $this->reportBuilder->createTable(
                'Top Filters Used (WordPress hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Usage Count'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $topFiltersRows
            );
        }

        // Actions provided by plugin
        if (!empty($actionsProvided)) {
            $actionsProvidedRows = [];
            foreach ($actionsProvided as $hookName => $data) {
                $actionsProvidedRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['actions_provided'] = $this->reportBuilder->createTable(
                'Actions Provided (Plugin-specific hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
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
                $filtersProvidedRows[] = [
                    'hook' => $hookName,
                    'count' => (string) $data['count'],
                    'locations' => $data['count'] === 1
                        ? $data['locations'][0]['file'] . ':' . $data['locations'][0]['line']
                        : $data['count'] . ' locations',
                ];
            }

            $presentation['filters_provided'] = $this->reportBuilder->createTable(
                'Filters Provided (Plugin-specific hooks)',
                [
                    ['key' => 'hook', 'label' => 'Hook Name'],
                    ['key' => 'count', 'label' => 'Occurrences'],
                    ['key' => 'locations', 'label' => 'Location'],
                ],
                $filtersProvidedRows
            );
        }

        return $presentation;
    }
}
