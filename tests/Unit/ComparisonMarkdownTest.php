<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Tools\ComparisonMarkdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Выбор 🏆 Победителя: все ничьи, не один пакет.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
#[CoversClass(ComparisonMarkdown::class)]
final class ComparisonMarkdownTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $order = [
        'ours',
        'phpseclib/phpseclib',
        'spatie/ssh',
        'ext-ssh2',
    ];

    /**
     * @var array<string, string>
     */
    private array $labels = [
        'ours' => 'cloud-castle/ssh',
        'phpseclib/phpseclib' => 'phpseclib',
        'spatie/ssh' => 'spatie/ssh',
        'ext-ssh2' => 'ext-ssh2',
    ];

    public function testBoolWinnerListsAllTruePackages(): void
    {
        $flags = [
            'ours' => true,
            'phpseclib/phpseclib' => true,
            'spatie/ssh' => true,
            'ext-ssh2' => true,
        ];

        self::assertSame(
            'cloud-castle/ssh, phpseclib, spatie/ssh, ext-ssh2',
            ComparisonMarkdown::boolWinner($flags, $this->order, $this->labels, 'cloud-castle/ssh'),
        );
    }

    public function testBoolWinnerOmitsFalsePackages(): void
    {
        $flags = [
            'ours' => true,
            'phpseclib/phpseclib' => true,
            'spatie/ssh' => false,
            'ext-ssh2' => true,
        ];

        self::assertSame(
            'cloud-castle/ssh, phpseclib, ext-ssh2',
            ComparisonMarkdown::boolWinner($flags, $this->order, $this->labels, 'cloud-castle/ssh'),
        );
    }

    public function testMinWinnerListsAllTiedMinima(): void
    {
        $values = [
            'ours' => 0,
            'phpseclib/phpseclib' => 0,
            'spatie/ssh' => 100,
            'ext-ssh2' => null,
        ];

        self::assertSame(
            'cloud-castle/ssh, phpseclib',
            ComparisonMarkdown::minWinner($values, $this->order, $this->labels, 'cloud-castle/ssh'),
        );
    }

    public function testMinWinnerSingleWhenStrictlyBest(): void
    {
        $values = [
            'ours' => 10,
            'phpseclib/phpseclib' => 20,
            'spatie/ssh' => 30,
            'ext-ssh2' => null,
        ];

        self::assertSame(
            'cloud-castle/ssh',
            ComparisonMarkdown::minWinner($values, $this->order, $this->labels, 'cloud-castle/ssh'),
        );
    }
}
