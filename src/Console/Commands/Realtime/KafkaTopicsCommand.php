<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Realtime;

use Toporia\Framework\Console\Command;

/**
 * Class KafkaTopicsCommand
 *
 * Manage Kafka topics - list, create, alter partitions.
 * Uses kafka-topics CLI via Docker.
 */
final class KafkaTopicsCommand extends Command
{
    protected string $signature = 'kafka:topics
        {action=list : Action to perform (list, create, alter, describe, sync)}
        {--topic= : Topic name (required for create, alter, describe)}
        {--partitions=10 : Number of partitions}
        {--replication=1 : Replication factor}
        {--bootstrap=localhost:9092 : Kafka bootstrap server}
        {--container=toporia_kafka : Docker container name}';

    protected string $description = 'Manage Kafka topics (list, create, alter partitions, sync from config)';

    public function handle(): int
    {
        $action = $this->argument('action') ?? 'list';
        $topic = $this->option('topic');
        $partitions = (int) ($this->option('partitions') ?? 10);
        $replication = (int) ($this->option('replication') ?? 1);
        $bootstrap = $this->option('bootstrap') ?? 'localhost:9092';
        $container = $this->option('container') ?? 'toporia_kafka';

        return match ($action) {
            'list' => $this->listTopics($bootstrap, $container),
            'create' => $this->createTopic($topic, $partitions, $replication, $bootstrap, $container),
            'alter' => $this->alterPartitions($topic, $partitions, $bootstrap, $container),
            'describe' => $this->describeTopic($topic, $bootstrap, $container),
            'sync' => $this->syncFromConfig($bootstrap, $container),
            default => $this->invalidAction($action),
        };
    }

    private function listTopics(string $bootstrap, string $container): int
    {
        $this->newLine();
        $this->writeln('<fg=cyan>╔════════════════════════════════════════════╗</>');
        $this->writeln('<fg=cyan>║</>       <fg=yellow>KAFKA TOPICS OVERVIEW</>          <fg=cyan>║</>');
        $this->writeln('<fg=cyan>╚════════════════════════════════════════════╝</>');
        $this->newLine();

        $command = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} --list 2>&1";
        $output = shell_exec($command);

        if ($output === null || str_contains($output, 'Error')) {
            $this->error("Failed to connect to Kafka");
            return 1;
        }

        $topics = array_filter(explode("\n", trim($output)));
        $userTopics = array_filter($topics, fn($t) => !str_starts_with($t, '__'));

        if (empty($userTopics)) {
            $this->warn('No topics found.');
            return 0;
        }

        // Get partition count for each topic
        $topicData = [];
        foreach ($userTopics as $topicName) {
            $partitions = $this->getTopicPartitionCount($topicName, $bootstrap, $container);
            $topicData[] = [$topicName, (string) $partitions];
        }

        $this->table(['Topic Name', 'Partitions'], $topicData);

        $this->newLine();
        $this->writeln('<fg=gray>────────────────────────────────────────────</>');
        $this->writeln('  <fg=cyan>Total:</> <fg=yellow>' . count($userTopics) . '</> topic(s)');
        $this->writeln('<fg=gray>────────────────────────────────────────────</>');
        $this->newLine();

