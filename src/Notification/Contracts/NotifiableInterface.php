<?php

declare(strict_types=1);

namespace Toporia\Framework\Notification\Contracts;


/**
 * Interface NotifiableInterface
 *
 * Contract defining the interface for NotifiableInterface implementations
 * in the Multi-channel notifications layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Notification\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface NotifiableInterface
{
    /**
     * Get routing information for a notification channel.
     *
     * Returns channel-specific delivery address:
     * - 'mail': Email address (string)
     * - 'sms': Phone number (string)
     * - 'slack': Webhook URL (string)
     * - 'database': User ID or identifier (string|int)
     * - Custom channels: Any routing data
     *
     * Performance: O(1) - Direct property access
     *
     * @param string $channel Channel name
     * @return mixed Channel-specific routing data (null if channel not supported)
     */
    public function routeNotificationFor(string $channel): mixed;
}
