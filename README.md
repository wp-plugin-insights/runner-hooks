# Runner Hooks

WordPress Plugin Insights runner for scanning and analyzing WordPress hooks (actions and filters) in plugins.

## Features

This runner analyzes:

- **Actions Used**: WordPress actions that the plugin hooks into using `add_action()`
- **Filters Used**: WordPress filters that the plugin hooks into using `add_filter()`
- **Actions Provided**: Plugin-specific actions created using `do_action()` that other developers can hook into
- **Filters Provided**: Plugin-specific filters created using `apply_filters()` that other developers can use

## Installation

```bash
composer install
cp .env.example .env
```

Edit `.env` to configure your RabbitMQ connection settings.

## Usage

```bash
./bin/runner
```

The runner will:
1. Connect to RabbitMQ
2. Listen for analysis jobs on the configured queue
3. Scan plugin files for hook usage
4. Generate a report with metrics and insights
5. Publish the report back to the report exchange

## Report Structure

The runner generates reports with:

- **Score**: Grade (A-F) based on extensibility (number of plugin-specific hooks provided)
- **Metrics**: Counts of actions/filters used and provided
- **Capabilities**: Whether the plugin is extensible and what hooks it provides
- **Issues**: Identified issues like lack of plugin-specific hooks
- **Presentation**: Tables showing top hooks used and all hooks provided

## Scoring

The score is primarily based on extensibility:

- **A (90-100%)**: 20+ plugin-specific hooks
- **B (80-89%)**: 10-19 plugin-specific hooks
- **C (70-79%)**: 5-9 plugin-specific hooks
- **D (60-69%)**: 1-4 plugin-specific hooks
- **F (<60%)**: No plugin-specific hooks

## Development

The main classes are:

- `Application.php`: Entry point, sets up RabbitMQ connection
- `Runner.php`: Consumes messages and coordinates processing
- `JobProcessor.php`: Processes jobs and builds reports
- `HookScanner.php`: Scans PHP files for WordPress hooks
- `ReportBuilder.php`: Builds structured report data
- `ReportPublisher.php`: Publishes reports to RabbitMQ
