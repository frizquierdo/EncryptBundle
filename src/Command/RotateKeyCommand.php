<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Command;

use Doctrine\Persistence\ManagerRegistry;
use PSolutions\EncryptBundle\BlindIndex\BlindIndexer;
use PSolutions\EncryptBundle\Encryptors\EncryptorFactory;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Encryptors\OpenSslEncryptor;
use PSolutions\EncryptBundle\Event\EncryptKeyEvents;
use PSolutions\EncryptBundle\Exception\EncryptException;
use PSolutions\EncryptBundle\Maintenance\DatabaseRewriter;
use PSolutions\EncryptBundle\Util\EnvFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Rotates encrypt_key and/or blind_index_key, leaving data and blind indexes coherent in a single step.
 */
#[AsCommand(name: 'encrypt:rotate-key', description: 'Rotate the encryption and/or blind index key, re-encrypting data and rebuilding blind indexes.')]
class RotateKeyCommand extends Command
{
    /**
     * @param string[] $connections default entity managers (psolutions_encrypt.connections)
     */
    public function __construct(
        private readonly DatabaseRewriter $rewriter,
        private readonly EncryptorInterface $encryptor,
        private readonly BlindIndexer $blindIndexer,
        private readonly ManagerRegistry $registry,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly array $connections = ['default'],
        private readonly string $encryptorClass = OpenSslEncryptor::class,
        private readonly ?string $projectDir = null,
        private readonly ?string $environment = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('encrypt-key', null, InputOption::VALUE_OPTIONAL, 'New encryption key (generated if no value is given).', false)
            ->addOption('blind-index-key', null, InputOption::VALUE_OPTIONAL, 'New dedicated blind index key (generated if no value is given).', false)
            ->addOption('connection', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entity manager(s) to process (default: the configured connections). The key is shared: process all of them.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows read per query.', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run everything inside the transaction and roll it back.')
            ->addOption('write-env', null, InputOption::VALUE_OPTIONAL, 'Store the new keys in this env file (default .env).', false)
            ->setHelp(<<<'HELP'
                Without options it rotates every key in use: encrypt_key, and blind_index_key when it is a dedicated key
                (a derived blind index key always follows encrypt_key).

                  <info>--encrypt-key</info>       re-encrypts the data (and rebuilds the indexes if the index key is derived)
                  <info>--blind-index-key</info>   only rebuilds the blind indexes (requires a dedicated blind_index_key)

                Everything happens in one transaction per connection and is verified before commit: on any failure the
                database is left exactly as it was. Take a backup first.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $encryptOption = $input->getOption('encrypt-key');
        $blindOption = $input->getOption('blind-index-key');
        $rotateEncrypt = false !== $encryptOption;
        $rotateBlind = false !== $blindOption;
        $dedicated = $this->blindIndexer->hasDedicatedKey();
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$rotateEncrypt && !$rotateBlind) {
            $rotateEncrypt = true;
            $rotateBlind = $dedicated;
        }

        if ($rotateBlind && !$dedicated) {
            $io->error('blind_index_key is derived from encrypt_key, so it rotates with --encrypt-key. To rotate it '
                . 'separately, configure a dedicated key first (bin/console encrypt:install --blind-index-key).');

            return Command::FAILURE;
        }

        if ($this->dispatcher?->hasListeners(EncryptKeyEvents::LOAD_KEY)) {
            $io->error('The encryption key is provided at runtime by an EncryptKeyEvents::LOAD_KEY listener; '
                . 'this command cannot rotate it.');

            return Command::FAILURE;
        }

        try {
            $newEncryptKey = $rotateEncrypt ? ($encryptOption ?: GenKeyCommand::generateKey()) : null;
            $newBlindKey = $rotateBlind ? ($blindOption ?: GenKeyCommand::generateKey()) : null;

            $targetEncryptor = null;

            if (null !== $newEncryptKey) {
                $binary = BlindIndexer::decodeKey($newEncryptKey, 'new encryption key');
                // Fresh dispatcher: the new key must not be overridden by LOAD_KEY listeners.
                $targetEncryptor = (new EncryptorFactory(new EventDispatcher()))->createService($newEncryptKey, $this->encryptorClass);
                $targetBlindKey = $dedicated ? null : BlindIndexer::deriveKey($binary);
            }

            if (null !== $newBlindKey) {
                $targetBlindKey = BlindIndexer::decodeKey($newBlindKey, 'new blind index key');
            }
        } catch (EncryptException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rebuildIndexes = $rotateBlind || !$dedicated;
        $managers = BlindIndexRebuildCommand::resolveManagers($this->registry, $input->getOption('connection') ?: $this->connections);

        $io->title('Key rotation');
        $io->listing(array_filter([
            $rotateEncrypt ? 'encrypt_key: re-encrypt every #[Encrypted] column' : null,
            $rebuildIndexes ? 'blind indexes: recompute with the new ' . ($dedicated ? 'blind_index_key' : 'derived key') : null,
            'connections: ' . implode(', ', array_keys($managers)),
            $dryRun ? 'dry run: everything is rolled back' : null,
        ]));

        if (!$dryRun) {
            $io->warning('Take a database backup before going on. Until the new keys are set, the application will not decrypt.');

            if ($input->isInteractive() && !$io->confirm('Rotate the keys now?', false)) {
                $io->writeln('Aborted.');

                return Command::FAILURE;
            }
        }

        $report = $this->rewriter->rewrite(
            managers: $managers,
            sourceEncryptor: $this->encryptor,
            targetEncryptor: $targetEncryptor,
            dataMode: $rotateEncrypt ? DatabaseRewriter::DATA_REENCRYPT : DatabaseRewriter::DATA_KEEP,
            indexMode: $rebuildIndexes ? DatabaseRewriter::INDEX_REBUILD : DatabaseRewriter::INDEX_SKIP,
            blindIndexKey: $rebuildIndexes ? ($targetBlindKey ?? null) : null,
            batchSize: (int) $input->getOption('batch-size'),
            dryRun: $dryRun,
        );

        $status = BlindIndexRebuildCommand::renderReport($io, $report, 'Rows rewritten');

        if (Command::SUCCESS !== $status || $dryRun) {
            return $status;
        }

        $newKeys = array_filter([
            InstallCommand::ENCRYPT_KEY_VAR => $newEncryptKey,
            InstallCommand::BLIND_INDEX_KEY_VAR => $newBlindKey,
        ]);

        $writeEnv = $input->getOption('write-env');

        if (false !== $writeEnv && null !== $this->projectDir) {
            try {
                foreach ($this->writeEnv($writeEnv ?? '.env', $newKeys) as $line) {
                    $io->writeln($line);
                }
            } catch (\Throwable $e) {
                $io->error('The database was rotated but the env file could not be written: ' . $e->getMessage());
                $this->showKeys($io, $newKeys);

                return Command::FAILURE;
            }
        } else {
            $this->showKeys($io, $newKeys);
        }

        $io->warning('Now run "bin/console cache:clear" and restart the workers (php-fpm, messenger consumers...).');

        return Command::SUCCESS;
    }

    /**
     * Writes the keys to the target file and to every other env file that already defines them, so a file with
     * higher precedence cannot keep an old key.
     *
     * @param array<string, string> $keys
     *
     * @return list<string> report lines
     */
    private function writeEnv(string $target, array $keys): array
    {
        $lines = [];
        $candidates = ['.env', '.env.local'];

        if (null !== $this->environment) {
            $candidates[] = '.env.' . $this->environment;
            $candidates[] = '.env.' . $this->environment . '.local';
        }

        $dotenvVars = explode(',', (string) ($_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? ''));

        foreach ($keys as $var => $value) {
            $files = [$target];
            foreach ($candidates as $candidate) {
                if (EnvFile::has($this->projectDir . '/' . $candidate, $var)) {
                    $files[] = $candidate;
                }
            }

            foreach (array_unique($files) as $file) {
                $path = str_starts_with($file, '/') ? $file : $this->projectDir . '/' . $file;
                EnvFile::set($path, $var, $value);
                $lines[] = sprintf(' <info>updated</info> %s in %s', $var, $file);
            }

            if (isset($_SERVER[$var]) && !in_array($var, $dotenvVars, true)) {
                $lines[] = sprintf(' <error>%s is set as a real environment variable: update it there too, it overrides the env files.</error>', $var);
            }
        }

        return $lines;
    }

    /** @param array<string, string> $keys */
    private function showKeys(SymfonyStyle $io, array $keys): void
    {
        $io->caution(array_merge(
            ['Set these values now (env file, real environment or secrets); until then the application cannot decrypt:'],
            array_map(static fn ($var, $value) => $var . '=' . $value, array_keys($keys), $keys)
        ));
    }
}
