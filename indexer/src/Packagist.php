<?php

declare(strict_types=1);

namespace Contao\PackageMetaDataIndexer;

use Composer\MetadataMinifier\MetadataMinifier;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Packagist
{
    private const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[^/ ]+)$}i';

    /**
     * Excluded packages that should not be found.
     */
    private const EXCLUDE_LIST = ['contao/installation-bundle', 'contao/contao'];

    public function __construct(private readonly OutputInterface $output, private readonly HttpClientInterface $client)
    {
    }

    public function getPackageNames(string $type): array
    {
        try {
            $data = $this->getJson('https://packagist.org/packages/list.json?type='.$type);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Error fetching package names of type "%s"', $type), 0, $e);
        }

        return array_diff($data['packageNames'], self::EXCLUDE_LIST);
    }

    public function getPackageData(string $name): array|null
    {
        if (preg_match(self::PLATFORM_PACKAGE_REGEX, $name)) {
            return null;
        }

        try {
            $packagesData = $this->getJson('https://packagist.org/packages/'.$name.'.json');

            if (!isset($packagesData['package'])) {
                return null;
            }

            $repoData = $this->getJson('https://repo.packagist.org/p2/'.$name.'.json');

            if (!isset($repoData['packages'][$name])) {
                return null;
            }

            $data['packages'] = $packagesData['package'];
            $data['p'] = MetadataMinifier::expand($repoData['packages'][$name]);
        } catch (ExceptionInterface $e) {
            $this->output->writeln(sprintf('Error fetching package "%s"', $name), OutputInterface::VERBOSITY_DEBUG);

            return null;
        }

        return $data;
    }

    /**
     * @throws ExceptionInterface
     */
    private function getJson(string $uri): array
    {
        $response = $this->client->request('GET', $uri);
        $headers = $response->getHeaders();

        if (isset($headers['x-symfony-cache'])) {
            $this->output->writeln($headers['x-symfony-cache'], OutputInterface::VERBOSITY_DEBUG);
        }

        return $response->toArray();
    }
}
