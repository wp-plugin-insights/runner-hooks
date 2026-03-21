<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

class HookScanner
{
    /**
     * Scan a plugin directory for hooks (actions and filters).
     *
     * @param string $pluginPath Path to the plugin directory
     * @param string $pluginSlug Plugin slug to identify plugin-specific hooks
     * @return array<string, mixed>
     */
    public function scan(string $pluginPath, string $pluginSlug): array
    {
        $actions = [];
        $filters = [];
        $doActions = [];
        $applyFilters = [];

        $phpFiles = $this->findPhpFiles($pluginPath);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace($pluginPath . '/', '', $file);

            // Find add_action calls
            $actions = array_merge($actions, $this->findHookCalls($content, $relativePath, 'add_action'));

            // Find add_filter calls
            $filters = array_merge($filters, $this->findHookCalls($content, $relativePath, 'add_filter'));

            // Find do_action calls (plugin-specific hooks provided by the plugin)
            $doActions = array_merge($doActions, $this->findHookCalls($content, $relativePath, 'do_action'));

            // Find apply_filters calls (plugin-specific hooks provided by the plugin)
            $applyFilters = array_merge($applyFilters, $this->findHookCalls($content, $relativePath, 'apply_filters'));
        }

        // Categorize hooks
        $pluginSpecificActions = $this->filterPluginSpecificHooks($doActions, $pluginSlug);
        $pluginSpecificFilters = $this->filterPluginSpecificHooks($applyFilters, $pluginSlug);

