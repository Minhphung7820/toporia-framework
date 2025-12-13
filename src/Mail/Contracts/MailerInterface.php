<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Contracts;

use Toporia\Framework\Mail\Mailable;


/**
 * Interface MailerInterface
 *
 * Contract defining the interface for MailerInterface implementations in
 * the Email sending and queuing layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Mail\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface MailerInterface
{
    /**
     * Send an email using Message object.
     *
     * @param MessageInterface $message Email message to send
     * @return bool True on success, false on failure
     * @throws \RuntimeException If sending fails
     */
    public function send(MessageInterface $message): bool;

    /**
     * Send a Mailable.
     *
     * @param Mailable $mailable Mailable instance
     * @return bool True on success
     * @throws \RuntimeException If sending fails
     */
    public function sendMailable(Mailable $mailable): bool;

    /**
     * Queue an email for async sending.
     *
     * @param MessageInterface $message Email message to queue
     * @param int $delay Delay in seconds
     * @return bool True on success
     * @throws \RuntimeException If queue not available
     */
    public function queue(MessageInterface $message, int $delay = 0): bool;

    /**
     * Queue a Mailable for async sending.
     *
     * @param Mailable $mailable Mailable instance
     * @param int $delay Delay in seconds
     * @return bool True on success
     * @throws \RuntimeException If queue not available
     */
    public function queueMailable(Mailable $mailable, int $delay = 0): bool;
}
