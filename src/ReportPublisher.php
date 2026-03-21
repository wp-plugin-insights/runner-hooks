<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class ReportPublisher
{

    private const REPORT_ROUTING_KEY = 'runner-report';

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $report
     */
    public function publish(Job $job, array $report, string $receivedAt, string $completedAt): void
    {
        $payload = [
            'runner' => $this->config->runnerName,
            'plugin' => $job->plugin,
            'version' => $job->version,
            'source' => $job->source,
            'report' => $report,
            'received_at' => $receivedAt,
            'completed_at' => $completedAt,
        ];

        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $this->channel->basic_publish(
            $message,
            $this->config->reportExchange,
            self::REPORT_ROUTING_KEY
        );
    }
}
