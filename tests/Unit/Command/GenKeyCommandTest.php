<?php

namespace PSolutions\EncryptBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PSolutions\EncryptBundle\Command\GenKeyCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GenKeyCommandTest extends TestCase
{
    /**
     * Test that an encryption key of 43 characters (ending with =) is created.
     *
     * @test
     */
    public function testExecute()
    {
        $application = new Application();
        $command = new GenKeyCommand();
        method_exists($application, 'addCommand') ? $application->addCommand($command) : $application->add($command);

        $command = $application->find('encrypt:genkey');

        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        // Assert that command returned success status.
        $this->assertTrue(Command::SUCCESS === $commandTester->getStatusCode());

        // Assert that the command output contains text that like: Key is: FdY6sodQZ0GJBACsDlNda/9YEycltVmtob3CIhYe5Kw=
        $this->assertMatchesRegularExpression('/Key\sis:\s(\S{43}\=)/i', $commandTester->getDisplay());
    }

    public function testBlindIndexKey(): void
    {
        $tester = new CommandTester(new GenKeyCommand(true));
        $tester->execute(['--blind-index' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Key\sis:\s(\S{43}=)/', $tester->getDisplay());
        preg_match('/Key\sis:\s(\S{43}=)/', $tester->getDisplay(), $m);
        $this->assertSame(32, strlen(base64_decode($m[1], true)));
        $this->assertStringContainsString('encrypt:rotate-key', $tester->getDisplay());
        $this->assertStringContainsString('Blind Index', $tester->getDisplay());
    }

    public function testWarnsThatDerivedIndexKeyFollowsEncryptKey(): void
    {
        $tester = new CommandTester(new GenKeyCommand(true));
        $tester->execute([]);

        $this->assertStringContainsString('encrypt:rotate-key', $tester->getDisplay());
        $this->assertStringContainsString('derived from encrypt_key', $tester->getDisplay());
    }
}