        return [
            'actions_used' => $actions,
            'filters_used' => $filters,
            'actions_provided' => $pluginSpecificActions,
            'filters_provided' => $pluginSpecificFilters,
            'total_actions_used' => count($actions),
            'total_filters_used' => count($filters),
            'total_actions_provided' => count($pluginSpecificActions),
            'total_filters_provided' => count($pluginSpecificFilters),
        ];
    }

    /**
     * Find all PHP files in a directory.
     *
     * @param string $directory
     * @return array<string>
     */
    private function findPhpFiles(string $directory): array
    {
        $phpFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Skip vendor and node_modules directories
                $path = $file->getPathname();
                if (strpos($path, '/vendor/') !== false || strpos($path, '/node_modules/') !== false) {
                    continue;
                }
                $phpFiles[] = $path;
            }
        }

        return $phpFiles;
    }

    /**
     * Find hook calls in file content.
     *
     * @param string $content
     * @param string $file
     * @param string $function
     * @return array<array{hook: string, file: string, line: int, callback: string|null, priority: int|null}>
     */
    private function findHookCalls(string $content, string $file, string $function): array
    {
        $hooks = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Pattern to match function calls: add_action('hook_name', ...) or do_action('hook_name', ...)
            // Also handles double quotes and concatenation
            $patterns = [
                // Simple single-quoted hook names
                '/' . preg_quote($function, '/') . '\s*\(\s*[\'"]([^\'"\)]+)[\'"]\s*[,\)]/',
                // Variable hook names (we'll capture the variable name)
                '/' . preg_quote($function, '/') . '\s*\(\s*\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*[,\)]/',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line, $matches)) {
                    $hookName = $matches[1];

                    // Extract callback and priority for add_action/add_filter
                    $callback = null;
                    $priority = null;

                    if (in_array($function, ['add_action', 'add_filter'])) {
                        $callback = $this->extractCallback($line, $function);
                        $priority = $this->extractPriority($line, $function);
                    }

                    $hooks[] = [
                        'hook' => $hookName,
                        'file' => $file,
                        'line' => $lineNumber + 1,
                        'callback' => $callback,
                        'priority' => $priority,
                    ];
                }
            }
        }

        return $hooks;
    }

    /**
     * Extract callback from add_action/add_filter call.
     *
     * @param string $line
     * @param string $function
     * @return string|null
     */
    private function extractCallback(string $line, string $function): ?string
    {
        // Find the start of the function call
        $functionPattern = '/' . preg_quote($function, '/') . '\s*\(/';
        if (!preg_match($functionPattern, $line, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $startPos = $match[0][1] + strlen($match[0][0]);

        // Skip the first parameter (hook name)
        $pos = $this->skipParameter($line, $startPos);
        if ($pos === null) {
            return null;
        }

        // Extract the callback parameter
        $callbackStart = $pos;
        $callbackEnd = $this->findParameterEnd($line, $callbackStart);
        if ($callbackEnd === null) {
            return null;
        }

        $callback = trim(substr($line, $callbackStart, $callbackEnd - $callbackStart));

        // Parse the callback
        return $this->parseCallback($callback);
    }

    /**
     * Skip to the next parameter.
     *
     * @param string $line
     * @param int $start
     * @return int|null
     */
    private function skipParameter(string $line, int $start): ?int
    {
        $pos = $this->findParameterEnd($line, $start);
        if ($pos === null) {
            return null;
        }

        // Skip comma and whitespace
        while ($pos < strlen($line) && ($line[$pos] === ',' || ctype_space($line[$pos]))) {
            $pos++;
        }

        return $pos;
    }

    /**
     * Find the end of a parameter (handles nested structures).
     *
     * @param string $line
     * @param int $start
     * @return int|null
     */
    private function findParameterEnd(string $line, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($line);

        for ($i = $start; $i < $length; $i++) {
            $char = $line[$i];

            // Handle strings
            if (($char === '"' || $char === "'") && ($i === 0 || $line[$i - 1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
                continue;
            }

            if ($inString) {
                continue;
            }

            // Handle brackets and parentheses
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                if ($depth === 0) {
                    return $i; // End of parameters
                }
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return $i; // End of this parameter
            }
        }

        return $length;
    }

    /**
     * Parse callback into a readable format.
     *
     * @param string $callback
     * @return string
     */
    private function parseCallback(string $callback): string
    {
        // Handle arrays: array($this, 'method') or [$this, 'method']
        if (preg_match('/^(array\s*\(|\[)\s*\$(\w+)\s*,\s*[\'"](\w+)[\'"]\s*(\)|\])$/', $callback, $match)) {
            return '$' . $match[2] . '::' . $match[3];
        }

        // Handle closures/anonymous functions
        if (preg_match('/^(function|fn)\s*\(/', $callback)) {
            return preg_match('/^fn\s*\(/', $callback) ? 'fn(...)' : 'function(...)';
        }

        // Handle string callbacks
        $callback = trim($callback, " \t\n\r\0\x0B'\"");

        // Limit length for readability
        if (strlen($callback) > 50) {
            return substr($callback, 0, 47) . '...';
        }

        return $callback;
    }

    /**
     * Extract priority from add_action/add_filter call.
     *
     * @param string $line
     * @param string $function
     * @return int|null
     */
    private function extractPriority(string $line, string $function): ?int
    {
        // Priority is the 3rd parameter
        $pattern = '/' . preg_quote($function, '/') . '\s*\([^,]+,[^,]+,\s*(\d+)/';

        if (preg_match($pattern, $line, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Filter hooks to only include plugin-specific ones.
     *
     * @param array<array{hook: string, file: string, line: int, callback: string|null, priority: int|null}> $hooks
     * @param string $pluginSlug
     * @return array<array{hook: string, file: string, line: int, callback: string|null, priority: int|null}>
     */
    private function filterPluginSpecificHooks(array $hooks, string $pluginSlug): array
    {
        $pluginPrefix = str_replace('-', '_', $pluginSlug);

        // Also check for short prefix (first word of plugin slug)
        // e.g., "tabify-edit-screen" -> check both "tabify_edit_screen" and "tabify_"
        $shortPrefix = '';
        if (strpos($pluginPrefix, '_') !== false) {
            $parts = explode('_', $pluginPrefix);
            $shortPrefix = $parts[0] . '_';
        }

        $filtered = [];

        foreach ($hooks as $hook) {
            $hookName = $hook['hook'];

            // Check if hook name contains the full plugin prefix
            if (strpos($hookName, $pluginPrefix) !== false) {
                $filtered[] = $hook;
            }
            // Or if it starts with the short prefix (e.g., "tabify_")
            elseif ($shortPrefix !== '' && strpos($hookName, $shortPrefix) === 0) {
                $filtered[] = $hook;
            }
        }

        return $filtered;
    }

    /**
     * Group hooks by name with their locations.
     *
     * @param array<array{hook: string, file: string, line: int, callback: string|null, priority: int|null}> $hooks
     * @return array<string, array{count: int, locations: array<array{file: string, line: int, callback: string|null, priority: int|null}>}>
     */
    public function groupHooksByName(array $hooks): array
    {
        $grouped = [];

        foreach ($hooks as $hook) {
            $hookName = $hook['hook'];

            if (!isset($grouped[$hookName])) {
                $grouped[$hookName] = [
                    'count' => 0,
                    'locations' => [],
                ];
            }

            $grouped[$hookName]['count']++;
            $grouped[$hookName]['locations'][] = [
                'file' => $hook['file'],
                'line' => $hook['line'],
                'callback' => $hook['callback'],
                'priority' => $hook['priority'],
            ];
        }

        // Sort by count descending
        uasort($grouped, fn($a, $b) => $b['count'] <=> $a['count']);

        return $grouped;
    }
}
