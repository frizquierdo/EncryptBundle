<?php

namespace PSolutions\EncryptBundle\Command;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PSolutions\EncryptBundle\Maintenance\DatabaseRewriter;
use PSolutions\EncryptBundle\Event\EncryptEvents;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use PSolutions\EncryptBundle\Encryptors\EncryptorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
            name: 'encrypt:database',
            description: 'Encrypts or Decrypts the database'
    )]
class EncryptDatabaseCommand extends Command {

    private ?EntityManagerInterface $em;
    private array $encryptedFields = [];

    public function __construct(
            private readonly EncryptorInterface $encryptor,
            private readonly ManagerRegistry $registry,
            private readonly array $encryptedAttributes,
            private readonly ?DatabaseRewriter $rewriter = null
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->addArgument('direction', InputArgument::REQUIRED, 'Encrypt or Decrypt db.');
        $this->addOption('manager', null, InputOption::VALUE_OPTIONAL, 'Nominate the database connection manager name.');
        $this->addOption('no-blind-index', null, InputOption::VALUE_NONE, 'encrypt: do not rebuild the blind indexes afterwards.');
        $this->addOption('clear-blind-index', null, InputOption::VALUE_NONE, 'decrypt: also set the blind indexes to null (they stay valid otherwise).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {

        $io = new SymfonyStyle($input, $output);

        $direction = $input->getArgument('direction');

        if (!in_array($direction, [
            EncryptEvents::ENCRYPT,
            EncryptEvents::DECRYPT
            ],
            true
        )) {
            $io->error(sprintf(
                'La dirección puede ser "%s" o "%s"',
                EncryptEvents::ENCRYPT,
                EncryptEvents::DECRYPT
            ));
            return Command::FAILURE;
        }
        $managerName = $this->registry->getDefaultManagerName();

        $managerNameOption = $input->getOption('manager');

        if (!empty($managerNameOption)) {
            $managerName = $managerNameOption;
        }

        $this->em = $this->registry->getManager($managerName);

        $this->getEncryptedFields();

        $io->title(EncryptEvents::ENCRYPT === $direction ? 'Encrypting the database' : 'Decrypting the database');

        $tables = count($this->encryptedFields);

        $io->writeln($tables . ' tables to process');

        $io->progressStart($tables);

        foreach ($this->encryptedFields as $entityName => $fieldArray) {
            $this->decryptTable($entityName, $fieldArray, $direction);
            $io->progressAdvance();
        }

        $io->progressFinish();

        if (null === $this->rewriter) {
            return Command::SUCCESS;
        }

        $indexMode = null;
        if (EncryptEvents::ENCRYPT === $direction && !$input->getOption('no-blind-index')) {
            $indexMode = DatabaseRewriter::INDEX_REBUILD;
        } elseif (EncryptEvents::DECRYPT === $direction && $input->getOption('clear-blind-index')) {
            $indexMode = DatabaseRewriter::INDEX_CLEAR;
        }

        if (null === $indexMode) {
            return Command::SUCCESS;
        }

        $io->section(DatabaseRewriter::INDEX_CLEAR === $indexMode ? 'Clearing blind indexes' : 'Rebuilding blind indexes');
        $report = $this->rewriter->rewrite(
            managers: [$managerName => $this->em],
            sourceEncryptor: $this->encryptor,
            indexMode: $indexMode,
        );

        $status = BlindIndexRebuildCommand::renderReport($io, $report, 'Blind indexes updated');
        if (Command::SUCCESS !== $status) {
            $io->warning('The data was processed but the blind indexes were not. Fix the problems above and run bin/console encrypt:blind-index:rebuild.');
        }

        return $status;
    }

    private function decryptTable(string $tableName, array $fieldArray, string $direction): void {
        // Get all the field names that have been encrypted as an array.
        $select = array_keys($fieldArray);

        // Convert those to comma seperated string like lastname, firstname.
        $select = implode(', ', $select);
        $selectQuery = sprintf('SELECT id, %s FROM %s', $select, $tableName);

        // Fetch these encrypted rows
        $encryptedEntityFields = $this->em
            ->getConnection()
            ->fetchAllAssociative($selectQuery);

        foreach ($encryptedEntityFields as $entity) {
            $decryptedFields = [];
            foreach ($fieldArray as $fieldName => $refProperty) {

                if (EncryptEvents::ENCRYPT === $direction) {
                    $newValue = $this->encryptor->encrypt($entity[$fieldName]);
                } else {
                    $newValue = $this->encryptor->decrypt($entity[$fieldName]);
                }

                $decryptedFields[$fieldName] = $newValue;
            }

            $this->em->getConnection()->update(
                $tableName,
                $decryptedFields,
                [
                    'id' => $entity['id']
                ]
            );
        }
    }

    private function getEncryptedFields(): array {

        /** @var ClassMetadata[] $meta */
        $meta = $this->em->getMetadataFactory()->getAllMetadata();

        // Loop through entities
        foreach ($meta as $entityMeta) {

            if ($entityMeta->isMappedSuperclass) {
                continue;
            }

            $tableName = $entityMeta->getTableName();

            // Single table inheritance: the table was already collected from another class of the hierarchy.
            if (isset($this->encryptedFields[$tableName])) {
                continue;
            }

            $meta = $this->em->getClassMetadata($entityMeta->getName());
            $reflectionClass = $meta->getReflectionClass();

            foreach ($meta->getFieldNames() as $fieldName) {
                if (!$reflectionClass->hasProperty($fieldName)) {
                    continue;
                }
                $refProperty = $reflectionClass->getProperty($fieldName);
                if ($this->isEncryptedProperty($refProperty)) {
                    $columnName = $meta->getColumnName($fieldName);
                    $this->encryptedFields[$tableName][$columnName] = $refProperty;
                }
            }
        }

        return $this->encryptedFields;
    }

    private function isEncryptedProperty(\ReflectionProperty $refProperty): bool
    {
        foreach ($refProperty->getAttributes() as $refAttribute) {
            if (in_array($refAttribute->getName(), $this->encryptedAttributes)) {
                return true;
            }
        }

        return false;
    }
}
