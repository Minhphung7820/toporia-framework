<?php

declare(strict_types=1);

namespace Toporia\Framework\Testing\Concerns;


/**
 * Trait InteractsWithMail
 *
 * Trait providing reusable functionality for InteractsWithMail in the
 * Concerns layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Concerns
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
trait InteractsWithMail
{
    /**
     * Fake mail (disable real sending).
     */
    protected bool $fakeMail = false;

    /**
     * Sent mails.
     *
     * @var array
     */
    protected array $sentMails = [];

    /**
     * Fake mail.
     *
     * Performance: O(1)
     */
    protected function fakeMail(): void
    {
        $this->fakeMail = true;
        $this->sentMails = [];
    }

    /**
     * Assert that a mail was sent.
     *
     * Performance: O(N) where N = number of mails
     */
    protected function assertMailSent(string $to, string $subject = null): void
    {
        $found = false;

        foreach ($this->sentMails as $mail) {
            if ($mail['to'] === $to) {
                if ($subject === null || $mail['subject'] === $subject) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, "Mail to {$to} was not sent");
    }

    /**
     * Assert that a mail was not sent.
     *
     * Performance: O(N) where N = number of mails
     */
    protected function assertMailNotSent(string $to): void
    {
        $found = false;

        foreach ($this->sentMails as $mail) {
            if ($mail['to'] === $to) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Mail to {$to} was unexpectedly sent");
    }

    /**
     * Record a sent mail.
     *
     * Performance: O(1)
     */
    protected function recordMail(string $to, string $subject, string $body): void
    {
        $this->sentMails[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Cleanup mail after test.
     */
    protected function tearDownMail(): void
    {
        $this->sentMails = [];
        $this->fakeMail = false;
    }
}

