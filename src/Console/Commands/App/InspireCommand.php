<?php

declare(strict_types=1);

namespace Toporia\Framework\Console\Commands\App;

use Toporia\Framework\Console\Command;

/**
 * Class InspireCommand
 *
 * Display an inspiring quote about programming and software development.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Console\Commands\App
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
final class InspireCommand extends Command
{
    protected string $signature = 'inspire';

    protected string $description = 'Display an inspiring quote';

    private array $quotes = [
        '"Simplicity is the ultimate sophistication." - Leonardo da Vinci',
        '"Clean code always looks like it was written by someone who cares." - Robert C. Martin',
        '"The best way to predict the future is to implement it." - David Heinemeier Hansson',
        '"First, solve the problem. Then, write the code." - John Johnson',
        '"Any fool can write code that a computer can understand. Good programmers write code that humans can understand." - Martin Fowler',
        '"Programs must be written for people to read, and only incidentally for machines to execute." - Harold Abelson',
        '"The only way to go fast is to go well." - Robert C. Martin',
        '"Make it work, make it right, make it fast." - Kent Beck',
        '"Code is like humor. When you have to explain it, it\'s bad." - Cory House',
        '"Perfection is achieved not when there is nothing more to add, but rather when there is nothing more to take away." - Antoine de Saint-Exupéry',
        '"The best error message is the one that never shows up." - Thomas Fuchs',
        '"Walking on water and developing software from a specification are easy if both are frozen." - Edward V. Berard',
        '"It\'s not a bug – it\'s an undocumented feature." - Anonymous',
        '"Before software can be reusable it first has to be usable." - Ralph Johnson',
        '"Talk is cheap. Show me the code." - Linus Torvalds',
    ];

    public function handle(): int
    {
        $quote = $this->quotes[array_rand($this->quotes)];

        $this->newLine();
        $this->info($quote);
        $this->newLine();

        return 0;
    }
}
