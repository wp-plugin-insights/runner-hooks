<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

use InvalidArgumentException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class Runner
{
    private readonly ReportPublisher $reportPublisher;
    private readonly JobProcessor $jobProcessor;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly Config $config
    ) {
        $this->reportPublisher = new ReportPublisher($channel, $config);
        $this->jobProcessor = new JobProcessor($config);
    }

    public function consume(): void
    {
        $this->channel->basic_qos(null, 1, null);

        $this->channel->basic_consume(
            $this->config->inputQueue,
            '',
            false,
            false,
            false,
            false,
            fn (AMQPMessage $message) => $this->handleMessage($message)
        );
    }

    private function handleMessage(AMQPMessage $message): void
    {
        try {
            $payload = $this->jobProcessor->process($message->getBody());
            $job = new Job($payload['plugin'], $payload['version'], $payload['source'], $payload['src']);

            fwrite(STDERR, sprintf("[runner] report generated" . " for plugin '%s' with version '%s'\n", $job->plugin, $job->version));

            $this->reportPublisher->publish(
                $job,
                $payload['report'],
                $payload['received_at'],
                $payload['completed_at']
            );

            $message->ack();
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, sprintf("[runner] rejecting invalid job: %s\n", $exception->getMessage()));
            $message->reject(false);
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("[runner] runtime failure: %s\n", $exception->getMessage()));
            $message->nack(false, true);
        }

        sleep(10); // Sleep for 10 seconds to avoid overwhelming the system with too many messages at once
    }
}
