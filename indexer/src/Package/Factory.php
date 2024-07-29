<?php

declare(strict_types=1);

namespace Contao\PackageMetaDataIndexer\Package;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use Contao\PackageMetaDataIndexer\Command\IndexCommand;
use Contao\PackageMetaDataIndexer\MetaDataRepository;
use Contao\PackageMetaDataIndexer\Packagist;

class Factory
{
    private array $cache = [];

    private array $contaoVersions = [];

    public function __construct(private readonly MetaDataRepository $metaData, private readonly Packagist $packagist)
    {
    }

    public function create(string $name): Package
    {
        // Package names are case-insensitive and returned lowercase from Packagist
        $name = strtolower($name);
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

        if (isset($data['require']['contao/core-bundle'])) {
            try {
                $contaoConstraint = (new VersionParser())->parseConstraints($data['require']['contao/core-bundle']);
                $contaoConstraint = $this->normalizeConstraints([$contaoConstraint]);

                $package->setContaoConstraint($contaoConstraint);
                $package->setContaoVersions($this->buildContaoVersions($contaoConstraint));
            } catch (\Throwable) {
                // Ignore
            }
        }
    }

    private function setBasicDataFromPackagist(array $data, Package $package): void
    {
        $latest = $this->findLatestVersion($data['p']) ?? $data;
        $versions = array_keys($data['packages']['versions']);
        // $data['p'] contains the non-cached data, while only $data['packages'] has the "support" metadata
        $latestPackages = $this->findLatestVersion($data['packages']['versions']);
        $contaoConstraint = $this->buildContaoConstraint($data['p']);
        $contaoVersions = $contaoConstraint ? $this->buildContaoVersions($contaoConstraint) : null;

        sort($versions);

        $package->setType($latest['type'] ?? 'contao-bundle');
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
        $package->setContaoConstraint($contaoConstraint);
        $package->setContaoVersions($contaoVersions);
        $package->setPrivate(false);
    }

    private function isSupported(array $versionsData): bool
    {
        foreach ($versionsData as $version => $versionData) {
            if (str_starts_with((string) $version, 'dev-')) {
                continue;
            }

            if ('contao-component' === $versionData['type']) {
                return true;
            }

            if (
                !isset($versionData['require']['contao/core-bundle'])
                && !isset($versionData['require']['contao/manager-bundle'])
            ) {
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

        foreach (IndexCommand::$languages as $language) {
            if (null !== ($data = $this->metaData->getMetaDataForPackage($package, $language))) {
                $meta[$language] = $data;
            }
        }

        $package->setMeta($meta);
    }

    private function findLatestVersion(array $versions): array|null
    {
        $latest = array_reduce(
            array_keys($versions),
            static function (?string $prev, string $curr) use ($versions) {
                if (null === $prev) {
                    return $curr;
                }

                if (
                    !str_ends_with($prev, '-dev')
                    && !str_starts_with($prev, 'dev-')
                    && (str_starts_with($curr, 'dev-') || str_ends_with($curr, '-dev'))
                ) {
                    return $prev;
                }

                if (
                    !str_ends_with($curr, '-dev')
                    && !str_starts_with($curr, 'dev-')
                    && (str_starts_with($prev, 'dev-') || str_ends_with($prev, '-dev'))
                ) {
                    return $curr;
                }

                return strtotime($versions[$prev]['time']) > strtotime($versions[$curr]['time']) ? $prev : $curr;
            }
        );

        return $versions[$latest] ?? [];
    }

    private function buildContaoConstraint(array $versions): string|null
    {
        $constraints = [];

        foreach ($versions as $package) {
            if (VersionParser::parseStability($package['version']) !== 'stable') {
                continue;
            }

            if (!$constraint = $package['require']['contao/core-bundle'] ?? null) {
                continue;
            }

            if ('self.version' === $constraint) {
                $constraint = $package['version'];
            }

            try {
                $constraints[] = (new VersionParser())->parseConstraints($constraint);
            } catch (\Throwable) {
                // Ignore
            }
        }

        if (!$constraints) {
            return null;
        }

        return $this->normalizeConstraints($constraints);
    }

    private function buildContaoVersions(string $contaoConstraint): array
    {
        if (!$this->contaoVersions) {
            $data = $this->packagist->getPackageData('contao/core-bundle');

            foreach ($data['p'] as $package) {
                $version = explode('.', (new VersionParser())->normalize($package['version'] ?? ''));

                if (\count($version) > 3 && (int) $version[0]) {
                    $this->contaoVersions[(int) $version[0]][(int) $version[1]] ??= implode('.', $version);
                }
            }

            ksort($this->contaoVersions);

            foreach ($this->contaoVersions as $major => $minors) {
                ksort($this->contaoVersions[$major]);
            }
        }

        $constraint = (new VersionParser())->parseConstraints($contaoConstraint);

        $matches = [];

        foreach ($this->contaoVersions as $major => $minors) {
            $prefix = '';
            $lastMinor = 0;

            foreach ($minors as $minor => $versionString) {
                if ($constraint->matches(new Constraint('=', $versionString))) {
                    if (!$prefix) {
                        $prefix = "$major.$minor";
                    }
                } elseif ($prefix) {
                    $version = "$major.$lastMinor";
                    $matches[] = $version === $prefix ? $version : "$prefix - $version";
                    $prefix = '';
                }

                $lastMinor = $minor;
            }

            if ($prefix) {
                $matches[] = "$prefix+";
            }
        }

        return $matches;
    }

    private function normalizeConstraints(array $constraints): string
    {
        $constraint = (string) Intervals::compactConstraint(MultiConstraint::create($constraints, false));
        $constraint =  str_replace(['[', ']'], '', $constraint);
        $constraint = preg_replace('{(\d+\.\d+\.\d+)\.\d+(-dev)?( |$)}', '$1 ', $constraint);

        return trim($constraint);
    }
}
