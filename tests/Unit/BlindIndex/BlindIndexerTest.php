<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Unit\BlindIndex;

use PHPUnit\Framework\TestCase;
use PSolutions\EncryptBundle\Attributes\BlindIndex;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexNormalizerInterface;
use PSolutions\EncryptBundle\Event\EncryptKeyEvent;
use PSolutions\EncryptBundle\Event\EncryptKeyEvents;
use PSolutions\EncryptBundle\Exception\EncryptException;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DigitsOnlyNormalizer implements BlindIndexNormalizerInterface
{
    public function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value);
    }
}

class BlindIndexerTest extends TestCase
{
    private const KEY = 'YBmNcBGfrZoayB+V254wdYa/abvxSUWJsjCtlMc1tRI=';
    private const BLIND_KEY = 'Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE=';

    private function indexer(?string $blindKey = null, string $key = self::KEY, ?EventDispatcher $dispatcher = null): BlindIndexer
    {
        return new BlindIndexer(null, $dispatcher ?? new EventDispatcher(), $key, $blindKey);
    }

    public function testDeterministicHex(): void
    {
        $hash = $this->indexer()->compute('john@example.com');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, $this->indexer()->compute('john@example.com'));
        $this->assertNotSame($hash, $this->indexer()->compute('jane@example.com'));
    }

    public function testNormalizers(): void
    {
        $indexer = $this->indexer();

        $this->assertNotSame($indexer->compute(' John@Example.com '), $indexer->compute('john@example.com'));
        $this->assertSame(
            $indexer->compute(' JOSÉ@Example.com ', BlindIndex::LOWER_TRIM),
            $indexer->compute('josé@example.com', BlindIndex::LOWER_TRIM)
        );
        $this->assertSame(
            $indexer->compute('+34 600-111-222', DigitsOnlyNormalizer::class),
            $indexer->compute('34600111222')
        );
    }

    public function testUnknownNormalizerThrows(): void
    {
        $this->expectException(EncryptException::class);
        $this->indexer()->compute('x', 'App\\Missing\\Normalizer');
    }

    public function testNullAndEmptyGiveNull(): void
    {
        $indexer = $this->indexer();

        $this->assertNull($indexer->compute(null));
        $this->assertNull($indexer->compute(''));
        $this->assertNull($indexer->compute('   ', BlindIndex::LOWER_TRIM));
    }

    public function testDerivedKeyDiffersFromEncryptKey(): void
    {
        $indexer = $this->indexer();

        $this->assertFalse($indexer->hasDedicatedKey());
        $this->assertSame(32, strlen($indexer->getKey()));
        $this->assertNotSame(base64_decode(self::KEY), $indexer->getKey());
        $this->assertSame(BlindIndexer::deriveKey(base64_decode(self::KEY)), $indexer->getKey());
        $this->assertSame(hash_hmac('sha256', 'a', $indexer->getKey()), $indexer->compute('a'));
    }

    public function testExplicitBlindIndexKey(): void
    {
        $indexer = $this->indexer(self::BLIND_KEY);

        $this->assertTrue($indexer->hasDedicatedKey());
        $this->assertSame(base64_decode(self::BLIND_KEY), $indexer->getKey());
        $this->assertNotSame($this->indexer()->compute('a'), $indexer->compute('a'));
        $this->assertSame(hash_hmac('sha256', 'a', base64_decode(self::BLIND_KEY)), $indexer->compute('a'));
    }

    public function testDerivedKeyFollowsLoadKeyOverride(): void
    {
        $other = 'q1v3l0r4b9zXk0m8qYp2b6dJ3eW5tH7uC9aN1sF0gLk=';
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EncryptKeyEvents::LOAD_KEY, static fn (EncryptKeyEvent $e) => $e->setKey($other));

        $this->assertSame($this->indexer(null, $other)->compute('a'), $this->indexer(null, self::KEY, $dispatcher)->compute('a'));
    }

    /** @dataProvider invalidKeys */
    public function testMissingOrPlaceholderKeyThrows(?string $key): void
    {
        $this->expectException(EncryptException::class);
        $this->expectExceptionMessageMatches('/encrypt:(install|genkey)/');
        $this->indexer(null, (string) $key)->compute('a');
    }

    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'placeholder' => ['change_me!'];
        yield 'short' => [base64_encode('short')];
    }
}
