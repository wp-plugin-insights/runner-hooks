<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

use InvalidArgumentException;

class Job
{
    public function __construct(
        public readonly string $plugin,
        public readonly string $version,
        public readonly string $source,
        public readonly string $src
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $plugin = $payload['plugin'] ?? null;
        $version = $payload['version'] ?? null;
        $source = $payload['source'] ?? null;
        $src = $payload['src'] ?? null;

        if (!is_string($plugin) || trim($plugin) === '') {
            throw new InvalidArgumentException('Missing or invalid "plugin" field.');
        }

        if (!is_string($version) || trim($version) === '') {
            throw new InvalidArgumentException('Missing or invalid "version" field.');
        }

        if (!is_string($source) || trim($source) === '') {
            throw new InvalidArgumentException('Missing or invalid "source" field.');
        }

        if (!is_string($src) || trim($src) === '') {
            throw new InvalidArgumentException('Missing or invalid "src" field.');
        }

        return new self(trim($plugin), trim($version), trim($source), trim($src));
    }
}
