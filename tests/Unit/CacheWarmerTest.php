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

namespace EliasHaeussler\CacheWarmup\Tests\Unit;

use EliasHaeussler\CacheWarmup\CacheWarmer;
use EliasHaeussler\CacheWarmup\Exception;
use EliasHaeussler\CacheWarmup\Sitemap;
use Generator;
use GuzzleHttp\Psr7;
use PHPUnit\Framework;
use Prophecy\PhpUnit;
use Psr\Http\Client;
use Psr\Http\Message;

use function sprintf;

/**
 * CacheWarmerTest.
 *
 * @author Elias Häußler <elias@haeussler.dev>
 * @license GPL-3.0-or-later
 */
final class CacheWarmerTest extends Framework\TestCase
{
    use PhpUnit\ProphecyTrait;
    use RequestProphecyTrait;
    use CacheWarmupResultProcessorTrait;

    private CacheWarmer $subject;

    protected function setUp(): void
    {
        $this->clientProphecy = $this->prophesize(Client\ClientInterface::class);
        $this->subject = new CacheWarmer(client: $this->clientProphecy->reveal());
    }

    /**
     * @test
     * @dataProvider runCrawlsListOfUrlsDataProvider
     *
     * @param list<Psr7\Uri> $urls
     */
    public function runCrawlsListOfUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->subject->addUrl($url);
        }

        $result = $this->subject->run();
        $processedUrls = $this->getProcessedUrlsFromCacheWarmupResult($result);

        self::assertSame([], array_diff($urls, $processedUrls));
    }

    /**
     * @test
     */
    public function addSitemapsThrowsExceptionIfInvalidSitemapsIsGiven(): void
    {
        $this->expectException(Exception\InvalidSitemapException::class);
        $this->expectExceptionMessage(sprintf('Sitemaps must be of type string or %s, bool given.', Sitemap::class));
        $this->expectExceptionCode(1604055096);

        /* @phpstan-ignore-next-line */
        $this->subject->addSitemaps([false]);
    }

    /**
     * @test
     */
    public function addSitemapsIgnoresSitemapsIfLimitWasExceeded(): void
    {
        $subject = new CacheWarmer(limit: 1, client: $this->clientProphecy->reveal());
        $expected = [
            new Psr7\Uri('https://www.example.org/'),
        ];

        $this->prophesizeSitemapRequest('valid_sitemap_2');

        // Add sitemap (first time)
        $subject->addSitemaps('https://www.example.org/sitemap.xml');
        self::assertEquals($expected, $subject->getUrls());

        // Add sitemap (second time)
        $subject->addSitemaps('https://www.example.com/sitemap.xml');
        self::assertEquals($expected, $subject->getUrls());
    }

    /**
     * @test
     * @dataProvider addSitemapsAddsAndParsesGivenSitemapsDataProvider
     *
     * @param list<string|Sitemap>|string|Sitemap      $sitemaps
     * @param list<Sitemap>                            $expectedSitemaps
     * @param list<Message\UriInterface>               $expectedUrls
     * @param array<string, Message\UriInterface|null> $prophesizedRequests
     */
    public function addSitemapsAddsAndParsesGivenSitemaps(
        array|string|Sitemap $sitemaps,
        array $expectedSitemaps,
        array $expectedUrls,
        array $prophesizedRequests = [],
    ): void {
        foreach ($prophesizedRequests as $fixture => $expectedUri) {
            $this->prophesizeSitemapRequest($fixture, $expectedUri);
        }

        $this->subject->addSitemaps($sitemaps);

        self::assertEquals($expectedSitemaps, $this->subject->getSitemaps());
        self::assertEquals($expectedUrls, $this->subject->getUrls());
    }

    /**
     * @test
     */
    public function addUrlAddsGivenUrlToListOfUrls(): void
    {
        $url = $this->getExpectedUri('https://www.example.org/sitemap.xml');

        self::assertSame([$url], $this->subject->addUrl($url)->getUrls());
    }

    /**
     * @test
     */
    public function addUrlDoesNotAddAlreadyAvailableUrlToListOfUrls(): void
    {
        $url = $this->getExpectedUri('https://www.example.org/sitemap.xml');

        self::assertSame([$url], $this->subject->addUrl($url)->addUrl($url)->getUrls());
    }

    /**
     * @test
     */
    public function addUrlDoesNotAddUrlIfLimitWasExceeded(): void
    {
        $url1 = $this->getExpectedUri('https://www.example.org/sitemap.xml');
        $url2 = $this->getExpectedUri('https://www.example.com/sitemap.xml');

        $subject = new CacheWarmer(limit: 1, client: $this->clientProphecy->reveal());
        $subject->addUrl($url1)->addUrl($url2);

        self::assertSame([$url1], $subject->getUrls());
    }

    /**
     * @test
     */
    public function getLimitReturnsUrlLimit(): void
    {
        self::assertSame(0, $this->subject->getLimit());
    }

    /**
     * @return Generator<string, array{array<int, Psr7\Uri>}>
     */
    public function runCrawlsListOfUrlsDataProvider(): Generator
    {
        yield 'no urls' => [
            [],
        ];
        yield 'multiple urls' => [
            [
                $this->getExpectedUri('https://www.example.org'),
                $this->getExpectedUri('https://www.example.com'),
            ],
        ];
    }

    /**
     * @return Generator<string, array{0: list<string|Sitemap>|string|Sitemap, 1: list<Sitemap>, list<Message\UriInterface>, 2?: array<string, Message\UriInterface|null>}>
     */
    public function addSitemapsAddsAndParsesGivenSitemapsDataProvider(): Generator
    {
        yield 'empty sitemaps' => [
            [],
            [],
            [],
        ];
        yield 'one sitemap url' => [
            'https://www.example.org/sitemap.xml',
            [
                new Sitemap(new Psr7\Uri('https://www.example.org/sitemap.xml')),
            ],
            [
                new Psr7\Uri('https://www.example.org/'),
                new Psr7\Uri('https://www.example.org/foo'),
                new Psr7\Uri('https://www.example.org/baz'),
            ],
            [
                'valid_sitemap_2' => null,
            ],
        ];
        yield 'one sitemap object' => [
            new Sitemap(new Psr7\Uri('https://www.example.org/sitemap.xml')),
            [
                new Sitemap(new Psr7\Uri('https://www.example.org/sitemap.xml')),
            ],
            [
                new Psr7\Uri('https://www.example.org/'),
                new Psr7\Uri('https://www.example.org/foo'),
                new Psr7\Uri('https://www.example.org/baz'),
            ],
            [
                'valid_sitemap_2' => null,
            ],
        ];
        yield 'multiple sitemap urls' => [
            [
                'https://www.example.org/sitemap.xml',
                'https://www.example.com/sitemap.xml',
            ],
            [
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.com/sitemap.xml')),
            ],
            [
                new Psr7\Uri('https://www.example.org/'),
                new Psr7\Uri('https://www.example.org/foo'),
                new Psr7\Uri('https://www.example.org/baz'),
                new Psr7\Uri('https://www.example.com/'),
                new Psr7\Uri('https://www.example.com/foo'),
            ],
            [
                'valid_sitemap_2' => new Psr7\Uri('https://www.example.org/sitemap.xml'),
                'valid_sitemap_3' => new Psr7\Uri('https://www.example.com/sitemap.xml'),
            ],
        ];
        yield 'multiple sitemap objects' => [
            [
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.com/sitemap.xml')),
            ],
            [
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.com/sitemap.xml')),
            ],
            [
                new Psr7\Uri('https://www.example.org/'),
                new Psr7\Uri('https://www.example.org/foo'),
                new Psr7\Uri('https://www.example.org/baz'),
                new Psr7\Uri('https://www.example.com/'),
                new Psr7\Uri('https://www.example.com/foo'),
            ],
            [
                'valid_sitemap_2' => new Psr7\Uri('https://www.example.org/sitemap.xml'),
                'valid_sitemap_3' => new Psr7\Uri('https://www.example.com/sitemap.xml'),
            ],
        ];
        yield 'mix of sitemap url set and sitemap index' => [
            [
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.com/sitemap.xml')),
            ],
            [
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.com/sitemap.xml')),
                new Sitemap($this->getExpectedUri('https://www.example.org/sitemap_en.xml')),
            ],
            [
                new Psr7\Uri('https://www.example.org/'),
                new Psr7\Uri('https://www.example.org/foo'),
                new Psr7\Uri('https://www.example.org/baz'),
                new Psr7\Uri('https://www.example.com/'),
                new Psr7\Uri('https://www.example.com/foo'),
            ],
            [
                'valid_sitemap_2' => new Psr7\Uri('https://www.example.org/sitemap.xml'),
                'valid_sitemap_1' => new Psr7\Uri('https://www.example.com/sitemap.xml'),
                'valid_sitemap_3' => new Psr7\Uri('https://www.example.org/sitemap_en.xml'),
            ],
        ];
    }

    protected function tearDown(): void
    {
        $this->closeStreams();
    }

    private function getExpectedUri(string $url): Psr7\Uri
    {
        $uri = new Psr7\Uri($url);

        // Due to a new introduced behavior in guzzlehttp/psr7 2.0.0,
        // we have to call the __toString() method in order to explicitly
        // create first-level caches within the instance. Otherwise,
        // self::assertEquals() will fail,
        // see https://github.com/guzzle/psr7/pull/293
        $uri->__toString();

        return $uri;
    }
}
