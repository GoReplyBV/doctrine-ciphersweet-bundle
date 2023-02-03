<?php

namespace GoReply\DoctrineCiphersweetBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use GoReply\DoctrineCiphersweet\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function sprintf;

class ReindexBlindIndexesCommand extends Command
{
    private const BATCH_SIZE = 100;

    private SymfonyStyle $io;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Helper $helper,
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta instanceof ClassMetadataInfo && count($meta->subClasses) > 0) {
                continue;
            }

            if (!$this->helper->hasEncryptedFields($meta->getName())) {
                continue;
            }

            $this->reindexEntity($meta->getName());
        }

        return Command::SUCCESS;
    }

    /**
     * @param class-string $entityClass
     */
    private function reindexEntity(string $entityClass): void
    {
        $entities = $this->iterateEntities($entityClass);
        $totalCount = $this->getTotalCount($entityClass);

        $this->io->section($entityClass);
        $progressBar = $this->io->createProgressBar($totalCount);

        $untilFlush = self::BATCH_SIZE;

        foreach ($entities as $entity) {
            $this->helper->encrypt($entity);

            --$untilFlush;

            if ($untilFlush === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $progressBar->advance();

                $untilFlush = self::BATCH_SIZE;
            }
        }

        $this->entityManager->flush();
        $progressBar->finish();

        $this->io->newLine(2);
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $entityClass
     * @return iterable<TEntity>
     */
    private function iterateEntities(string $entityClass): iterable
    {
        /** @var iterable<TEntity> */
        return $this->entityManager->createQuery(
            sprintf('SELECT entity FROM %s entity', $entityClass)
        )->toIterable();
    }

    private function getTotalCount(string $entityClass): int
    {
        /** @var int */
        return $this->entityManager->createQuery(
            sprintf('SELECT COUNT(1) FROM %s entity', $entityClass)
        )->getSingleScalarResult();
    }
}
