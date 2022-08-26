<?php

declare(strict_types=1);

/*
 * This file is part of the Composer package "eliashaeussler/cache-warmup".
 *
 * Copyright (C) 2022 Elias Häußler <elias@haeussler.dev>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace EliasHaeussler\CacheWarmup\Command;

use EliasHaeussler\CacheWarmup\CacheWarmer;
use EliasHaeussler\CacheWarmup\Crawler;
use EliasHaeussler\CacheWarmup\Result;
use EliasHaeussler\CacheWarmup\Sitemap;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7;
use Psr\Http\Client;
use Symfony\Component\Console;

use function array_map;
use function assert;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * CacheWarmupCommand.
 *
 * @author Elias Häußler <elias@heussler.dev>
 * @license GPL-3.0-or-later
 */
final class CacheWarmupCommand extends Console\Command\Command
{
    private const SUCCESSFUL = 0;
    private const FAILED = 1;

    private Console\Style\SymfonyStyle $io;

    public function __construct(
        private readonly Client\ClientInterface $client = new GuzzleClient(),
    ) {
        parent::__construct('cache-warmup');
    }

    protected function configure(): void
    {
        $this->setDescription('Warms up caches of URLs provided by a given set of XML sitemaps.');
        $this->setHelp(implode(PHP_EOL, [
            'This command can be used to warm up website caches. ',
            'It requires a set of XML sitemaps offering several URLs which will be crawled.',
            '',
            '<info>Sitemaps</info>',
            '<info>========</info>',
            'The list of sitemaps to be crawled can be defined as command argument:',
            '',
            '   <comment>%command.full_name% https://www.example.com/sitemap.xml</comment>',
            '',
            'You are free to crawl as many different sitemaps as you want.',
            'Alternatively, sitemaps can be specified from user input when application is in interactive mode.',
            '',
            '<info>Custom URLs</info>',
            '<info>===========</info>',
            'In addition or as an alternative to sitemaps, it\'s also possible to provide a given URL set '.
            'using the <comment>--urls</comment> option:',
            '',
            '   <comment>%command.full_name% -u https://www.example.com/foo -u https://www.example.com/baz</comment>',
            '',
            '<info>URL limit</info>',
            '<info>=========</info>',
            'The number of URLs to be crawled can be limited using the <comment>--limit</comment> option:',
            '',
            '   <comment>%command.full_name% --limit 50</comment>',
            '',
            '<info>Crawler</info>',
            '<info>=======</info>',
            'By default, cache warmup will be done using concurrent HEAD requests. ',
            'This behavior can be overridden in case a special crawler is defined using the <comment>--crawler</comment> option:',
            '',
            '   <comment>%command.full_name% --crawler "Vendor\Crawler\MyCrawler"</comment>',
            '',
            'It\'s up to you to ensure the given crawler class is available and fully loaded.',
            'This can best be achieved by registering the class with Composer autoloader.',
            'Also make sure the crawler implements the <comment>'.Crawler\CrawlerInterface::class.'</comment> interface.',
            '',
            '<info>Crawler options</info>',
            '<info>===============</info>',
            'For crawlers implementing the <comment>'.Crawler\ConfigurableCrawlerInterface::class.'</comment> interface,',
            'it is possible to pass a JSON-encoded array of crawler options by using the <comment>--crawler-options</comment> option:',
            '',
            '   <comment>%command.full_name% --crawler-options \'{"concurrency": 3}\'</comment>',
            '',
            '<info>Allow failures</info>',
            '<info>==============</info>',
            'If a sitemap cannot be parsed or an URL fails to be crawled, this command normally exits ',
            'with a non-zero exit code. This is not always the desired behavior. Therefore, you can change ',
            'this behavior by using the <comment>--allow-failures</comment> option:',
            '',
            '   <comment>%command.full_name% --allow-failures</comment>',
        ]));

        $this->addArgument(
            'sitemaps',
            Console\Input\InputArgument::OPTIONAL | Console\Input\InputArgument::IS_ARRAY,
            'URLs of XML sitemaps to be used for cache warming'
        );
        $this->addOption(
            'urls',
            'u',
            Console\Input\InputOption::VALUE_REQUIRED | Console\Input\InputOption::VALUE_IS_ARRAY,
            'Custom additional URLs to be used for cache warming'
        );
        $this->addOption(
            'limit',
            'l',
            Console\Input\InputOption::VALUE_REQUIRED,
            'Limit the number of URLs to be processed',
            '0'
        );
        $this->addOption(
            'progress',
            'p',
            Console\Input\InputOption::VALUE_NEGATABLE,
            'Show progress bar during cache warmup'
        );
        $this->addOption(
            'crawler',
            'c',
            Console\Input\InputOption::VALUE_REQUIRED,
            'FQCN of the crawler to be used for cache warming'
        );
        $this->addOption(
            'crawler-options',
            'o',
            Console\Input\InputOption::VALUE_REQUIRED,
            'Additional config for configurable crawlers'
        );
        $this->addOption(
            'allow-failures',
            null,
            Console\Input\InputOption::VALUE_NONE,
            'Allow failures during URL crawling and exit with zero'
        );
    }

