<?php

declare(strict_types=1);

/*
 * This file is part of the Composer package "eliashaeussler/cache-warmup".
 *
 * Copyright (C) 2023 Elias Häußler <elias@haeussler.dev>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace EliasHaeussler\CacheWarmup\Crawler;

use EliasHaeussler\CacheWarmup\Http;
use EliasHaeussler\CacheWarmup\Result;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log;

/**
 * ConcurrentCrawler.
 *
 * @author Elias Häußler <elias@haeussler.dev>
 * @license GPL-3.0-or-later
 *
 * @extends AbstractConfigurableCrawler<array{
 *     concurrency: int,
 *     request_method: string,
 *     request_headers: array<string, string>,
 *     request_options: array<string, mixed>,
 *     client_config: array<string, mixed>,
 * }>
 */
final class ConcurrentCrawler extends AbstractConfigurableCrawler implements LoggingCrawlerInterface
{
    use ConcurrentCrawlerTrait;

    protected static array $defaultOptions = [
        'concurrency' => 5,
        'request_method' => 'HEAD',
        'request_headers' => [],
        'request_options' => [],
        'client_config' => [],
    ];

    private readonly ClientInterface $client;
    private ?Log\LoggerInterface $logger = null;

    /**
     * @phpstan-var Log\LogLevel::*
     */
    private string $logLevel = Log\LogLevel::ERROR;

    public function __construct(
        array $options = [],
        ClientInterface $client = null,
    ) {
        parent::__construct($options);
        $this->client = $client ?? new Client($this->options['client_config']);
    }

    public function crawl(array $urls): Result\CacheWarmupResult
    {
        $resultHandler = new Http\Message\Handler\ResultCollectorHandler();
        $handlers = [$resultHandler];

        // Create log handler
        if (null !== $this->logger) {
            $logHandler = new Http\Message\Handler\LogHandler($this->logger, $this->logLevel);
            $handlers[] = $logHandler;
        }

        // Start crawling
        $this->createPool($urls, $this->client, $handlers)->promise()->wait();

        return $resultHandler->getResult();
    }

    public function setLogger(Log\LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setLogLevel(string $logLevel): void
    {
        $this->logLevel = $logLevel;
    }
}
