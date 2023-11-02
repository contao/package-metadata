<?php

declare(strict_types=1);

namespace Contao\PackageMetaDataIndexer\Package;

class Package
{
    private string $type = 'contao-bundle';
    private string|null $title = null;
    private string|null $description = null;
    private array|null $keywords = null;
    private string|null $homepage = null;
    private array|null $support = null;
    private array $versions = [];
    private array|null $license = null;
    private int $downloads = 0;
    private int $favers = 0;
    private string|null $released = null;
    private string|null $updated = null;
    private bool $supported = false;
    private bool $dependency = false;
    private bool $discoverable = true;
    private string|bool $abandoned = false;
    private bool $private = false;
    private array|null $suggest = null;
    private string|null $logo = null;
    private array $meta = [];

    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title ?: $this->name;
    }

    public function setTitle(string|null $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): string|null
    {
        return $this->description;
    }

    public function setDescription(string|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getKeywords(): array|null
    {
        return $this->keywords;
    }

    public function setKeywords(array|null $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function getHomepage(): string|null
    {
        return $this->homepage;
    }

    public function setHomepage(string|null $homepage): self
    {
        $this->homepage = $homepage;

        return $this;
    }

    public function getSupport(): array|null
    {
        return $this->support;
    }

    public function setSupport(array|null $support): self
    {
        $this->support = $support;

        return $this;
    }

    public function getVersions(): array
    {
        return $this->versions;
    }

    public function setVersions(array $versions): self
    {
        $this->versions = $versions;

        return $this;
    }

    public function getLicense(): array|null
    {
        return $this->license;
    }

    public function setLicense(array|null $license): self
    {
        $this->license = $license;

        return $this;
    }

    public function getDownloads(): int
    {
        return $this->downloads;
    }

    public function setDownloads(int $downloads): self
    {
        $this->downloads = $downloads;

        return $this;
    }

    public function getFavers(): int
    {
        return $this->favers;
    }

    public function setFavers(int $favers): self
    {
        $this->favers = $favers;

        return $this;
    }

    public function getReleased(): string|null
    {
        return $this->released;
    }

    public function setReleased(string|null $released): self
    {
        $this->released = $released;

        return $this;
    }

    public function getUpdated(): string|null
    {
        return $this->updated;
    }

    public function setUpdated(string|null $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function setSupported(bool $supported): self
    {
        $this->supported = $supported;

        return $this;
    }

    public function isDependency(): bool
    {
        return $this->dependency;
    }

    public function setDependency(bool $dependency): self
    {
        $this->dependency = $dependency;

        return $this;
    }

    public function isDiscoverable(): bool
    {
        return $this->discoverable;
    }

    public function setDiscoverable(bool $discoverable): self
    {
        $this->discoverable = $discoverable;

        return $this;
    }

    public function getAbandoned(): string|bool
    {
        return $this->abandoned;
    }

    public function setAbandoned(string|bool $abandoned): self
    {
        $this->abandoned = $abandoned;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(bool $private): self
    {
        $this->private = $private;

        return $this;
    }

    public function getSuggest(): array|null
    {
        return $this->suggest;
    }

    public function setSuggest(array|null $suggest): self
    {
        $this->suggest = $suggest;

        return $this;
    }

    public function getLogo(): string|null
    {
        return $this->logo;
    }

    public function setLogo(string|null $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getMetaForLanguage(string $language): array
    {
        $meta = $this->getMeta();

        if (isset($meta[$language])) {
            return (array) $meta[$language];
        }

        return [];
    }

    public function setMeta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function getForAlgolia(array $languages): array
    {
        $language = reset($languages);

        $data = [
            'objectID' => $this->getName().'/'.$language,
            'name' => $this->getName(),
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'keywords' => $this->getKeywords(),
            'homepage' => $this->getHomepage(),
            'support' => $this->getSupport(),
            'license' => $this->getLicense(),
            'downloads' => $this->getDownloads(),
            'favers' => $this->getFavers(),
            'released' => $this->getReleased(),
            'updated' => $this->getUpdated(),
            'dependency' => $this->isDependency(),
            'discoverable' => $this->isDiscoverable(),
            'abandoned' => $this->getAbandoned(),
            'private' => $this->isPrivate(),
            'suggest' => $this->getSuggest(),
            'logo' => $this->getLogo(),
            'languages' => $languages,
        ];

        $data = array_replace_recursive($data, $this->getMetaForLanguage($language));

        if ($data['dependency']) {
            $data['discoverable'] = false;
        }

        return array_filter(
            $data,
            static fn ($v) => null !== $v
        );
    }
}
