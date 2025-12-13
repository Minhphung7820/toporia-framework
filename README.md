# Toporia Framework

A modern PHP framework built with Clean Architecture principles.

## About

Toporia is a PHP framework designed for developers who appreciate clean, maintainable code. While the developer experience (DX) may feel familiar to those who have worked with popular PHP frameworks, **Toporia is built entirely from scratch** - not a fork, clone, or copy of any existing framework.

### Philosophy

- **Inspired, Not Copied**: The API design draws inspiration from well-established patterns in the PHP ecosystem, but every line of code is original
- **Clean Architecture**: Strict separation between Framework and Application layers
- **Zero Dependencies Core**: The framework core has minimal external dependencies
- **Modern PHP**: Built for PHP 8.1+ with full type safety and modern language features

## Features

### Core Components

- **Container** - PSR-11 compatible DI container with auto-wiring
- **Routing** - Fluent OOP router with middleware pipeline
- **HTTP** - Request/Response abstraction (PSR-7 inspired)
- **Events** - Priority-based event dispatcher (PSR-14 inspired)
- **Console** - CLI command framework with scheduling support
- **Bus** - Command/Query dispatcher with queue support
- **Database** - ORM, Query Builder, Migrations
- **Queue** - Async job processing (Database, Redis, Sync drivers)
- **Cache** - Multi-driver caching (File, Redis, Memory)
- **Auth** - Authentication & Authorization (Session, Token, Gates, Policies)
- **Validation** - Form request validation with 70+ built-in rules
- **Logging** - PSR-3 logger with daily rotation
- **Realtime** - Broadcasting (Redis, RabbitMQ, Kafka brokers)
- **Search** - Elasticsearch integration
- **Concurrency** - Multi-process execution support

### Additional Features

- OAuth/Social Authentication
- Email with queue support
- Excel import/export (streaming, chunking)
- Webhook handling
- Task scheduling
- And more...

## Installation

```bash
composer require toporia/framework
```

## Requirements

- PHP >= 8.1
- Composer

### Optional Extensions

- `ext-redis` - Redis cache, queue drivers, and realtime broker
- `ext-pdo_mysql` / `ext-pdo_pgsql` / `ext-pdo_sqlite` - Database support
- `ext-pcntl` - Multi-process execution (Linux/macOS only)

## Quick Start

```php
<?php

use Toporia\Framework\Foundation\Application;
use Toporia\Framework\Routing\Router;

// Bootstrap
$app = new Application(__DIR__);

// Define routes
$router = $app->get(Router::class);
$router->get('/', fn() => response()->json(['message' => 'Hello, Toporia!']));

// Run
$app->run();
```

## Documentation

Detailed documentation is available in the `/docs` directory of the main repository.

## A Note from the Author

This framework was built as a solo project through **vibe coding** - late nights, countless iterations, and a passion for clean code. It represents hundreds of hours of work, learning, and experimentation.

### Current Status

As a one-person project, there are areas that may need improvement:

- Some features may not be fully optimized
- Edge cases might not all be covered
- Documentation is continuously being improved
- Bug reports are welcome and appreciated

### Contributing

I welcome contributions from the community! Whether it's:

- Bug reports
- Feature suggestions
- Code improvements
- Documentation fixes
- Performance optimizations

Every contribution helps make Toporia better. Please feel free to:

1. Open an issue to discuss changes
2. Submit a pull request
3. Share feedback and suggestions

Your input is valuable and appreciated!

## License

Toporia Framework is open-sourced software licensed under the [MIT license](LICENSE).

## Author

**Phungtruong7820** - [GitHub](https://github.com/Minhphung7820)

---

*Built with passion. Inspired by the best. Created from scratch.*
