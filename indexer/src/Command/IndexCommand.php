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
     * @var string
     */
    private $cacheFile;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct(Packagist $packagist, Factory $packageFactory, Client $client, MetaDataRepository $metaDataRepository, string $cacheFile)
    {
        $this->packagist = $packagist;
        $this->client = $client;
        $this->packageFactory = $packageFactory;
        $this->metaDataRepository = $metaDataRepository;
        $this->cacheFile = $cacheFile;

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
            ->addArgument('package', InputArgument::OPTIONAL, 'Restrict indexing to a given package name.')
            ->addOption('with-stats', null, InputOption::VALUE_NONE, 'Also update statistics (should run less often / generates more API calls).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not index any data. Very useful together with -vvv.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Do not consider local cache (forces an index update).')
            ->addOption('clear-index', null, InputOption::VALUE_NONE, 'Clears algolia indexes completely (full re-index).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        $dryRun = (bool) $input->getOption('dry-run');
        $ignoreCache = (bool) $input->getOption('no-cache');
        $clearIndex = (bool) $input->getOption('clear-index');
        $updateStats = (bool) $input->getOption('with-stats');

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

        $cache = [];

        if (!$ignoreCache && file_exists($this->cacheFile)) {
            $cache = require $this->cacheFile;

            if (!\is_array($cache)) {
                $cache = [];
            }
        }

        $this->indexPackages($dryRun, $cache, $updateStats);

        // If the index was not cleared completely, delete old/removed packages
        if (!$clearIndex && null === $package) {
            $this->deleteRemovedPackages($dryRun);
        }

        if (!$ignoreCache) {
            file_put_contents($this->cacheFile, '<?php return '.var_export($cache, true).';');
        }

        return 0;
    }

    private function deleteRemovedPackages(bool $dryRun): void
    {
        $packagesToDeleteFromIndex = [];

        /* @noinspection PhpUndefinedMethodInspection */
        foreach ($this->index->browse('', ['attributesToRetrieve' => ['objectID']]) as $item) {
            // Check if object still exists in collected packages
            $objectID = $item['objectID'];
            $name = substr($objectID, 0, -3);

            if (!isset($this->packages[$name])) {
                $packagesToDeleteFromIndex[] = $objectID;
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
                $this->output->writeln($packageName.' is not supported.', OutputInterface::VERBOSITY_DEBUG);
                continue;
            }

            $this->packages[$packageName] = $package;
            $this->output->writeln($packageName.' added', OutputInterface::VERBOSITY_DEBUG);
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

    private function indexPackages(bool $dryRun, array &$cache, bool $updateStats): void
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
                    $hash = $package->getHash($languages, $updateStats);

                    if (isset($cache[$data['objectID']])) {
                        $this->output->writeln(
                            sprintf('Cache HIT for object %s', $data['objectID']),
                            OutputInterface::VERBOSITY_DEBUG
                        );

                        if ($cache[$data['objectID']] === $hash) {
                            continue;
                        }

                        $this->output->writeln(
                            sprintf('Hash MISMATCH: old: %s / new: %s', $cache[$data['objectID']], $hash),
                            OutputInterface::VERBOSITY_DEBUG
                        );
                    }

                    $cache[$data['objectID']] = $hash;
                    $objects[] = $data;
                    $objectIDs[] = $data['objectID'];

                    $this->output->writeln(
                        sprintf('Cache MISS for object %s', $data['objectID']),
                        OutputInterface::VERBOSITY_DEBUG
                    );
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

    private function createIndex(bool $clearIndex): void
    {
        if (null !== $this->index) {
            return;
        }

        /* @noinspection PhpUnhandledExceptionInspection */
        $this->index = $this->client->initIndex($_SERVER['ALGOLIA_INDEX']);

        if ($clearIndex) {
            /* @noinspection PhpUnhandledExceptionInspection */
            $this->index->clearIndex();
        }
    }
}
