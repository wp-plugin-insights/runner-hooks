# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a WordPress Plugin Insights runner that analyzes WordPress plugins for hook usage (actions and filters). It's part of a message queue-based analysis system that processes plugins and generates reports on their integration with WordPress.

## Key Commands

```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
# Edit .env with your RabbitMQ connection details

# Run the runner (consumes jobs from RabbitMQ queue)
./bin/runner

# Process a single message (for testing, reads JSON from stdin)
echo '{"plugin":"example","version":"1.0","source":"wordpress.org","src":"/path/to/plugin"}' | ./bin/process-message
```

## Architecture

This is a RabbitMQ-based job processing system with the following flow:

1. **Application** (`src/Application.php`) - Entry point that sets up RabbitMQ connection and starts the runner
2. **Runner** (`src/Runner.php`) - Consumes messages from the input queue, coordinates processing, handles errors
3. **JobProcessor** (`src/JobProcessor.php`) - Orchestrates the analysis workflow and builds the report structure
4. **HookScanner** (`src/HookScanner.php`) - Scans PHP files to find hook calls (add_action, add_filter, do_action, apply_filters)
5. **ReportPublisher** (`src/ReportPublisher.php`) - Publishes analysis reports to the report exchange

### Message Flow

- Input: JSON message with `plugin`, `version`, `source`, and `src` (path to plugin directory)
- Process: Scans all PHP files (excluding vendor/ and node_modules/) for WordPress hooks and their documentation
- Output: Report with score, metrics, capabilities, issues, and detailed hook listings (including documentation status)
- Reports are published to the `plugin.analysis.reports` exchange with routing key `runner-report`

### Report Data

Reports include:

- **Metrics**: Hook usage counts, documentation percentage, well-documented count
- **Capabilities**: Boolean flags for extensibility, documentation presence, quality rating
- **Presentation**: Tables showing hooks with documentation status and descriptions
- **Issues**: Documentation quality problems (missing, incomplete, low quality)

### Hook Analysis

The scanner differentiates between:

- **Actions Used**: Plugin calls to `add_action()` (WordPress integration)
- **Filters Used**: Plugin calls to `add_filter()` (WordPress integration)
- **Actions Provided**: Plugin calls to `do_action()` (extensibility points for other developers)
- **Filters Provided**: Plugin calls to `apply_filters()` (extensibility points for other developers)

Plugin-specific hooks are identified by matching the plugin slug (with underscores) in the hook name.

### Hook Documentation Extraction

For provided hooks (`do_action` and `apply_filters`), the scanner extracts DocBlock comments that document the hooks:

- **Description**: First paragraph of the DocBlock
- **@since tag**: Version when the hook was introduced
- **@param tags**: Parameter types, names, and descriptions
- **Example detection**: Whether the DocBlock includes usage examples

Documentation quality is graded as:
- **Excellent**: 80%+ hooks documented, 50%+ well-documented (has @since and @param)
- **Good**: 60%+ documented, 30%+ well-documented
- **Fair**: 40%+ documented
- **Poor**: Some documentation but below 40%
- **None**: No documentation

Undocumented hooks generate issues:
- **Medium severity**: No hooks documented when hooks are provided
- **Low severity**: Less than 50% documented
- **Trivial severity**: 50-80% documented, or documented but missing @since/@param tags

### Scoring System

Scoring is based on **WordPress integration** (how many hooks the plugin uses):

- 100%: 20+ hooks used
- 85%: 10-19 hooks used
- 70%: 5-9 hooks used
- 50%: 1-4 hooks used
- 30%: No hooks used

The reasoning includes notes about extensibility if the plugin provides its own hooks.

## Configuration

Environment variables (via `.env`):

- `RABBITMQ_*`: RabbitMQ connection settings
- `RABBITMQ_INPUT_QUEUE`: Queue to consume jobs from (default: `plugin.analysis.runner-hooks`)
- `RABBITMQ_REPORT_EXCHANGE`: Exchange to publish reports to (default: `plugin.analysis.reports`)
- `RUNNER_CATEGORY`: Category for queue binding (default: `basic`)
- `RUNNER_NAME`: Runner identifier (default: `hooks`)

## Error Handling

The runner handles different error types:

- `InvalidArgumentException`: Rejects message (not requeued) - malformed job payload
- Other `Throwable`: Nacks message with requeue - transient failures

Messages are acknowledged only after successful report publication.

## Code Structure

- **PSR-4 autoloading**: `WpPluginInsights\RunnerHooks` namespace maps to `src/`
- **Strict types**: All PHP files use `declare(strict_types=1)`
- **Readonly properties**: Configuration and dependencies use readonly properties where applicable
- **Type safety**: Extensive use of typed arrays and return types
