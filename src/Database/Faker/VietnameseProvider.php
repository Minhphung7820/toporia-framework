<?php

declare(strict_types=1);

namespace Toporia\Framework\Database\Faker;

use Toporia\Framework\Database\Contracts\FakerProviderInterface;
use Faker\Generator;


/**
 * Class VietnameseProvider
 *
 * Service provider for registering and bootstrapping Faker services in the
 * Toporia Framework application.
 *
 * @author      Phungtruong7820 <minhphung485@gmail.com>
 * @copyright   Copyright (c) 2025 Toporia Framework
 * @license     MIT
 * @version     1.0.0
 * @package     toporia/framework
 * @subpackage  Faker
 * @since       2025-01-10
 *
 * @link        https://github.com/Minhphung7820/toporia
 */
class VietnameseProvider implements FakerProviderInterface
{
    /**
     * Vietnamese first names.
     *
     * @var array<int, string>
     */
    private array $firstNames = [
        'An',
        'Bình',
        'Cường',
        'Dũng',
        'Đức',
        'Hùng',
        'Minh',
        'Nam',
        'Quang',
        'Tuấn',
        'Anh',
        'Hà',
        'Lan',
        'Linh',
        'Mai',
        'Ngọc',
        'Phương',
        'Thảo',
        'Thu',
        'Vy'
    ];

    /**
     * Vietnamese last names.
     *
     * @var array<int, string>
     */
    private array $lastNames = [
        'Nguyễn',
        'Trần',
        'Lê',
        'Phạm',
        'Hoàng',
        'Huỳnh',
        'Phan',
        'Vũ',
        'Võ',
        'Đặng',
        'Bùi',
        'Đỗ',
        'Hồ',
        'Ngô',
        'Dương',
        'Lý',
        'Đinh',
        'Đào',
        'Tô',
        'Tôn'
    ];

    /**
     * Vietnamese middle names.
     *
     * @var array<int, string>
     */
    private array $middleNames = [
        'Văn',
        'Thị',
        'Đức',
        'Minh',
        'Quang',
        'Hữu',
        'Công',
        'Duy',
        'Tuấn',
        'Mạnh'
    ];

    /**
     * Vietnamese cities.
     *
     * @var array<int, string>
     */
    private array $cities = [
        'Hà Nội',
        'Hồ Chí Minh',
        'Đà Nẵng',
        'Hải Phòng',
        'Cần Thơ',
        'An Giang',
        'Bà Rịa - Vũng Tàu',
        'Bắc Giang',
        'Bắc Kạn',
        'Bạc Liêu'
    ];

    /**
     * Vietnamese districts.
     *
     * @var array<int, string>
     */
    private array $districts = [
        'Quận 1',
        'Quận 2',
        'Quận 3',
        'Quận 4',
        'Quận 5',
        'Huyện Bình Chánh',
        'Huyện Cần Giờ',
        'Huyện Củ Chi',
        'Huyện Hóc Môn'
    ];

    /**
     * {@inheritdoc}
     */
    public function register(Generator $generator): void
    {
        $generator->addProvider($this);
    }

    /**
     * Generate Vietnamese full name.
     *
     * @return string
     */
    public function vietnameseName(): string
    {
        $firstName = $this->firstNames[array_rand($this->firstNames)];
        $middleName = $this->middleNames[array_rand($this->middleNames)];
        $lastName = $this->lastNames[array_rand($this->lastNames)];

        return trim("{$lastName} {$middleName} {$firstName}");
    }

    /**
     * Generate Vietnamese phone number.
     *
     * @return string
     */
    public function vietnamesePhoneNumber(): string
    {
        $prefixes = ['090', '091', '092', '093', '094', '096', '097', '098', '099', '032', '033', '034', '035', '036', '037', '038', '039'];
        $prefix = $prefixes[array_rand($prefixes)];
        $number = sprintf('%07d', mt_rand(0, 9999999));

        return $prefix . $number;
    }

    /**
     * Generate Vietnamese address.
     *
     * @return string
     */
    public function vietnameseAddress(): string
    {
        $streetNumber = mt_rand(1, 999);
        $streetName = $this->streetName();
        $ward = $this->districts[array_rand($this->districts)];
        $city = $this->cities[array_rand($this->cities)];

        return "{$streetNumber} {$streetName}, {$ward}, {$city}";
    }

    /**
     * Generate Vietnamese street name.
     *
     * @return string
     */
    public function streetName(): string
    {
        $streets = [
            'Nguyễn Trãi',
            'Lê Lợi',
            'Trần Hưng Đạo',
            'Hai Bà Trưng',
            'Lý Thường Kiệt',
            'Võ Văn Tần',
            'Điện Biên Phủ',
            'Hoàng Diệu'
        ];

        $prefixes = ['Đường', 'Phố'];
        $prefix = $prefixes[array_rand($prefixes)];
        $street = $streets[array_rand($streets)];

        return "{$prefix} {$street}";
    }
}
