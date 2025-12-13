<?php

declare(strict_types=1);

namespace Toporia\Framework\Observer\Contracts;


/**
 * Interface ObserverInterface
 *
 * Contract defining the interface for ObserverInterface implementations in
 * the Observer layer of the Toporia Framework.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Observer\Contracts
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
interface ObserverInterface
{
    /**
     * Handle the update notification from an observable.
     *
     * This method is called when the observed object changes state.
     *
     * @param ObservableInterface $observable The observable object that changed
     * @param string $event The event that occurred (e.g., 'created', 'updated', 'deleted')
     * @param array<string, mixed> $data Additional data about the change
     * @return void
     */
    public function update(ObservableInterface $observable, string $event, array $data = []): void;
}

