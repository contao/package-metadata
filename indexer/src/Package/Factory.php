<?php

declare(strict_types=1);

/*
 * Contao Package Indexer
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataIndexer\Package;

use Contao\PackageMetaDataIndexer\Command\IndexCommand;
use Contao\PackageMetaDataIndexer\MetaDataRepository;
use Contao\PackageMetaDataIndexer\Packagist;

class Factory
{
    /**
     * @var MetaDataRepository
     */
    private $metaData;

    /**
     * @var Packagist
     */
    private $packagist;

    /**
     * @var array
     */
    private $cache = [];

    public function __construct(MetaDataRepository $metaData, Packagist $packagist)
    {
        $this->metaData = $metaData;
        $this->packagist = $packagist;
    }

    public function create(string $name): Package
    {
        $cacheKey = 'basic-'.$name;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $package = new Package($name);
        $data = $this->packagist->getPackageData($name);

        if (null === $data) {
            $this->setDataForPrivate($package);
        } else {
            $this->setBasicDataFromPackagist($data, $package);
        }

        $package->setLogo($this->metaData->getLogoForPackage($package));
        $this->addMeta($package);

        if (!$package->isSupported()) {
            if (!empty($package->getMeta())) {
                $package->setSupported(true);
            } elseif (null !== $package->getLogo()) {
                $package->setSupported(true);
                $package->setDependency(true);
            }
        }

        return $this->cache[$cacheKey] = $package;
    }

    private function setDataForPrivate(Package $package): void
    {
        $package->setSupported(true);
        $package->setPrivate(true);

        $data = $this->metaData->getComposerJsonForPackage($package);

        if (null === $data) {
            return;
        }

        $package->setDescription($data['description'] ?? null);
        $package->setKeywords($data['keywords'] ?? null);
        $package->setHomepage($data['homepage'] ?? null);
        $package->setSupport($data['support'] ?? null);
        $package->setVersions(isset($data['version']) ? [$data['version']] : []);
        $package->setLicense(isset($data['license']) ? (array) $data['license'] : null);
        $package->setReleased($data['time'] ?? null);
        $package->setUpdated($data['time'] ?? null);
        $package->setSuggest($data['suggest'] ?? null);
    }

    private function setBasicDataFromPackagist(array $data, Package $package): void
    {
        $latest = $this->findLatestVersion($data['p']);
        $versions = array_keys($data['packages']['versions']);
        // $data['p'] contains the non-cached data, while only $data['packages'] has the "support" metadata
        $latestPackages = $this->findLatestVersion($data['packages']['versions']);

        sort($versions);

        $package->setTitle($package->getName());
        $package->setDescription($latest['description'] ?? null);
        $package->setKeywords($latest['keywords'] ?? null);
        $package->setHomepage($latest['homepage'] ?? null);
        $package->setSupport($latestPackages['support'] ?? null);
        $package->setVersions($versions);
        $package->setLicense($latest['license'] ?? null);
        $package->setDownloads((int) ($data['packages']['downloads']['total'] ?? 0));
        $package->setFavers((int) ($data['packages']['favers'] ?? 0));
        $package->setReleased($data['packages']['time'] ?? null);
        $package->setUpdated($latest['time'] ?? null);
        $package->setSupported($this->isSupported($data['packages']['versions']));
        $package->setAbandoned($data['packages']['abandoned'] ?? false);
        $package->setSuggest($latest['suggest'] ?? null);
        $package->setPrivate(false);
    }

    private function isSupported(array $versionsData): bool
    {
        foreach ($versionsData as $version => $versionData) {
            if (0 === strpos((string) $version, 'dev-')) {
                continue;
            }

            if ('contao-component' === $versionData['type']) {
                return true;
            }

            if (!isset($versionData['require']['contao/core-bundle'])) {
                continue;
            }

            if ('contao-bundle' !== $versionData['type'] || isset($versionData['extra']['contao-manager-plugin'])) {
                return true;
            }
        }

        return false;
    }

    private function addMeta(Package $package): void
    {
        $meta = [];

        foreach (IndexCommand::LANGUAGES as $language) {
            if (null !== ($data = $this->metaData->getMetaDataForPackage($package, $language))) {
                $meta[$language] = $data;
            }
        }

        $package->setMeta($meta);
    }

    private function findLatestVersion(array $versions)
    {
        $latest = array_reduce(
            array_keys($versions),
            static function (?string $prev, string $curr) use ($versions) {
                if (null === $prev) {
                    return $curr;
                }

                if ('-dev' !== substr($prev, -4)
                    && 0 !== strpos($prev, 'dev-')
                    && (0 === strpos($curr, 'dev-') || '-dev' === substr($curr, -4))
                ) {
                    return $prev;
                }

                if ('-dev' !== substr($curr, -4)
                    && 0 !== strpos($curr, 'dev-')
                    && (0 === strpos($prev, 'dev-') || '-dev' === substr($prev, -4))
                ) {
                    return $curr;
                }

                return strtotime($versions[$prev]['time']) > strtotime($versions[$curr]['time']) ? $prev : $curr;
            }
        );

        return $versions[$latest];
    }
}
