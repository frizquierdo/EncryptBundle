<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Command;

use PSolutions\EncryptBundle\Util\EnvFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates config/packages/psolutions_encrypt.yaml and the key(s) in .env. Idempotent.
 *
 * Needed because Symfony Flex does not apply recipes declared by a dependency.
 */
#[AsCommand(name: 'encrypt:install', description: 'Create the bundle configuration file and the encryption key in .env.')]
class InstallCommand extends Command
{
    public const CONFIG_FILE = 'config/packages/psolutions_encrypt.yaml';
    public const ENCRYPT_KEY_VAR = 'PSOLUTIONS_ENCRYPT_KEY';
    public const BLIND_INDEX_KEY_VAR = 'PSOLUTIONS_BLIND_INDEX_KEY';
    public const BLIND_INDEX_KEY_LINE = "    blind_index_key: '%env(PSOLUTIONS_BLIND_INDEX_KEY)%'";

    /** Same contents as recipe/config/packages/psolutions_encrypt.yaml. */
    public const CONFIG_TEMPLATE = <<<'YAML'
        psolutions_encrypt:
            encrypt_key: '%env(PSOLUTIONS_ENCRYPT_KEY)%'
            # Dedicated key for blind indexes (default: derived from encrypt_key).
            # Recommended if you plan to rotate encrypt_key. Create it with: bin/console encrypt:install --blind-index-key
            # blind_index_key: '%env(PSOLUTIONS_BLIND_INDEX_KEY)%'
            is_disabled: false

        YAML;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the configuration file if it exists.')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Write the key(s) to .env.local instead of .env.')
            ->addOption('blind-index-key', null, InputOption::VALUE_NONE, 'Also create a dedicated blind index key (PSOLUTIONS_BLIND_INDEX_KEY).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $blindIndexKey = (bool) $input->getOption('blind-index-key');

        // 1. Configuration file.
        $configFile = $this->projectDir . '/' . self::CONFIG_FILE;

        if (!is_file($configFile) || $input->getOption('force')) {
            $contents = self::CONFIG_TEMPLATE;

            if ($blindIndexKey) {
                $contents = self::enableBlindIndexKey($contents);
            }

            if (!is_dir(\dirname($configFile))) {
                mkdir(\dirname($configFile), 0777, true);
            }

            file_put_contents($configFile, $contents);
            $io->writeln(sprintf(' <info>created</info> %s', self::CONFIG_FILE));
        } elseif ($blindIndexKey && !self::hasBlindIndexKey((string) file_get_contents($configFile))) {
            file_put_contents($configFile, self::enableBlindIndexKey((string) file_get_contents($configFile)));
            $io->writeln(sprintf(' <info>updated</info> %s (blind_index_key)', self::CONFIG_FILE));
        } else {
            $io->writeln(sprintf(' <comment>exists</comment>  %s (use --force to overwrite)', self::CONFIG_FILE));
        }

        // 2. Keys.
        $envName = $input->getOption('local') ? '.env.local' : '.env';
        $vars = [self::ENCRYPT_KEY_VAR];

        if ($blindIndexKey) {
            $vars[] = self::BLIND_INDEX_KEY_VAR;
        }

        foreach ($vars as $var) {
            $definedIn = $this->definedIn($var);

            if (null !== $definedIn) {
                $io->writeln(sprintf(' <comment>exists</comment>  %s in %s', $var, $definedIn));
                continue;
            }

            EnvFile::set($this->projectDir . '/' . $envName, $var, GenKeyCommand::generateKey());
            $io->writeln(sprintf(' <info>added</info>   %s to %s', $var, $envName));
        }

        if ($blindIndexKey) {
            $io->note('If blind indexes already exist, recompute them with the new key: bin/console encrypt:blind-index:rebuild');
        }

        if ('.env' === $envName) {
            $io->note('Keep the production key out of version control: set it as a real environment variable or a Symfony secret.');
        }

        return Command::SUCCESS;
    }

    public static function hasBlindIndexKey(string $yaml): bool
    {
        return 1 === preg_match('/^\s*blind_index_key\s*:/m', $yaml);
    }

    public static function enableBlindIndexKey(string $yaml): string
    {
        if (self::hasBlindIndexKey($yaml)) {
            return $yaml;
        }

        // Uncomment the template line if present.
        $count = 0;
        $yaml = preg_replace('/^\s*#\s*blind_index_key\s*:.*$/m', self::BLIND_INDEX_KEY_LINE, $yaml, 1, $count);

        if ($count > 0) {
            return $yaml;
        }

        // Otherwise add it after encrypt_key, or right under the root key.
        $yaml = preg_replace('/^(\s*encrypt_key\s*:.*)$/m', '$1' . "\n" . self::BLIND_INDEX_KEY_LINE, $yaml, 1, $count);

        if ($count > 0) {
            return $yaml;
        }

        $yaml = preg_replace('/^(psolutions_encrypt\s*:.*)$/m', '$1' . "\n" . self::BLIND_INDEX_KEY_LINE, $yaml, 1, $count);

        return $count > 0 ? $yaml : rtrim($yaml) . "\npsolutions_encrypt:\n" . self::BLIND_INDEX_KEY_LINE . "\n";
    }

    private function definedIn(string $var): ?string
    {
        foreach (['.env', '.env.local'] as $file) {
            if (EnvFile::has($this->projectDir . '/' . $file, $var)) {
                return $file;
            }
        }

        return null;
    }
}
