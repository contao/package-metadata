<?php

declare(strict_types=1);

/*
 * Contao Package Indexer
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataIndexer;

use Contao\PackageMetaDataIndexer\Package\Package;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class MetaDataRepository
{
    /**
     * @var string
     */
    private $metaDataDir;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var array
     */
    private $names;

    /**
     * Metadata constructor.
     */
    public function __construct(string $metaDataDir)
    {
        $this->metaDataDir = $metaDataDir;
        $this->fs = new Filesystem();
    }

    public function getMetaDataDir(): string
    {
        return $this->metaDataDir;
    }

    public function getPackageNames(): array
    {
        if (null === $this->names) {
            $this->names = [];

            $finder = new Finder();
            $finder->directories()->in($this->getMetaDataDir())->depth('== 1');

            foreach ($finder as $dir) {
                $this->names[] = basename($dir->getPath()).'/'.$dir->getBasename();
            }
        }

        return $this->names;
    }

    public function getLogoForPackage(Package $package): ?string
    {
        [$vendor, $name] = explode('/', $package->getName(), 2);
        $image = sprintf('%s/%s/logo.svg', $vendor, $name);

        if (!$this->fs->exists($this->getMetaDataDir().'/'.$image)) {
            $image = sprintf('%s/logo.svg', $vendor);

            if (!$this->fs->exists($this->getMetaDataDir().'/'.$image)) {
                return null;
            }
        }

        // if bigger than 5kb use raw url
        if (@filesize($this->getMetaDataDir().'/'.$image) > (5 * 1024)) {
            $logo = sprintf(
                'https://contao.github.io/package-metadata/meta/'.$image,
                $package->getName()
            );
        } else {
            $logo = sprintf(
                'data:image/svg+xml;base64,%s',
                base64_encode(file_get_contents($this->getMetaDataDir().'/'.$image))
            );
        }

        return $logo;
    }

    public function getComposerJsonForPackage(Package $package): ?array
    {
        $file = $this->getMetaDataDir().'/'.$package->getName().'/composer.json';

        if (!$this->fs->exists($file)) {
            return null;
        }

        $data = @json_decode(file_get_contents($file), true);

        if (!\is_array($data)) {
            return null;
        }

        return $data;
    }

    public function getMetaDataForPackage(Package $package, string $language): ?array
    {
        $file = $this->getMetaDataDir().'/'.$package->getName().'/'.$language.'.yml';

        try {
            $data = Yaml::parseFile($file);
            $data = (\array_key_exists($language, $data) && \is_array($data[$language])) ? $data[$language] : [];

            $data = $this->filterMetadata($data);
            $data['metadata'] = sprintf(
                'https://github.com/contao/package-metadata/blob/master/meta/%s/%s.yml',
                $package->getName(),
                $language
            );
        } catch (ParseException $e) {
            return null;
        }

        return $data;
    }

    private function filterMetadata(array $data): array
    {
        return array_intersect_key(
            $data,
            array_flip(['title', 'description', 'keywords', 'homepage', 'support', 'suggest', 'dependency'])
        );
    }
}
