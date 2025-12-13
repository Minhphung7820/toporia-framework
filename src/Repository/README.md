# Repository Pattern - Toporia Framework

## Giới thiệu

Repository Pattern cung cấp một lớp trừu tượng giữa business logic và data access layer. Nó giúp:
- Tách biệt logic truy vấn khỏi business logic
- Dễ dàng test với mock
- Tái sử dụng query logic qua Criteria Pattern
- Tích hợp caching và events

## Cách sử dụng

### 1. Tạo Model

```php
<?php

namespace App\Models;

use Toporia\Framework\Database\ORM\Model;
use Toporia\Framework\Database\ORM\Concerns\SoftDeletes;

class User extends Model
{
    use SoftDeletes; // Optional: hỗ trợ soft delete

    protected static string $table = 'users';
    protected static string $primaryKey = 'id';
    protected static array $fillable = ['name', 'email', 'password'];
    protected static array $hidden = ['password'];
}
```

### 2. Tạo Repository

```php
<?php

namespace App\Repositories;

use App\Models\User;
use Toporia\Framework\Repository\BaseRepository;

class UserRepository extends BaseRepository
{
    /**
     * Model class được sử dụng
     */
    protected string $model = User::class;

    /**
     * Criteria mặc định (optional)
     */
    protected function getDefaultCriteria(): array
    {
        return [
            // new ActiveCriteria(),
        ];
    }

    /**
     * Custom method cho repository
     */
    public function findByEmail(string $email): ?User
    {
        return $this->findBy('email', $email);
    }

    public function findActiveUsers(): ModelCollection
    {
        return $this->pushCriteria(ActiveCriteria::active())
            ->orderBy('created_at', 'desc')
            ->all();
    }
}
```

### 3. Đăng ký trong Service Provider

```php
<?php

namespace App\Providers;

use App\Repositories\UserRepository;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind repository
        $this->container->singleton(UserRepository::class, function ($container) {
            $repo = new UserRepository($container);

            // Optional: Set cache manager
            if ($container->has(CacheManagerInterface::class)) {
                $repo->setCacheManager($container->get(CacheManagerInterface::class));
            }

            // Optional: Set event dispatcher
            if ($container->has(EventDispatcherInterface::class)) {
                $repo->setEventDispatcher($container->get(EventDispatcherInterface::class));
            }

            return $repo;
        });
    }
}
```

### 4. Sử dụng Repository

```php
<?php

class UserController
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    // === READ OPERATIONS ===

    public function index()
    {
        // Lấy tất cả users
        $users = $this->userRepository->all();

        // Lấy với pagination
        $users = $this->userRepository->paginate(15, 1);

        // Cursor pagination (hiệu năng cao cho dataset lớn)
        $users = $this->userRepository->cursorPaginate(50);
    }

    public function show(int $id)
    {
        // Tìm theo ID
        $user = $this->userRepository->find($id);

        // Tìm hoặc throw exception
        $user = $this->userRepository->findOrFail($id);

        // Tìm theo field khác
        $user = $this->userRepository->findBy('email', 'john@example.com');

        // Tìm nhiều theo IDs
        $users = $this->userRepository->findMany([1, 2, 3]);
    }

    // === CREATE OPERATIONS ===

    public function store(array $data)
    {
        // Tạo mới
        $user = $this->userRepository->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
        ]);

        // Tạo nhiều
        $users = $this->userRepository->createMany([
            ['name' => 'User 1', 'email' => 'user1@example.com'],
            ['name' => 'User 2', 'email' => 'user2@example.com'],
        ]);

        // First or Create
        $user = $this->userRepository->firstOrCreate(
            ['email' => 'john@example.com'], // Criteria tìm
            ['name' => 'John Doe']           // Attributes nếu tạo mới
        );
    }

    // === UPDATE OPERATIONS ===

    public function update(int $id, array $data)
    {
        // Update theo ID
        $user = $this->userRepository->update($id, [
            'name' => $data['name'],
        ]);

        // Update or Create
        $user = $this->userRepository->updateOrCreate(
            ['email' => 'john@example.com'], // Criteria tìm
            ['name' => 'John Updated']       // Attributes update
        );

        // Bulk upsert
        $affected = $this->userRepository->upsert(
            [
                ['email' => 'user1@example.com', 'name' => 'User 1', 'score' => 100],
                ['email' => 'user2@example.com', 'name' => 'User 2', 'score' => 200],
            ],
            ['email'],        // Unique columns
            ['name', 'score'] // Columns to update
        );
    }

    // === DELETE OPERATIONS ===

    public function destroy(int $id)
    {
        // Soft delete (nếu Model có SoftDeletes trait)
        $this->userRepository->delete($id);

        // Hard delete
        $this->userRepository->forceDelete($id);

        // Delete theo điều kiện
        $deleted = $this->userRepository->deleteWhere(['status' => 'inactive']);
    }

    // === SOFT DELETE OPERATIONS ===

    public function trash()
    {
        // Lấy chỉ records đã xóa
        $trashedUsers = $this->userRepository
            ->onlyTrashed()
            ->all();
    }

    public function restore(int $id)
    {
        // Khôi phục record đã soft delete
        $this->userRepository->restore($id);
    }

    public function withTrashed()
    {
        // Lấy tất cả bao gồm đã xóa
        $allUsers = $this->userRepository
            ->withTrashed()
            ->all();
    }
}
```

## Criteria Pattern

Criteria cho phép đóng gói query logic để tái sử dụng:

### Built-in Criteria

