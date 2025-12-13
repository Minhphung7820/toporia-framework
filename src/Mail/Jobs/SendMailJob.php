<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Jobs;

use Toporia\Framework\Queue\Job;
use Toporia\Framework\Mail\Contracts\{MailerInterface, MessageInterface};

/**
 * Class SendMailJob
 *
 * Queue job for sending emails asynchronously.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Jobs
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class SendMailJob extends Job
{
    /**
     * @param MessageInterface $message Email message to send.
     */
    public function __construct(
        private MessageInterface $message
    ) {
        parent::__construct();
        $this->tries(3);
        $this->onQueue('emails');
    }

    /**
     * Execute the job.
     *
     * @param MailerInterface $mailer Mailer instance (auto-injected).
     * @return void
     */
    public function handle(MailerInterface $mailer): void
    {
        $success = $mailer->send($this->message);

        if (!$success) {
            throw new \RuntimeException('Failed to send email');
        }

        error_log("Email sent to: " . implode(', ', $this->message->getTo()));
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        error_log("Failed to send email: " . $exception->getMessage());
    }
}
