<?php

namespace PSolutions\EncryptBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to generate a 256-bit encryption (or blind index) key.
 */
#[AsCommand(name: 'encrypt:genkey')]
class GenKeyCommand extends Command
{
    /**
     * @param bool|null $derivedBlindIndexKey whether the blind index key is derived from encrypt_key (null: unknown)
     */
    public function __construct(private readonly ?bool $derivedBlindIndexKey = null)
    {
        parent::__construct();
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate a 256-bit encryption key.')
            ->addOption('blind-index', null, InputOption::VALUE_NONE, 'Generate a key for blind indexes (PSOLUTIONS_BLIND_INDEX_KEY).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $blindIndex = (bool) $input->getOption('blind-index');

        $io->title($blindIndex ? 'Generated Blind Index Key' : 'Generated Key');
        $io->success('Key is: ' . self::generateKey());

        $notes = ['This key is not applied by itself. To change the key of a database that already has data, use '
            . '"bin/console encrypt:rotate-key" (it re-encrypts and rebuilds the blind indexes in one step).'];

        if ($blindIndex) {
            $notes[] = 'For a new installation: "bin/console encrypt:install --blind-index-key".';
        } elseif (true === $this->derivedBlindIndexKey) {
            $notes[] = 'The blind index key is derived from encrypt_key: rotating encrypt_key also rebuilds the blind indexes.';
        }

        $io->note($notes);

        return Command::SUCCESS;
    }
}