    protected function initialize(Console\Input\InputInterface $input, Console\Output\OutputInterface $output): void
    {
        $this->io = new Console\Style\SymfonyStyle($input, $output);
    }

    protected function interact(Console\Input\InputInterface $input, Console\Output\OutputInterface $output): void
    {
        // Early return if sitemaps or URLs are already specified
        if ([] !== $input->getArgument('sitemaps') || [] !== $input->getOption('urls')) {
            return;
        }

        // Get sitemaps from interactive user input
        $sitemaps = [];
        /** @var Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        do {
            $question = new Console\Question\Question('Please enter the URL of a XML sitemap: ');
            $question->setValidator($this->validateSitemap(...));
            $sitemap = $helper->ask($input, $output, $question);
            if ($sitemap instanceof Sitemap\Sitemap) {
                $sitemaps[] = $sitemap;
                $output->writeln(sprintf('<info>Sitemap added: %s</info>', $sitemap));
            }
        } while ($sitemap instanceof Sitemap\Sitemap);

        // Throw exception if no sitemaps were added
        if ([] === $sitemaps && [] === $input->getOption('urls')) {
            throw new Console\Exception\RuntimeException('You must enter at least one sitemap URL.', 1604258903);
        }

        $input->setArgument('sitemaps', $sitemaps);
    }

    protected function execute(Console\Input\InputInterface $input, Console\Output\OutputInterface $output): int
    {
        $sitemaps = $input->getArgument('sitemaps');
        $urls = $input->getOption('urls');
        $limit = (int) $input->getOption('limit');
        $allowFailures = (bool) $input->getOption('allow-failures');

        // Throw exception if neither sitemaps nor URLs are defined
        if ([] === $sitemaps && [] === $urls) {
            throw new Console\Exception\RuntimeException('Neither sitemaps nor URLs are defined.', 1604261236);
        }

        // Initialize crawler
        $crawler = $this->initializeCrawler($input, $output);
        $isVerboseCrawler = $crawler instanceof Crawler\VerboseCrawlerInterface;

        // Initialize cache warmer
        $output->write('Parsing sitemaps... ');
        $cacheWarmer = new CacheWarmer($limit, $this->client, $crawler, !$allowFailures);
        $cacheWarmer->addSitemaps($sitemaps);
        foreach ($urls as $url) {
            assert(is_string($url));
            $cacheWarmer->addUrl($url);
        }
        $output->writeln('<info>Done</info>');

        if ($output->isVeryVerbose()) {
            // Print parsed sitemaps
            $decoratedSitemaps = array_map($this->decorateSitemap(...), $cacheWarmer->getSitemaps());
            $this->io->section('The following sitemaps were processed:');
            $this->io->listing($decoratedSitemaps);

            // Print parsed URLs
            $this->io->section('The following URLs will be crawled:');
            $this->io->listing($cacheWarmer->getUrls());
        }

        // Print failed sitemaps
        if ([] !== ($failedSitemaps = $cacheWarmer->getFailedSitemaps())) {
            $decoratedFailedSitemaps = array_map($this->decorateSitemap(...), $failedSitemaps);
            $this->io->section('The following sitemaps could not be parsed:');
            $this->io->listing($decoratedFailedSitemaps);
        }

        // Start crawling
        $urlCount = count($cacheWarmer->getUrls());
        $output->write(sprintf('Crawling URL%s... ', 1 === $urlCount ? '' : 's'), $isVerboseCrawler);
        $result = $cacheWarmer->run();
        if (!$isVerboseCrawler) {
            $output->writeln('<info>Done</info>');
        }

        $this->printResult($result);

        if ([] !== [...$result->getFailed(), ...$failedSitemaps] && !$allowFailures) {
            return self::FAILED;
        }

        return self::SUCCESSFUL;
    }

    private function printResult(Result\CacheWarmupResult $result): void
    {
        $successfulUrls = $result->getSuccessful();
        $failedUrls = $result->getFailed();

        // Print crawler statistics
        if ($this->io->isVeryVerbose()) {
            if ([] !== $successfulUrls) {
                $this->io->section('The following URLs were successfully crawled:');
                $this->io->listing($this->decorateCrawledUrls($successfulUrls));
            }
            if ([] !== $failedUrls) {
                $this->io->section('The following URLs failed during crawling:');
                $this->io->listing($this->decorateCrawledUrls($failedUrls));
            }
        }

        // Print crawler results
        if ([] !== $successfulUrls) {
            $countSuccessfulUrls = count($successfulUrls);
            $this->io->success(
                sprintf(
                    'Successfully warmed up caches for %d URL%s.',
                    $countSuccessfulUrls,
                    1 === $countSuccessfulUrls ? '' : 's'
                )
            );
        }

        if ([] !== $failedUrls) {
            $countFailedUrls = count($failedUrls);
            $this->io->error(
                sprintf(
                    'Failed to warm up caches for %d URL%s.',
                    $countFailedUrls,
                    1 === $countFailedUrls ? '' : 's'
                )
            );
        }
    }

    private function initializeCrawler(
        Console\Input\InputInterface $input,
        Console\Output\OutputInterface $output,
    ): Crawler\CrawlerInterface {
        $crawler = $input->getOption('crawler');
        $crawlerOptions = $input->getOption('crawler-options');

        if (is_string($crawler)) {
            // Use crawler specified by --crawler option
            if (!class_exists($crawler)) {
                throw new Console\Exception\RuntimeException('The specified crawler class does not exist.', 1604261816);
            }

            if (!in_array(Crawler\CrawlerInterface::class, class_implements($crawler) ?: [])) {
                throw new Console\Exception\RuntimeException('The specified crawler is not valid.', 1604261885);
            }

            /** @var Crawler\CrawlerInterface $crawler */
            $crawler = new $crawler();
        } elseif ($this->isProgressBarEnabled($output, $input)) {
            // Use default verbose crawler
            $crawler = new Crawler\OutputtingCrawler();
        } else {
            // Use default crawler
            $crawler = new Crawler\ConcurrentCrawler();
        }

