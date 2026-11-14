<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PSolutions\EncryptBundle\Command\InstallCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class InstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/psolutions-install-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/' . InstallCommand::CONFIG_FILE);
        @rmdir($this->dir . '/config/packages');
        @rmdir($this->dir . '/config');
        foreach (scandir($this->dir) as $f) { if (str_starts_with($f, '.env')) { unlink($this->dir . '/' . $f); } }
        @rmdir($this->dir);
    }

    private function install(array $input = []): CommandTester
    {
        $tester = new CommandTester(new InstallCommand($this->dir));
        $tester->execute($input);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        return $tester;
    }

    private function config(): string
    {
        return file_get_contents($this->dir . '/' . InstallCommand::CONFIG_FILE);
    }

    private function env(string $file = '.env'): string
    {
        return file_get_contents($this->dir . '/' . $file);
    }

    public function testCreatesConfigAndKey(): void
    {
        $this->install();

        $this->assertSame(InstallCommand::CONFIG_TEMPLATE, $this->config());
        $this->assertMatchesRegularExpression(
            "/###> psolutions\\/encrypt-bundle ###\nPSOLUTIONS_ENCRYPT_KEY=\\S{43}=\n###< psolutions\\/encrypt-bundle ###/",
            $this->env()
        );
        $this->assertSame(32, strlen(base64_decode(explode('=', explode("\n", $this->env())[1], 2)[1])));
    }

    public function testIsIdempotentAndDoesNotOverwriteWithoutForce(): void
    {
        file_put_contents($this->dir . '/.env', "APP_ENV=dev\n");
        $this->install();
        $env = $this->env();
        file_put_contents($this->dir . '/' . InstallCommand::CONFIG_FILE, "psolutions_encrypt: ~\n");

        $this->install();
        $this->assertSame($env, $this->env());
        $this->assertSame("psolutions_encrypt: ~\n", $this->config());
        $this->assertStringStartsWith("APP_ENV=dev\n\n###>", $env);

        $this->install(['--force' => true]);
        $this->assertSame(InstallCommand::CONFIG_TEMPLATE, $this->config());
        $this->assertSame($env, $this->env());
    }

    public function testLocal(): void
    {
        $this->install(['--local' => true]);
        $this->assertFileDoesNotExist($this->dir . '/.env');
        $this->assertStringContainsString('PSOLUTIONS_ENCRYPT_KEY=', $this->env('.env.local'));

        $this->install();
        $this->assertFileDoesNotExist($this->dir . '/.env', 'Already defined in .env.local.');
    }

    public function testBlindIndexKey(): void
    {
        $this->install();
        $this->install(['--blind-index-key' => true]);
        $this->install(['--blind-index-key' => true]);

        $this->assertSame(1, substr_count($this->env(), 'PSOLUTIONS_BLIND_INDEX_KEY='));
        $this->assertSame(1, substr_count($this->env(), 'PSOLUTIONS_ENCRYPT_KEY='));
        $this->assertSame(1, substr_count($this->env(), '###>'));
        $this->assertSame(1, preg_match_all('/^\s*blind_index_key:/m', $this->config()));
        $this->assertStringContainsString(InstallCommand::BLIND_INDEX_KEY_LINE, $this->config());
    }

    public function testBlindIndexKeyIsAddedToCustomConfig(): void
    {
        mkdir($this->dir . '/config/packages', 0777, true);
        file_put_contents($this->dir . '/' . InstallCommand::CONFIG_FILE, "psolutions_encrypt:\n    encrypt_key: '%env(PSOLUTIONS_ENCRYPT_KEY)%'\n    is_disabled: true\n");

        $this->install(['--blind-index-key' => true]);

        $this->assertSame(
            "psolutions_encrypt:\n    encrypt_key: '%env(PSOLUTIONS_ENCRYPT_KEY)%'\n" . InstallCommand::BLIND_INDEX_KEY_LINE . "\n    is_disabled: true\n",
            $this->config()
        );
    }

    public function testRecipeMatchesInstallTemplate(): void
    {
        $recipeDir = \dirname(__DIR__, 3) . '/recipe';
        $this->assertSame(InstallCommand::CONFIG_TEMPLATE, file_get_contents($recipeDir . '/config/packages/psolutions_encrypt.yaml'));

        $compiled = json_decode(file_get_contents($recipeDir . '/psolutions.encrypt-bundle.5.1.json'), true);
        $recipe = $compiled['manifests']['psolutions/encrypt-bundle'];
        $this->assertSame(json_decode(file_get_contents($recipeDir . '/manifest.json'), true), $recipe['manifest'], 'Run php recipe/build.php');
        $this->assertSame(
            rtrim(InstallCommand::CONFIG_TEMPLATE, "\n"),
            implode("\n", $recipe['files']['config/packages/psolutions_encrypt.yaml']['contents']),
            'Run php recipe/build.php'
        );
    }
}
