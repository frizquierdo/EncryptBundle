<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Integration;

use PSolutions\EncryptBundle\Command\BlindIndexRebuildCommand;
use PSolutions\EncryptBundle\Command\EncryptDatabaseCommand;
use PSolutions\EncryptBundle\Tests\Fixtures\Entity\User;
use PSolutions\EncryptBundle\Tests\Fixtures\OrmTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BlindIndexRebuildTest extends OrmTestCase
{
    private function rebuild(array $input = []): CommandTester
    {
        $tester = new CommandTester(new BlindIndexRebuildCommand($this->rewriter(), $this->encryptor, $this->registry));
        $tester->execute($input);

        return $tester;
    }

    private function hashes(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT email_hash FROM users ORDER BY id');
    }

    public function testRebuildsInBatches(): void
    {
        foreach (['a@x.com', 'b@x.com', 'c@x.com', null] as $email) {
            $this->insertUser($email);
        }
        $expected = $this->hashes();
        $this->em->getConnection()->executeStatement('UPDATE users SET email_hash = NULL');

        $tester = $this->rebuild(['--batch-size' => '1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($expected, $this->hashes());
        $this->assertStringContainsString('3 row(s) updated', $tester->getDisplay());

        $this->assertStringContainsString('up to date', $this->rebuild()->getDisplay());
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->insertUser('a@x.com');
        $this->em->getConnection()->executeStatement('UPDATE users SET email_hash = NULL');

        $tester = $this->rebuild(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1 row(s) would change', $tester->getDisplay());
        $this->assertSame([null], $this->hashes());
    }

    public function testCollisionsAreListedAndNothingIsWritten(): void
    {
        foreach (['a@x.com', 'b@x.com', 'A@X.com '] as $email) {
            $this->insertUser($email === 'A@X.com ' ? 'other@x.com' : $email);
        }
        // Simulate legacy data: same email with different case, no index yet.
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE users SET email = ?, email_hash = NULL WHERE id = 3', [$this->encryptor->encrypt('A@X.com ')]);
        $connection->executeStatement('UPDATE users SET email_hash = NULL WHERE id = 2');
        $before = $this->hashes();

        foreach ([[], ['--dry-run' => true]] as $input) {
            $tester = $this->rebuild($input + ['--batch-size' => '1']);
            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertMatchesRegularExpression('/User\s+emailHash\s+1, 3/', $tester->getDisplay());
            $this->assertSame($before, $this->hashes(), 'Row 2 must not be written either.');
        }
    }

    public function testEntityFilter(): void
    {
        $this->insertUser('a@x.com');
        $this->em->getConnection()->executeStatement('UPDATE users SET email_hash = NULL');

        $this->rebuild(['--entity' => 'App\\Other']);
        $this->assertSame([null], $this->hashes());

        $this->rebuild(['--entity' => User::class]);
        $this->assertNotNull($this->hashes()[0]);
    }

    public function testEncryptDatabaseRebuildsIndexes(): void
    {
        $this->boot(self::KEY, null, true); // plain data, as before running "encrypt:database encrypt"
        $this->insertUser('a@x.com', 'A');
        $this->insertUser('b@x.com', 'B');
        $this->em->getConnection()->executeStatement('UPDATE users SET email_hash = NULL');

        $command = new EncryptDatabaseCommand($this->encryptor, $this->registry, [\PSolutions\EncryptBundle\Attributes\Encrypted::class], $this->rewriter());
        $tester = new CommandTester($command);
        $tester->execute(['direction' => 'encrypt']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $row = $this->row(1);
        $this->assertStringEndsWith('<ENC>', $row['email']);
        $this->assertSame($this->indexer->compute('a@x.com'), $row['email_hash']);

        $tester->execute(['direction' => 'decrypt', '--clear-blind-index' => true]);
        $this->assertSame('a@x.com', $this->row(1)['email']);
        $this->assertSame([null, null], $this->hashes());
    }
}