        if ($crawler instanceof Crawler\VerboseCrawlerInterface) {
            $crawler->setOutput($output);
        }

        if ($crawler instanceof Crawler\ConfigurableCrawlerInterface) {
            $crawlerOptions = $this->parseCrawlerOptions($crawlerOptions);
            $crawler->setOptions($crawlerOptions);

            if ($output->isVerbose() && [] !== $crawlerOptions) {
                $this->io->section('Using custom crawler options:');
                $this->io->writeln(json_encode($crawlerOptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $this->io->newLine();
            }
        } elseif (null !== $crawlerOptions) {
            $this->io->warning('You passed crawler options for a non-configurable crawler.');
        }

        return $crawler;
    }

    private function isProgressBarEnabled(
        Console\Output\OutputInterface $output,
        Console\Input\InputInterface $input,
        ): bool {
        if (false === $input->getOption('progress')) {
            return false;
        }

        return $output->isVerbose() || $input->getOption('progress');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCrawlerOptions(mixed $crawlerOptions): array
    {
        if (null === $crawlerOptions) {
            return [];
        }

        if (is_array($crawlerOptions)) {
            return $crawlerOptions;
        }

        if (is_string($crawlerOptions)) {
            $crawlerOptions = json_decode($crawlerOptions, true);
        }

        if (!is_array($crawlerOptions)) {
            throw new Console\Exception\RuntimeException('The given crawler options are invalid. Please pass crawler options as JSON-encoded array.', 1659120649);
        }

        return $crawlerOptions;
    }

    private function decorateSitemap(Sitemap\Sitemap $sitemap): string
    {
        return (string) $sitemap->getUri();
    }

    /**
     * @param list<Result\CrawlingResult> $crawledUrls
     *
     * @return list<string>
     */
    private function decorateCrawledUrls(array $crawledUrls): array
    {
        $urls = [];

        foreach ($crawledUrls as $crawlingState) {
            $urls[] = (string) $crawlingState->getUri();
        }

        return $urls;
    }

    private function validateSitemap(?string $input): ?Sitemap\Sitemap
    {
        if (null === $input) {
            return null;
        }

        return new Sitemap\Sitemap(new Psr7\Uri($input));
    }
}