        return 0;
    }

    /**
     * Get partition count for a topic.
     */
    private function getTopicPartitionCount(string $topicName, string $bootstrap, string $container): int
    {
        $command = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--describe --topic {$topicName} 2>&1";
        $output = shell_exec($command);

        if ($output === null) {
            return 0;
        }

        preg_match('/PartitionCount:\s*(\d+)/', $output, $matches);
        return (int) ($matches[1] ?? 0);
    }

    private function createTopic(
        ?string $topic,
        int $partitions,
        int $replication,
        string $bootstrap,
        string $container
    ): int {
        if (empty($topic)) {
            $this->error('Topic name is required. Use --topic=<name>');
            return 1;
        }

        $this->info("Creating topic: {$topic}");

        $command = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--create --topic {$topic} --partitions {$partitions} --replication-factor {$replication} 2>&1";

        $output = shell_exec($command);

        if ($output !== null && str_contains($output, 'already exists')) {
            $this->warn("Topic '{$topic}' already exists.");
            return 0;
        }

        if ($output !== null && str_contains($output, 'Error')) {
            $this->error("Failed to create topic");
            return 1;
        }

        $this->success("Created '{$topic}' with {$partitions} partitions");
        return 0;
    }

    private function alterPartitions(
        ?string $topic,
        int $partitions,
        string $bootstrap,
        string $container
    ): int {
        if (empty($topic)) {
            $this->error('Topic name is required. Use --topic=<name>');
            return 1;
        }

        $describeCmd = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--describe --topic {$topic} 2>&1";
        $output = shell_exec($describeCmd);

        if ($output === null || str_contains($output, 'does not exist')) {
            $this->error("Topic '{$topic}' does not exist.");
            return 1;
        }

        preg_match('/PartitionCount:\s*(\d+)/', $output, $matches);
        $currentPartitions = (int) ($matches[1] ?? 0);

        if ($currentPartitions >= $partitions) {
            $this->warn("Already has {$currentPartitions} partitions (requested: {$partitions})");
            $this->line('Note: Kafka does not support reducing partitions.');
            return 0;
        }

        $this->info("Altering: {$topic}");

        $command = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--alter --topic {$topic} --partitions {$partitions} 2>&1";

        $output = shell_exec($command);

        if ($output !== null && str_contains($output, 'Error')) {
            $this->error("Failed to alter partitions");
            return 1;
        }

        $this->success("{$currentPartitions} → {$partitions} partitions");
        return 0;
    }

    private function describeTopic(?string $topic, string $bootstrap, string $container): int
    {
        if (empty($topic)) {
            $this->error('Topic name is required. Use --topic=<name>');
            return 1;
        }

        $command = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--describe --topic {$topic} 2>&1";

        $output = shell_exec($command);

        if ($output === null || str_contains($output, 'does not exist')) {
            $this->error("Topic '{$topic}' does not exist.");
            return 1;
        }

        // Parse topic info
        preg_match('/PartitionCount:\s*(\d+)/', $output, $partMatch);
        preg_match('/ReplicationFactor:\s*(\d+)/', $output, $replMatch);

        $partitions = $partMatch[1] ?? '?';
        $replication = $replMatch[1] ?? '?';

        $this->newLine();
        $this->writeln('<fg=cyan>╔════════════════════════════════════════════╗</>');
        $this->writeln('<fg=cyan>║</>           <fg=yellow>TOPIC DETAILS</>               <fg=cyan>║</>');
        $this->writeln('<fg=cyan>╚════════════════════════════════════════════╝</>');
        $this->newLine();

        $this->writeln('  <fg=gray>Topic:</>        <fg=green>' . $topic . '</>');
        $this->writeln('  <fg=gray>Partitions:</>   <fg=yellow>' . $partitions . '</>');
        $this->writeln('  <fg=gray>Replication:</>  <fg=cyan>' . $replication . '</>');
        $this->newLine();

        return 0;
    }

    private function syncFromConfig(string $bootstrap, string $container): int
    {
        $this->newLine();
        $this->writeln('<fg=cyan>╔════════════════════════════════════════════╗</>');
        $this->writeln('<fg=cyan>║</>       <fg=yellow>SYNC TOPICS FROM CONFIG</>         <fg=cyan>║</>');
        $this->writeln('<fg=cyan>╚════════════════════════════════════════════╝</>');
        $this->newLine();

        $kafkaConfig = config('kafka', []);
        $topicMapping = $kafkaConfig['topic_mapping'] ?? [];
        $defaultPartitions = (int) ($kafkaConfig['default_partitions'] ?? 10);

        if (empty($topicMapping)) {
            $this->warn('No topic_mapping found in config/kafka.php');
            return 0;
        }

        $results = [];

        foreach ($topicMapping as $config) {
            $topicName = $config['topic'] ?? null;
            $partitions = (int) ($config['partitions'] ?? $defaultPartitions);

            if ($topicName === null) {
                continue;
            }

            $result = $this->syncTopic($topicName, $partitions, $bootstrap, $container);
            $results[] = [$topicName, $partitions, $result];
        }

        // Sync default topic
        $defaultTopic = $kafkaConfig['default_topic'] ?? 'realtime';
        $result = $this->syncTopic($defaultTopic, $defaultPartitions, $bootstrap, $container);
        $results[] = [$defaultTopic . ' (default)', $defaultPartitions, $result];

        // Display results table
        $this->newLine();
        $this->table(
            ['Topic', 'Partitions', 'Status'],
            $results
        );

        $created = count(array_filter($results, fn($r) => str_contains($r[2], 'Created')));
        $altered = count(array_filter($results, fn($r) => str_contains($r[2], 'Altered')));
        $ok = count(array_filter($results, fn($r) => $r[2] === 'OK'));
        $errors = count(array_filter($results, fn($r) => str_contains($r[2], 'Error')));

        $this->newLine();
        $this->writeln('<fg=gray>────────────────────────────────────────────</>');
        $this->writeln('  <fg=cyan>Summary:</>');
        $this->writeln('    <fg=green>+</> Created:   <fg=yellow>' . $created . '</>');
        $this->writeln('    <fg=blue>^</> Altered:   <fg=yellow>' . $altered . '</>');
        $this->writeln('    <fg=gray>-</> Unchanged: <fg=yellow>' . $ok . '</>');
        if ($errors > 0) {
            $this->writeln('    <fg=red>x</> Errors:    <fg=red>' . $errors . '</>');
        }
        $this->writeln('<fg=gray>────────────────────────────────────────────</>');
        $this->newLine();

        return $errors > 0 ? 1 : 0;
    }

    private function syncTopic(string $topicName, int $partitions, string $bootstrap, string $container): string
    {
        $describeCmd = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
            "--describe --topic {$topicName} 2>&1";
        $output = shell_exec($describeCmd);

        if ($output === null || str_contains($output, 'does not exist')) {
            // Create topic
            $createCmd = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
                "--create --topic {$topicName} --partitions {$partitions} --replication-factor 1 2>&1";
            $result = shell_exec($createCmd);

            if ($result !== null && str_contains($result, 'Error')) {
                return 'Error';
            }
            return 'Created';
        }

        // Check partition count
        preg_match('/PartitionCount:\s*(\d+)/', $output, $matches);
        $currentPartitions = (int) ($matches[1] ?? 0);

        if ($currentPartitions < $partitions) {
            $alterCmd = "docker exec {$container} kafka-topics --bootstrap-server {$bootstrap} " .
                "--alter --topic {$topicName} --partitions {$partitions} 2>&1";
            $result = shell_exec($alterCmd);

            if ($result !== null && str_contains($result, 'Error')) {
                return 'Error';
            }
            return "Altered ({$currentPartitions}→{$partitions})";
        }

        return 'OK';
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->newLine();
        $this->writeln('<fg=cyan>Available actions:</>');
        $this->writeln('  <fg=green>list</>     - List all topics with partition counts');
        $this->writeln('  <fg=green>create</>   - Create a new topic');
        $this->writeln('  <fg=green>alter</>    - Alter topic partitions');
        $this->writeln('  <fg=green>describe</> - Describe a topic');
        $this->writeln('  <fg=green>sync</>     - Sync topics from config');
        $this->newLine();
        return 1;
    }
}
