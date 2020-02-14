<?php

declare(strict_types=1);

/*
 * Contao Package Indexer
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataIndexer\Command;

use AlgoliaSearch\Client;
use AlgoliaSearch\Index;
use Contao\PackageMetaDataIndexer\MetaDataRepository;
use Contao\PackageMetaDataIndexer\Package\Factory;
use Contao\PackageMetaDataIndexer\Package\Package;
use Contao\PackageMetaDataIndexer\Packagist;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class IndexCommand extends Command
{
    /**
     * Languages for the search index.
     *
     * @see https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js
     */
    public const LANGUAGES = ['en', 'de', 'br', 'cs', 'es', 'fa', 'fr', 'it', 'ja', 'lv', 'nl', 'pl', 'pt', 'ru', 'sr', 'zh'];
    private const CACHE_PREFIX = 'package-indexer';

    /**
     * @var Packagist
     */
    private $packagist;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Index
     */
    private $index;

    /**
     * @var Package[]
     */
    private $packages = [];

    /**
     * @var CacheItemPoolInterface
     */
    private $cacheItemPool;

    /**
     * @var Factory
     */
    private $packageFactory;

    /**
     * @var MetaDataRepository
     */
    private $metaDataRepository;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var SymfonyStyle
     */
    private $io;

    public function __construct(Packagist $packagist, Factory $packageFactory, Client $client, CacheItemPoolInterface $cacheItemPool, MetaDataRepository $metaDataRepository)
    {
        $this->packagist = $packagist;
        $this->client = $client;
        $this->cacheItemPool = $cacheItemPool;
        $this->packageFactory = $packageFactory;
        $this->metaDataRepository = $metaDataRepository;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('package-index')
            ->setDescription('Indexes package metadata')
            ->addArgument('package', InputArgument::OPTIONAL, 'Restrict indexing to a given package name.')
            ->addOption('with-stats', null, InputOption::VALUE_NONE, 'Also update statistics (should run less often / generates more API calls).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not index any data. Very useful together with -vvv.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Do not consider local cache (forces an index update).')
            ->addOption('clear-index', null, InputOption::VALUE_NONE, 'Clears algolia indexes completely (full re-index).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        $dryRun = (bool) $input->getOption('dry-run');
        $ignoreCache = (bool) $input->getOption('no-cache');
        $clearIndex = (bool) $input->getOption('clear-index');
        $updateStats = (bool) $input->getOption('with-stats');

        $this->io = new SymfonyStyle($input, $output);
        $this->output = $output;
        $this->packages = [];

        $this->createIndex($clearIndex);

        if (null !== $package) {
            $packageNames = [$package];
        } else {
            $packageNames = array_unique(array_merge(
                $this->packagist->getPackageNames('contao-bundle'),
                $this->packagist->getPackageNames('contao-module'),
                $this->packagist->getPackageNames('contao-component')
            ));
        }

        $this->collectPackages($packageNames);

        if (null === $package) {
            $this->collectAdditionalPackages();
        }

        $this->indexPackages($dryRun, $ignoreCache, $updateStats);

        // If the index was not cleared completely, delete old/removed packages
        if (!$clearIndex && null === $package) {
            $this->deleteRemovedPackages($dryRun);
        }

        return 0;
    }

    private function deleteRemovedPackages(bool $dryRun): void
    {
        $packagesToDeleteFromIndex = [];

        foreach ($this->index->browse('', ['attributesToRetrieve' => ['objectID']]) as $item) {
            // Check if object still exists in collected packages
            $objectID = $item['objectID'];
            $name = substr($objectID, 0, -3);
            if (!isset($this->packages[$name])) {
                $packagesToDeleteFromIndex[] = $objectID;
            }
        }

        if (0 === \count($packagesToDeleteFromIndex)) {
            return;
        }

        if (!$dryRun) {
            $this->index->deleteObjects($packagesToDeleteFromIndex);
        } else {
            $this->output->writeln(
                sprintf('Objects to delete from index: %s', json_encode($packagesToDeleteFromIndex)),
                OutputInterface::VERBOSITY_DEBUG
            );
        }
    }

    private function collectPackages(array $packageNames): void
    {
        foreach ($packageNames as $packageName) {
            $package = $this->packageFactory->create($packageName);

            if (null === $package || !$package->isSupported()) {
                $this->output->writeln($packageName.' is not supported.', OutputInterface::VERBOSITY_DEBUG);
                continue;
            }

            $this->packages[$packageName] = $package;
            $this->output->writeln('Added '.$packageName, OutputInterface::VERBOSITY_DEBUG);
        }
    }

    private function collectAdditionalPackages(): void
    {
        $publicPackages = array_keys($this->packages);
        $availablePackages = $this->metaDataRepository->getPackageNames();

        $additionalPackages = array_diff($availablePackages, $publicPackages);

        if (0 !== \count($additionalPackages)) {
            $this->collectPackages($additionalPackages);
        }
    }

    private function indexPackages(bool $dryRun, bool $ignoreCache, bool $updateStats): void
    {
        if (0 === \count($this->packages)) {
            return;
        }

        $packages = [];

        // Ignore the ones that do not need any update
        foreach ($this->packages as $packageName => $package) {
            $hash = self::CACHE_PREFIX.'-'.$package->getHash($updateStats);

            $cacheItem = $this->cacheItemPool->getItem($hash);

            if (!$ignoreCache) {
                if (!$cacheItem->isHit()) {
                    $hitMsg = 'miss';
                    $cacheItem->set(true);
                    $this->cacheItemPool->saveDeferred($cacheItem);
                    $packages[] = $package;
                } else {
                    $hitMsg = 'hit';
                }
            } else {
                $packages[] = $package;
                $hitMsg = 'ignored';
            }

            $this->output->writeln(
                sprintf('Cache entry for package "%s" was %s (hash: %s)', $packageName, $hitMsg, $hash),
                OutputInterface::VERBOSITY_DEBUG
            );
        }

        foreach (array_chunk($packages, 100) as $chunk) {
            $objects = [];

            /** @var Package $package */
            foreach ($chunk as $package) {
                $languageKeys = array_unique(array_merge(['en'], array_keys($package->getMeta())));

                foreach ($languageKeys as $language) {
                    $languages = [$language];

                    if ('en' === $language) {
                        $languages = array_merge(['en'], array_diff(self::LANGUAGES, $languageKeys));
                    }

                    $objects[] = $package->getForAlgolia($languages);
                }
            }

            if (!$dryRun) {
                $this->index->saveObjects($objects);
            } else {
                $this->output->writeln(
                    sprintf('Objects to index: %s', json_encode($objects)),
                    OutputInterface::VERBOSITY_DEBUG
                );
            }
        }

        $this->output->writeln(sprintf('Updated "%s" package(s).', \count($packages)), OutputInterface::VERBOSITY_DEBUG);

        $this->cacheItemPool->commit();
    }

    private function createIndex(bool $clearIndex): void
    {
        if (null !== $this->index) {
            return;
        }

        $this->index = $this->client->initIndex($_SERVER['ALGOLIA_INDEX']);

        if ($clearIndex) {
            $this->index->clearIndex();
        }
    }
}
