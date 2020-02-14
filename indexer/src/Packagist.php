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

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Packagist
{
    const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[^/ ]+)$}i';

    /**
     * Blacklisted packages that should not be found.
     */
    private const BLACKLIST = ['contao/installation-bundle', 'contao/module-devtools', 'contao/module-repository', 'contao/contao'];

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var HttpClientInterface
     */
    private $client;

    public function __construct(OutputInterface $output, HttpClientInterface $client)
    {
        $this->output = $output;
        $this->client = $client;
    }

    public function getPackageNames(string $type): array
    {
        try {
            $data = $this->getJson('https://packagist.org/packages/list.json?type='.$type);
        } catch (ExceptionInterface $e) {
            $this->output->writeln(sprintf('Error fetching package names of type "%s"', $type));

            return [];
        }

        return array_diff($data['packageNames'], self::BLACKLIST);
    }

    public function getPackageData(string $name): ?array
    {
        if (preg_match(self::PLATFORM_PACKAGE_REGEX, $name)) {
            return null;
        }

        try {
            $packagesData = $this->getJson('https://packagist.org/packages/'.$name.'.json');

            if (!isset($packagesData['package'])) {
                return null;
            }

            $repoData = $this->getJson('https://repo.packagist.org/p/'.$name.'.json');

            if (!isset($repoData['packages'][$name])) {
                return null;
            }

            $data['packages'] = $packagesData['package'];
            $data['p'] = $repoData['packages'][$name];
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
