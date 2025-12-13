<?php

declare(strict_types=1);

namespace Toporia\Framework\Mail\Contracts;


/**
 * Interface MailManagerInterface
 *
 * Contract defining the interface for MailManagerInterface implementations
 * in the Email sending and queuing layer of the Toporia Framework.
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
interface MailManagerInterface extends MailerInterface
{
    /**
     * Get a mailer driver instance.
     *
     * @param string|null $driver Driver name (null = default).
     * @return MailerInterface
     */
    public function driver(?string $driver = null): MailerInterface;

    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string;
}
