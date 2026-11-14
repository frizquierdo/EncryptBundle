<?php

declare(strict_types=1);

namespace PSolutions\EncryptBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use PSolutions\EncryptBundle\Maintenance\DatabaseRewriter;
use PSolutions\EncryptBundle\Maintenance\RewriteReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes every #[BlindIndex] from the decrypted source values. All or nothing.
 */
#[AsCommand(name: 'encrypt:blind-index:rebuild', description: 'Recompute the blind indexes of existing rows.')]
class BlindIndexRebuildCommand extends Command
{
    /**
     * @param string[] $connections default entity managers (psolutions_encrypt.connections)
     */
    public function __construct(
        private readonly DatabaseRewriter $rewriter,
        private readonly EncryptorInterface $encryptor,
        private readonly ManagerRegistry $registry,
        private readonly array $connections = ['default'],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entity manager(s) to process (default: the configured connections).')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Only this entity class (FQCN).')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows read per query.', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and the collisions, without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $report = $this->rewriter->rewrite(
            managers: self::resolveManagers($this->registry, $input->getOption('connection') ?: $this->connections),
            sourceEncryptor: $this->encryptor,
            indexMode: DatabaseRewriter::INDEX_REBUILD,
            entityFilter: $input->getOption('entity'),
            batchSize: (int) $input->getOption('batch-size'),
            dryRun: $dryRun,
        );

        return self::renderReport($io, $report, $dryRun ? 'Blind indexes to update' : 'Blind indexes updated');
    }

    /**
     * @param string[] $names
     *
     * @return array<string, EntityManagerInterface>
     */
    public static function resolveManagers(ManagerRegistry $registry, array $names): array
    {
        $managers = [];
        foreach (array_unique($names) as $name) {
            $manager = $registry->getManager($name);
            if (!$manager instanceof EntityManagerInterface) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a Doctrine ORM entity manager.', $name));
            }
            $managers[$name] = $manager;
        }

        return $managers;
    }

    public static function renderReport(SymfonyStyle $io, RewriteReport $report, string $changedLabel): int
    {
        $io->writeln(sprintf('Rows scanned: %d', $report->rowsScanned));

        if (!empty($report->changedByEntity)) {
            $io->table(['Entity', $changedLabel], array_map(
                null,
                array_keys($report->changedByEntity),
                array_values($report->changedByEntity)
            ));
        }

        if (!empty($report->collisions)) {
            $io->error('Collisions on unique blind indexes (same value in several rows). Nothing was written.');
            $io->table(['Entity', 'Property', 'Ids'], array_map(
                static fn (array $c) => [$c['entity'], $c['property'], implode(', ', $c['ids'])],
                $report->collisions
            ));
        }

        if (!empty($report->errors)) {
            $io->error(array_merge(['Nothing was written.'], array_slice($report->errors, 0, 50)));
        }

        if (!$report->isSuccessful()) {
            return Command::FAILURE;
        }

        if ($report->dryRun) {
            $io->success(sprintf('Dry run: %d row(s) would change. Nothing was written.', $report->rowsChanged));
        } elseif (0 === $report->rowsChanged) {
            $io->success('Everything is up to date.');
        } else {
            $io->success(sprintf('%d row(s) updated.', $report->rowsChanged));
        }

        return Command::SUCCESS;
    }
}
