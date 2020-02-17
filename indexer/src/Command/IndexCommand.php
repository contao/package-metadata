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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexCommand extends Command
{
    /**
     * Languages for the search index.
     *
     * @see https://github.com/contao/contao-manager/blob/master/src/i18n/locales.js
     */
    public const LANGUAGES = ['en', 'de', 'br', 'cs', 'es', 'fa', 'fr', 'it', 'ja', 'lv', 'nl', 'pl', 'pt', 'ru', 'sr', 'zh'];

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
     * @var array
     */
    private $indexData;

    public function __construct(Packagist $packagist, Factory $packageFactory, Client $client, MetaDataRepository $metaDataRepository)
    {
        $this->packagist = $packagist;
        $this->client = $client;
        $this->packageFactory = $packageFactory;
        $this->metaDataRepository = $metaDataRepository;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('package-index')
            ->setDescription('Indexes package metadata')
            ->addArgument('packages', InputArgument::OPTIONAL|InputArgument::IS_ARRAY, 'The packages to index (optional).')
            ->addOption('with-stats', null, InputOption::VALUE_NONE, 'Also update statistics (should run less often / generates more API calls).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not index any data. Very useful together with -vvv.')
            ->addOption('clear-index', null, InputOption::VALUE_NONE, 'Clears algolia indexes completely (full re-index).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $clearIndex = (bool) $input->getOption('clear-index');
        $updateStats = (bool) $input->getOption('with-stats');

        $this->output = $output;
        $this->packages = [];

        $this->initIndex($clearIndex);

        $updateAll = false;
        $packageNames = $input->getArgument('packages');
        if (empty($packageNames)) {
            $updateAll = true;
            $packageNames = array_unique(array_merge(
                $this->packagist->getPackageNames('contao-bundle'),
                $this->packagist->getPackageNames('contao-module'),
                $this->packagist->getPackageNames('contao-component')
            ));
        }

        $this->collectPackages($packageNames);

        if ($updateAll) {
            $this->collectAdditionalPackages();
        }

        $this->indexPackages($dryRun, $updateStats);

        // If the index was not cleared completely, delete old/removed packages
        if (!$clearIndex && $updateAll) {
            $this->deleteRemovedPackages($dryRun);
        }

        return 0;
    }

    private function deleteRemovedPackages(bool $dryRun): void
    {
        $packagesToDeleteFromIndex = [];

        foreach ($this->indexData as $item) {
            if (!isset($this->packages[$item['name']])) {
                $packagesToDeleteFromIndex[] = $item['objectID'];
            }
        }

        $total = \count($packagesToDeleteFromIndex);

        $this->output->writeln($total.' objects to delete from index', OutputInterface::VERBOSITY_VERBOSE);

        if ($total > 0) {
            $this->output->writeln(' - '.implode("\n - ", $packagesToDeleteFromIndex), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($dryRun || 0 === $total) {
            return;
        }

        $this->index->deleteObjects($packagesToDeleteFromIndex);
    }

    private function collectPackages(array $packageNames): void
    {
        foreach ($packageNames as $packageName) {
            $package = $this->packageFactory->create($packageName);

            if (null === $package || !$package->isSupported()) {
                $this->output->writeln($packageName.' found, but is not supported.', OutputInterface::VERBOSITY_DEBUG);
                continue;
            }

            $this->packages[$packageName] = $package;
            $this->output->writeln($packageName.' found', OutputInterface::VERBOSITY_DEBUG);
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

    private function indexPackages(bool $dryRun, bool $updateStats): void
    {
        $objectIDs = [];

        foreach (array_chunk($this->packages, 100) as $chunk) {
            $objects = [];

            /** @var Package $package */
            foreach ($chunk as $package) {
                $languageKeys = array_unique(array_merge(['en'], array_keys($package->getMeta())));

                foreach ($languageKeys as $language) {
                    $languages = [$language];

                    if ('en' === $language) {
                        $languages = array_merge(['en'], array_diff(self::LANGUAGES, $languageKeys));
                    }

                    $data = $package->getForAlgolia($languages);

                    if ($this->isIndexed($data, $updateStats)) {
                        $this->output->writeln(
                            sprintf('ObjectID %s is already up-to-date', $data['objectID']),
                            OutputInterface::VERBOSITY_DEBUG
                        );
                        continue;
                    }

                    $objects[] = $data;
                    $objectIDs[] = $data['objectID'];
                }
            }

            if (!$dryRun) {
                $this->index->saveObjects($objects);
            }
        }

        $total = \count($objectIDs);
        $this->output->writeln($total.' objects to index', OutputInterface::VERBOSITY_VERBOSE);

        if ($total > 0) {
            $this->output->writeln(' - '.implode("\n - ", $objectIDs), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    private function initIndex(bool $clearIndex): void
    {
        /* @noinspection PhpUnhandledExceptionInspection */
        $this->index = $this->client->initIndex($_SERVER['ALGOLIA_INDEX']);
        $this->indexData = [];

        if ($clearIndex) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $this->index->clearIndex();
            return;
        }

        $cursor = null;
        do {
            $data = $this->index->browseFrom(null, null, $cursor);
            $cursor = $data['cursor'] ?? null;
            foreach ($data['hits'] as $item) {
                $this->indexData[$item['objectID']] = $item;
            }
        } while ($cursor);
    }

    private function isIndexed(array $data, bool $includeStatistics): bool
    {
        if (!isset($this->indexData[$data['objectID']])) {
            $this->output->writeln(
                sprintf('ObjectID %s does not exist in index', $data['objectID']),
                OutputInterface::VERBOSITY_DEBUG
            );
            return false;
        }

        $existing = $this->indexData[$data['objectID']];

        if (!$includeStatistics) {
            unset($data['favers'], $data['downloads']);
            unset($existing['favers'], $existing['downloads']);
        }

        foreach ($data as $k => $v) {
            if (!isset($existing[$k])) {
                $this->output->writeln(
                    sprintf(
                        'Data for %s not equal. Field %s is not in index.',
                        $data['objectID'],
                        $k,
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );

                return false;
            }

            if ($existing[$k] !== $v) {
                $this->output->writeln(
                    sprintf(
                        'Data for %s not equal. Field %s is not up to date (existing: %s / new: %s).',
                        $data['objectID'],
                        $k,
                        json_encode($existing[$k]),
                        json_encode($data[$k])
                    ),
                    OutputInterface::VERBOSITY_DEBUG
                );

                return false;
            }

            unset($existing[$k]);
        }

        if (0 !== \count($existing)) {
            $this->output->writeln(
                sprintf(
                    'Data for %s not equal. Existing has key(s): %s',
                    $data['objectID'],
                    implode(', ', array_keys($existing))
                ),
                OutputInterface::VERBOSITY_DEBUG
            );

            return false;
        }

        return true;
    }
}
