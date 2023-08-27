<?php

declare(strict_types=1);

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
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IndexCommand extends Command
{
    protected static $defaultName = 'package-index';
    protected static $defaultDescription = 'Indexes package metadata';

    private Index $index;
    private OutputInterface $output;
    private array $indexData;

    /**
     * Languages for the search index.
     * @see https://github.com/contao/package-list/blob/main/src/i18n/locales.js
     *
     * @var array<string>
     */
    public static array $languages;

    /**
     * @var array<Package>
     */
    private array $packages;

    public function __construct(private readonly Packagist $packagist, private readonly Factory $packageFactory, private readonly Client $algoliaClient, private readonly MetaDataRepository $metaDataRepository, private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('packages', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'The packages to index (optional).')
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

        $this->initLanguages();
        $this->initIndex($clearIndex);

        $updateAll = false;
        $packageNames = $input->getArgument('packages');

        if (empty($packageNames)) {
            $updateAll = true;
            $packageNames = array_unique(array_merge(
                $this->packagist->getPackageNames('contao-bundle'),
                $this->packagist->getPackageNames('contao-module'),
                $this->packagist->getPackageNames('contao-theme'),
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

        return Command::SUCCESS;
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

            if (!$package->isSupported()) {
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
                        $languages = array_merge(['en'], array_diff(self::$languages, $languageKeys));
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
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->index = $this->algoliaClient->initIndex($_SERVER['ALGOLIA_INDEX']);
        $this->indexData = [];

        if ($clearIndex) {
            /** @noinspection PhpUnhandledExceptionInspection */
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
            unset($data['favers'], $data['downloads'], $existing['favers'], $existing['downloads']);
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
                        json_encode($existing[$k], JSON_THROW_ON_ERROR),
                        json_encode($data[$k], JSON_THROW_ON_ERROR)
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

    private function initLanguages(): void
    {
        $response = $this->httpClient->request('GET', 'https://raw.githubusercontent.com/contao/package-list/main/src/i18n/locales.js');

        preg_match_all('/^[ ]+([a-z]{2}(_[A-Z]{2})?)/m', $response->getContent(), $matches);

        self::$languages = $matches[1];
    }
}
