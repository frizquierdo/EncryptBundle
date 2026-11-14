<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Integration;

use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Command\RotateKeyCommand;
use PSolutions\EncryptBundle\Encryptors\OpenSslEncryptor;
use PSolutions\EncryptBundle\Maintenance\DatabaseRewriter;
use PSolutions\EncryptBundle\Tests\Fixtures\OrmTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

class RotateKeyTest extends OrmTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/psolutions-rotate-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->projectDir) as $f) { if (str_starts_with($f, '.env')) { unlink($this->projectDir . '/' . $f); } }
        @rmdir($this->projectDir);
    }

    private function seed(): void
    {
        $this->insertUser('a@x.com', 'Ana');
        $this->insertUser('b@x.com', 'Bea');
        $this->insertUser(null, null);
    }

    private function rotate(array $input): CommandTester
    {
        $command = new RotateKeyCommand(
            $this->rewriter(), $this->encryptor, $this->indexer, $this->registry, new EventDispatcher(),
            ['default'], OpenSslEncryptor::class, $this->projectDir, 'test'
        );
        $tester = new CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    private function encryptorFor(string $key): OpenSslEncryptor
    {
        $encryptor = new OpenSslEncryptor(new EventDispatcher());
        $encryptor->setSecretKey($key);

        return $encryptor;
    }

    private function snapshot(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM users ORDER BY id');
    }

    /** Every encrypted value decrypts with $encryptKey and every index matches $blindKey. */
    private function assertCoherent(string $encryptKey, string $binaryBlindKey): void
    {
        $encryptor = $this->encryptorFor($encryptKey);
        $plain = [1 => ['a@x.com', 'Ana'], 2 => ['b@x.com', 'Bea'], 3 => [null, null]];

        foreach ($this->snapshot() as $row) {
            [$email, $name] = $plain[$row['id']];
            $this->assertSame($email, $encryptor->decrypt($row['email']));
            $this->assertSame($name, $encryptor->decrypt($row['name']));
            $this->assertSame($this->indexer->compute($email, 'lower_trim', $binaryBlindKey), $row['email_hash']);
        }
    }

    public function testRotateEncryptKeyWithDerivedIndexKey(): void
    {
        $this->seed();
        $tester = $this->rotate(['--encrypt-key' => self::KEY2]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertCoherent(self::KEY2, BlindIndexer::deriveKey(base64_decode(self::KEY2)));
        $this->assertStringContainsString('PSOLUTIONS_ENCRYPT_KEY=' . self::KEY2, $tester->getDisplay());
        $this->assertStringContainsString('cache:clear', $tester->getDisplay());
    }

    public function testRotateBlindIndexKeyOnlyKeepsCiphertext(): void
    {
        $this->boot(self::KEY, self::BLIND_KEY);
        $this->seed();
        $before = $this->snapshot();
        $newBlind = base64_encode(random_bytes(32));

        $tester = $this->rotate(['--blind-index-key' => $newBlind]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(array_column($before, 'email'), array_column($this->snapshot(), 'email'));
        $this->assertCoherent(self::KEY, base64_decode($newBlind));
    }

    public function testRotateEncryptKeyOnlyKeepsDedicatedIndexes(): void
    {
        $this->boot(self::KEY, self::BLIND_KEY);
        $this->seed();
        $before = $this->snapshot();

        $tester = $this->rotate(['--encrypt-key' => self::KEY2]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(array_column($before, 'email_hash'), array_column($this->snapshot(), 'email_hash'));
        $this->assertNotSame(array_column($before, 'email'), array_column($this->snapshot(), 'email'));
        $this->assertCoherent(self::KEY2, base64_decode(self::BLIND_KEY));
    }

    public function testRotateBothKeysWithoutValuesGeneratesThem(): void
    {
        $this->boot(self::KEY, self::BLIND_KEY);
        $this->seed();

        $tester = $this->rotate(['--write-env' => null]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $env = file_get_contents($this->projectDir . '/.env');
        $this->assertMatchesRegularExpression('/^PSOLUTIONS_ENCRYPT_KEY=(\S{44})$/m', $env);
        preg_match('/^PSOLUTIONS_ENCRYPT_KEY=(\S+)$/m', $env, $e);
        preg_match('/^PSOLUTIONS_BLIND_INDEX_KEY=(\S+)$/m', $env, $b);
        $this->assertCoherent($e[1], base64_decode($b[1]));
        $this->assertStringNotContainsString($e[1], $tester->getDisplay(), 'Keys written to the env file are not printed.');
    }

    public function testWriteEnvReplacesVariableEverywhereItIsDefined(): void
    {
        $this->seed();
        file_put_contents($this->projectDir . '/.env', "APP_ENV=dev\n###> psolutions/encrypt-bundle ###\nPSOLUTIONS_ENCRYPT_KEY=" . self::KEY . "\n###< psolutions/encrypt-bundle ###\n");
        file_put_contents($this->projectDir . '/.env.local', 'PSOLUTIONS_ENCRYPT_KEY=' . self::KEY . "\n");

        $tester = $this->rotate(['--encrypt-key' => self::KEY2, '--write-env' => null]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        foreach (['.env', '.env.local'] as $file) {
            $contents = file_get_contents($this->projectDir . '/' . $file);
            $this->assertSame(1, substr_count($contents, 'PSOLUTIONS_ENCRYPT_KEY='), $file);
            $this->assertStringContainsString('PSOLUTIONS_ENCRYPT_KEY=' . self::KEY2, $contents, $file);
        }
        $this->assertStringStartsWith('APP_ENV=dev', file_get_contents($this->projectDir . '/.env'));
    }

    public function testBlindIndexKeyRequiresDedicatedKey(): void
    {
        $tester = $this->rotate(['--blind-index-key' => null]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('encrypt:install --blind-index-key', $tester->getDisplay());
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->seed();
        $before = $this->snapshot();

        $tester = $this->rotate(['--encrypt-key' => self::KEY2, '--dry-run' => true, '--write-env' => null]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('2 row(s) would change', $tester->getDisplay()); // row 3 is all null
        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->projectDir . '/.env');
    }

    public function testFailureHalfwayRollsBack(): void
    {
        $this->seed();
        $before = $this->snapshot();
        $writes = 0;

        $report = $this->rewriter()->rewrite(
            managers: ['default' => $this->em],
            sourceEncryptor: $this->encryptor,
            targetEncryptor: $this->encryptorFor(self::KEY2),
            dataMode: DatabaseRewriter::DATA_REENCRYPT,
            blindIndexKey: BlindIndexer::deriveKey(base64_decode(self::KEY2)),
            batchSize: 1,
            afterWrite: static function () use (&$writes): void {
                if (2 === ++$writes) {
                    throw new \RuntimeException('Injected failure');
                }
            },
        );

        $this->assertFalse($report->committed);
        $this->assertStringContainsString('Injected failure', implode("\n", $report->errors));
        $this->assertSame($before, $this->snapshot());
    }

    public function testUndecryptableRowAbortsBeforeWriting(): void
    {
        $this->seed();
        $this->em->getConnection()->executeStatement('UPDATE users SET name = ? WHERE id = 2', [$this->encryptorFor(self::KEY2)->encrypt('x')]);
        $before = $this->snapshot();

        $tester = $this->rotate(['--encrypt-key' => base64_encode(random_bytes(32))]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('#2', $tester->getDisplay());
        $this->assertSame($before, $this->snapshot());
    }

    public function testCollisionRollsBack(): void
    {
        $this->seed();
        $connection = $this->em->getConnection();
        // Legacy duplicate (different case) with a stale index: the new index of row 2 collides with row 1.
        $connection->executeStatement('UPDATE users SET email = ?, email_hash = ? WHERE id = 2', [$this->encryptor->encrypt('A@x.com'), str_repeat('0', 64)]);
        $before = $this->snapshot();

        $tester = $this->rotate(['--encrypt-key' => self::KEY2]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Collisions', $tester->getDisplay());
        $this->assertSame($before, $this->snapshot());
    }
}
