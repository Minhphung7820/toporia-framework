<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\Mail;

use Toporia\Framework\Console\Command;
use Toporia\Framework\Mail\Transport\SmtpConnectionPool;

/**
 * Class MailPoolStatsCommand
 *
 * Display SMTP connection pool statistics and performance metrics.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\Mail
 * @since       2025-12-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class MailPoolStatsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected string $signature = 'mail:pool-stats';

    /**
     * {@inheritdoc}
     */
    protected string $description = 'Display SMTP connection pool statistics';

    /**
     * {@inheritdoc}
     */
    public function handle(): int
    {
        $this->info('📊 SMTP Connection Pool Statistics');
        $this->line(str_repeat('─', 64));
        $this->newLine();

        $stats = SmtpConnectionPool::getStats();

        if ($stats['total'] === 0) {
            $this->warn('No active connections in pool');
            $this->newLine();
            return self::SUCCESS;
        }

        $this->info("Total Connections: {$stats['total']}");
        $this->newLine();

        foreach ($stats['connections'] as $key => $conn) {
            $healthIcon = $conn['healthy'] ? '✅' : '❌';
            $this->line("Connection: {$key}");
            $this->line("  Status:   {$healthIcon} " . ($conn['healthy'] ? 'Healthy' : 'Unhealthy'));
            $this->line("  Age:      {$conn['age']}s");
            $this->line("  Uses:     {$conn['uses']}");
            $this->newLine();
        }

        $this->info('💡 Tips:');
        $this->line('  - Healthy connections are reused automatically');
        $this->line('  - Connections expire after 5 minutes');
        $this->line('  - Maximum 100 uses per connection');
        $this->newLine();

        return self::SUCCESS;
    }
}

