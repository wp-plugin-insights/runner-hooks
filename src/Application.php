<?php

declare(strict_types=1);

namespace WpPluginInsights\RunnerHooks;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class Application
{
    public function run(): void
    {
        $config = Config::fromEnvironment();

        $connection = new AMQPStreamConnection(
            $config->rabbitMqHost,
            $config->rabbitMqPort,
            $config->rabbitMqUser,
            $config->rabbitMqPassword,
            $config->rabbitMqVhost
        );

        $channel = $connection->channel();

        $channel->queue_declare($config->inputQueue, false, true, false, false);
        $channel->queue_bind($config->inputQueue, "plugin.analysis." . $config->runnerCategory);

        $runner = new Runner($channel, $config);
        $runner->consume();

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
