<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Shlinkio\Shlink\CLI\Util\ExitCodes;
use Shlinkio\Shlink\CLI\Util\ProcessRunnerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\PhpExecutableFinder;

use function Functional\contains;
use function Functional\filter;

use const Shlinkio\Shlink\MIGRATIONS_TABLE;

class CreateDatabaseCommand extends AbstractDatabaseCommand
{
    public const NAME = 'db:create';
    public const DOCTRINE_SCRIPT = 'vendor/doctrine/orm/bin/doctrine.php';
    public const DOCTRINE_CREATE_SCHEMA_COMMAND = 'orm:schema-tool:create';

    public function __construct(
        LockFactory $locker,
        ProcessRunnerInterface $processRunner,
        PhpExecutableFinder $phpFinder,
        private Connection $regularConn,
        private Connection $noDbNameConn,
    ) {
        parent::__construct($locker, $processRunner, $phpFinder);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setHidden()
            ->setDescription(
                'Creates the database needed for shlink to work. It will do nothing if the database already exists',
            );
    }

    protected function lockedExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->checkDbExists();

        if ($this->schemaExists()) {
            $io->success('Database already exists. Run "db:migrate" command to make sure it is up to date.');
            return ExitCodes::EXIT_SUCCESS;
        }

        // Create database
        $io->writeln('<fg=blue>Creating database tables...</>');
        $this->runPhpCommand($output, [self::DOCTRINE_SCRIPT, self::DOCTRINE_CREATE_SCHEMA_COMMAND]);
        $io->success('Database properly created!');

        return ExitCodes::EXIT_SUCCESS;
    }

    private function checkDbExists(): void
    {
        if ($this->regularConn->getDriver()->getDatabasePlatform() instanceof SqlitePlatform) {
            return;
        }

        // In order to create the new database, we have to use a connection where the dbname was not set.
        // Otherwise, it will fail to connect and will not be able to create the new database
        $schemaManager = $this->noDbNameConn->createSchemaManager();
        $databases = $schemaManager->listDatabases();
        $shlinkDatabase = $this->regularConn->getParams()['dbname'] ?? null;

        if ($shlinkDatabase !== null && ! contains($databases, $shlinkDatabase)) {
            $schemaManager->createDatabase($shlinkDatabase);
        }
    }

    private function schemaExists(): bool
    {
        // If at least one of the shlink tables exist, we will consider the database exists somehow.
        // We exclude the migrations table, in case db:migrate was run first by mistake.
        // Any other inconsistency will be taken care by the migrations.
        $schemaManager = $this->regularConn->createSchemaManager();
        return ! empty(filter($schemaManager->listTableNames(), fn (string $table) => $table !== MIGRATIONS_TABLE));
    }
}
