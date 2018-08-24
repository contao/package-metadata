<?php

declare(strict_types=1);

/*
 * Contao Package Metadata Linter
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataLinter;

use JsonSchema\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class LintCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    protected function configure()
    {
        $this
            ->setName('app:lint')
            ->setDescription('Lint all the metadata.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contao Package metadata linter');

        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('*.{yaml,yml}');

        foreach ($finder as $file) {
            $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());
            $language = str_replace(['.yaml', '.yml'], '', $file->getBasename());

            try {
                $content = Yaml::parseFile($file->getPath().'/'.$file->getFilename());
            } catch (ParseException $e) {
                $this->error($package, $language, 'The YAML file is invalid');

                return;
            }

            // Language
            if (!isset($content[$language])) {
                $this->error($package, $language, 'The language key in the YAML file does not match the specified language file name.');

                return;
            }

            // Content
            if (!$this->validateContent($content[$language])) {
                $this->error($package, $language, 'The YAML file contains invalid data.');

                return;
            }
        }

        $this->io->success('All checks successful!');
    }

    private function validateContent(array $content): bool
    {
        $data = json_decode(json_encode($content));

        $validator = new Validator();
        $validator->validate($data, (object) ['$ref' => 'file://'.realpath(\dirname(__DIR__).'/schema.json')]);

        foreach ($validator->getErrors() as $error) {
            $message = $error['message'].(('' !== $error['property']) ? (' ['.$error['property'].']') : '');
            $this->io->error($message);
        }

        return $validator->isValid();
    }

    private function error(string $package, string $language, string $message)
    {
        $this->io->error(sprintf('[Package: %s; Language: %s]: %s',
            $package,
            $language,
            $message
        ));
    }
}