```php
use Toporia\Framework\Repository\Criteria\{
    ActiveCriteria,
    DateRangeCriteria,
    OrderByCriteria,
    SearchCriteria,
    WhereCriteria,
    WhereInCriteria,
    WhereNullCriteria,
    ScopeCriteria
};

// Active/Inactive records
$users = $repo->pushCriteria(ActiveCriteria::active())->all();
$users = $repo->pushCriteria(ActiveCriteria::isActive(true))->all();
$users = $repo->pushCriteria(ActiveCriteria::published())->all();

// Date range
$users = $repo->pushCriteria(DateRangeCriteria::today())->all();
$users = $repo->pushCriteria(DateRangeCriteria::thisMonth())->all();
$users = $repo->pushCriteria(DateRangeCriteria::lastDays(7))->all();
$users = $repo->pushCriteria(new DateRangeCriteria('created_at', '2024-01-01', '2024-12-31'))->all();

// Search across columns
$users = $repo->pushCriteria(new SearchCriteria('john', ['name', 'email']))->all();
$users = $repo->pushCriteria(SearchCriteria::exactMatch('john@example.com', ['email']))->all();

// Where conditions
$users = $repo->pushCriteria(new WhereCriteria('status', 'active'))->all();
$users = $repo->pushCriteria(new WhereCriteria('age', '>=', 18))->all();
$users = $repo->pushCriteria(new WhereInCriteria('role', ['admin', 'moderator']))->all();
$users = $repo->pushCriteria(WhereNullCriteria::notNull('email_verified_at'))->all();

// Ordering
$users = $repo->pushCriteria(OrderByCriteria::desc('created_at'))->all();
$users = $repo->pushCriteria(OrderByCriteria::multiple([
    ['column' => 'priority', 'direction' => 'desc'],
    ['column' => 'name', 'direction' => 'asc'],
]))->all();

// Custom scope
$users = $repo->pushCriteria(ScopeCriteria::from(function ($query) {
    $query->where('role', 'admin')
          ->whereNotNull('verified_at');
}))->all();
```

### Tạo Custom Criteria

```php
<?php

namespace App\Repositories\Criteria;

use Toporia\Framework\Database\ORM\ModelQueryBuilder;
use Toporia\Framework\Repository\Contracts\CriteriaInterface;
use Toporia\Framework\Repository\Contracts\RepositoryInterface;

class PremiumUsersCriteria implements CriteriaInterface
{
    public function __construct(
        private int $minPurchases = 10
    ) {}

    public function apply(ModelQueryBuilder $query, RepositoryInterface $repository): ModelQueryBuilder
    {
        return $query
            ->where('is_premium', true)
            ->where('total_purchases', '>=', $this->minPurchases)
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '>', now());
    }
}

// Sử dụng
$premiumUsers = $userRepo
    ->pushCriteria(new PremiumUsersCriteria(20))
    ->orderBy('total_purchases', 'desc')
    ->all();
```

## Eager Loading

```php
// Load relationships
$users = $userRepo
    ->with(['posts', 'profile'])
    ->all();

// Nested eager loading
$users = $userRepo
    ->with(['posts.comments', 'profile.avatar'])
    ->find($id);
```

## Caching

```php
// Repository tự động cache các query
$users = $userRepo->all(); // Cached

// Skip cache cho query này
$users = $userRepo->skipCache()->all();

// Disable cache
$userRepo->disableCache();

// Clear cache
$userRepo->clearCache();
$userRepo->clearCacheFor($userId);

// Custom TTL
$userRepo->cacheFor(3600)->all(); // Cache 1 hour
```

## Events

Repository tự động fire events cho các operations:

```php
// Listen to events
$userRepo->listen('creating', function ($attributes, $repo) {
    // Trước khi tạo
});

$userRepo->listen('created', function ($entity, $repo) {
    // Sau khi tạo
});

$userRepo->listen('updating', function ($entity, $attributes, $repo) {
    // Trước khi update
});

$userRepo->listen('deleted', function ($entity, $repo) {
    // Sau khi xóa
});

// Disable events tạm thời
$userRepo->withoutEvents(function () use ($userRepo) {
    $userRepo->create([...]);
});
```

## Aggregate Methods

```php
// Count
$total = $userRepo->count();
$activeCount = $userRepo->pushCriteria(ActiveCriteria::active())->count();

// Exists
$exists = $userRepo->exists($id);
$exists = $userRepo->existsWhere(['email' => 'john@example.com']);

// Sum, Min, Max, Avg
$totalScore = $userRepo->sum('score');
$minAge = $userRepo->min('age');
$maxAge = $userRepo->max('age');
$avgScore = $userRepo->avg('score');

// Pluck
$names = $userRepo->pluck('name');
$nameById = $userRepo->pluck('name', 'id');
```

## Chunking (Memory Efficient)

```php
// Process large datasets in chunks
$userRepo->chunk(100, function ($users, $repo) {
    foreach ($users as $user) {
        // Process user
    }
    return true; // Continue processing
});

// Process each entity
$userRepo->each(100, function ($user, $repo) {
    // Process single user
    return true;
});
```

## Method Chaining

```php
$users = $userRepo
    ->with(['posts', 'profile'])
    ->pushCriteria(ActiveCriteria::active())
    ->pushCriteria(DateRangeCriteria::thisMonth())
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->all();
```

## Best Practices

1. **Một Repository cho một Model** - Mỗi entity nên có repository riêng
2. **Sử dụng Criteria** - Đóng gói query logic phức tạp vào Criteria classes
3. **Inject Repository** - Sử dụng dependency injection thay vì khởi tạo trực tiếp
4. **Tận dụng Caching** - Enable caching cho các query thường xuyên
5. **Listen Events** - Sử dụng events cho side effects (logging, notifications)
