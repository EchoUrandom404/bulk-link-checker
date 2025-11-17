<?php
namespace acelabs\bulklinkchecker\services;

use acelabs\bulklinkchecker\BulkLinkChecker;
use Craft;
use craft\base\Component;
use craft\elements\Entry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use craft\helpers\UrlHelper;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

class ScannerService extends Component
{
    private Client $client;

    public function init(): void
    {
        parent::init();

        $this->client = new Client([
            'timeout' => 5,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 5,
                'track_redirects' => true,
            ],
            'headers' => [
                'User-Agent' => 'AceLabs BulkLinkChecker/1.0',
            ],
        ]);
    }

    public function getEntriesToScan(array $options = []): array
    {
        $query = Entry::find();

        // --- scope: sites ---
        $siteIds = $options['siteIds'] ?? [];
        if (!empty($siteIds)) {
            $query->siteId($siteIds);
        } else {
            // no selection = all sites
            $query->site('*');
        }

        // --- scope: status ---
        $includeDisabled = $options['includeDisabled'] ?? false;

        if ($includeDisabled) {
            // include all statuses + drafts + provisional drafts
            $query
                ->status(null)
                ->drafts(null)
                ->provisionalDrafts(null);
        } else {
            // only live, non-draft entries
            $query
                ->status('live')
                ->drafts(false)
                ->provisionalDrafts(false);
        }

        // --- scope: entry selection ---
        $entryScope   = $options['entryScope'] ?? 'all';
        $sectionIds   = $options['sectionIds'] ?? [];
        $entryTypeIds = $options['entryTypeIds'] ?? [];

        if ($entryScope === 'section' && !empty($sectionIds)) {
            $query->sectionId($sectionIds);
        } elseif ($entryScope === 'entryType' && !empty($entryTypeIds)) {
            $query->typeId($entryTypeIds);
        }

        $entries = $query->all();

        // Filter to entries that actually have URLs
        $pages = array_filter($entries, fn($e) => $e->getUrl() !== null);

        // Reset array keys for sane progress counters
        return array_values($pages);
    }



    public function scanEntries(array $entries, array $options = []): array
    {
        $linkMode          = $options['linkMode']          ?? 'internal'; // internal|external|both
        $checkEntryUrls    = $options['checkEntryUrls']    ?? true;
        $checkContentLinks = $options['checkContentLinks'] ?? true;
        $checkAssets       = $options['checkAssets']       ?? false;


        $config = Craft::$app->getConfig()->getConfigFromFile('bulk-link-checker');
        $defaultConcurrency = isset($config['concurrency'])
            ? (int)$config['concurrency']
            : 10;

        $concurrency = isset($options['concurrency'])
            ? (int)$options['concurrency']
            : $defaultConcurrency;

        // clamp it just in case
        $concurrency = max(1, min($concurrency, 50));

        Craft::info('Bulk Link Checker concurrency = '.$concurrency, __METHOD__);


        $resultsFlat    = [];
        $resultsGrouped = [];

        $ignorePatternsRaw = $options['ignorePatterns'] ?? '';
        $ignoreLines = preg_split('/\R+/', $ignorePatternsRaw, -1, PREG_SPLIT_NO_EMPTY);
        $ignoreLines = array_map('trim', $ignoreLines);

        foreach ($entries as $entry) {
            $entryUrl    = $entry->getUrl();
            $entryStatus = $entry->getStatus();

            $pageMeta = [
                'title'     => $entry->title,
                'url'       => $entryUrl,
                'siteName'  => $entry->getSite()->name,
                'cpEditUrl' => $entry->getCpEditUrl(),
            ];

            $linksForPage = [];
            $urlsForPage  = []; // [url => true] to de-dupe per page

            // -------------------------------
            // 1) LIVE entries: front-end HTML
            // -------------------------------
            if ($entryStatus === Entry::STATUS_LIVE && $entryUrl && is_string($entryUrl)) {

                // (a) Page URL itself
                if ($checkEntryUrls) {
                    $linksForPage[] = [
                        'entryId'       => $entry->id,
                        'url'           => $entryUrl,
                        'type'          => 'internal',
                        'statusCode'    => null,
                        'ok'            => false,
                        'message'       => '',
                        'hasRedirects'  => false,
                        'redirectCount' => 0,
                        'finalUrl'      => $entryUrl,
                        'redirectCodes' => [],
                        'redirectUrls'  => [],
                    ];
                    $urlsForPage[$entryUrl] = true;
                }

                // (b) Links in rendered HTML
                if ($checkContentLinks) {
                    $html = $this->fetchHtml($entryUrl);
                    if ($html !== null) {
                        $links = $this->extractLinksFromHtml($html, $entryUrl, $checkAssets);

                        foreach ($links as $link) {

                            // ignore patterns
                            $skip = false;
                            foreach ($ignoreLines as $needle) {
                                if ($needle !== '' && stripos($link['url'], $needle) !== false) {
                                    $skip = true;
                                    break;
                                }
                            }
                            if ($skip) {
                                continue;
                            }

                            // filter by mode
                            if ($linkMode === 'internal' && $link['type'] === 'external') {
                                continue;
                            }
                            if ($linkMode === 'external' && $link['type'] === 'internal') {
                                continue;
                            }

                            $linksForPage[] = [
                                'entryId'       => $entry->id,
                                'url'           => $link['url'],
                                'type'          => $link['type'],
                                'statusCode'    => null,
                                'ok'            => false,
                                'message'       => '',
                                'hasRedirects'  => false,
                                'redirectCount' => 0,
                                'finalUrl'      => $link['url'],
                                'redirectCodes' => [],
                                'redirectUrls'  => [],
                            ];
                            $urlsForPage[$link['url']] = true;
                        }
                    }
                }

            // -------------------------------------------
            // 2) NON-LIVE entries: scan field content only
            // -------------------------------------------
            } elseif ($checkContentLinks) {
                $links = $this->extractLinksFromEntryContent($entry, $checkAssets);

                foreach ($links as $link) {

                    // ignore patterns
                    $skip = false;
                    foreach ($ignoreLines as $needle) {
                        if ($needle !== '' && stripos($link['url'], $needle) !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }

                    // filter by mode
                    if ($linkMode === 'internal' && $link['type'] === 'external') {
                        continue;
                    }
                    if ($linkMode === 'external' && $link['type'] === 'internal') {
                        continue;
                    }

                    $linksForPage[] = [
                        'entryId'       => $entry->id,
                        'url'           => $link['url'],
                        'type'          => $link['type'],
                        'statusCode'    => null,
                        'ok'            => false,
                        'message'       => '',
                        'hasRedirects'  => false,
                        'redirectCount' => 0,
                        'finalUrl'      => $link['url'],
                        'redirectCodes' => [],
                        'redirectUrls'  => [],
                    ];
                    $urlsForPage[$link['url']] = true;
                }
            }

            // ----------------------------------
            // 3) Run URL checks concurrently
            // ----------------------------------
            $urlResults = [];
            if (!empty($urlsForPage)) {
                $urlResults = $this->checkUrlsWithPool(array_keys($urlsForPage), $concurrency);
            }

            // map results back to $linksForPage
            foreach ($linksForPage as &$link) {
                $url = $link['url'];

                if (isset($urlResults[$url])) {
                    [$statusCode, $ok, $message, $redirectMeta] = $urlResults[$url];

                    $link['statusCode']    = $statusCode;
                    $link['ok']            = $ok;
                    $link['message']       = $message;
                    $link['hasRedirects']  = $redirectMeta['hasRedirects'] ?? false;
                    $link['redirectCount'] = $redirectMeta['redirectCount'] ?? 0;
                    $link['finalUrl']      = $redirectMeta['finalUrl'] ?? $url;
                    $link['redirectCodes'] = $redirectMeta['codes'] ?? [];
                    $link['redirectUrls']  = $redirectMeta['urls'] ?? [];
                } else {
                    // just in case: something went weird
                    $link['statusCode'] = null;
                    $link['ok']         = false;
                    $link['message']    = $link['message'] ?: 'No response';
                }
            }
            unset($link); // break ref

            // Shape overall results
            if ($linkMode === 'external') {
                foreach ($linksForPage as $row) {
                    if ($row['type'] !== 'external') {
                        continue;
                    }
                    $row['foundOn']    = $pageMeta['title'] ?: $pageMeta['url'];
                    $row['foundOnUrl'] = $pageMeta['cpEditUrl'] ?? null;
                    $resultsFlat[]     = $row;
                }
            } else {
                $resultsGrouped[] = [
                    'page'  => $pageMeta,
                    'links' => $linksForPage,
                ];
            }
        }

        return $linkMode === 'external' ? $resultsFlat : $resultsGrouped;
    }

    /**
     * Check many URLs concurrently using Guzzle Pool.
     *
     * @param string[] $urls
     * @param int      $concurrency
     * @return array<string, array{0:?int,1:bool,2:string,3:array}>
     */
    private function checkUrlsWithPool(array $urls, int $concurrency = 10): array
    {
        $results = [];

        if (empty($urls)) {
            return $results;
        }

        $client = $this->client;

        $requests = function (array $urls) use ($client) {
            foreach ($urls as $url) {
                // key the pool by URL so we can map back easily
                yield $url => new \GuzzleHttp\Psr7\Request('GET', $url);
            }
        };

        $pool = new Pool($client, $requests($urls), [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response, string $url) use (&$results) {
                $statusCode   = $response->getStatusCode();
                $ok           = $statusCode >= 200 && $statusCode < 400;
                $message      = $response->getReasonPhrase();
                $redirectMeta = $this->buildRedirectMeta($response, $url);

                $results[$url] = [$statusCode, $ok, $message, $redirectMeta];
            },
            'rejected' => function ($reason, string $url) use (&$results) {
                $statusCode = null;
                $ok         = false;
                $message    = 'Network error';

                if ($reason instanceof GuzzleException) {
                    $errorMessage = $reason->getMessage() ?? '';

                    if (str_contains($errorMessage, 'Could not resolve host')) {
                        $message = 'Domain could not be resolved (DNS error)';
                    } elseif (str_contains($errorMessage, 'Connection timed out') || str_contains($errorMessage, 'timed out')) {
                        $message = 'Connection timed out';
                    } elseif (str_contains($errorMessage, 'SSL') || str_contains($errorMessage, 'certificate')) {
                        $message = 'Invalid SSL certificate or HTTPS error';
                    } elseif (str_contains($errorMessage, 'Failed to connect')) {
                        $message = 'Could not connect to server';
                    } else {
                        $message = 'Network error: ' . $errorMessage;
                    }
                } elseif ($reason instanceof \Throwable) {
                    $message = $reason->getMessage();
                }

                $results[$url] = [
                    $statusCode,
                    $ok,
                    $message,
                    [
                        'hasRedirects'  => false,
                        'redirectCount' => 0,
                        'codes'         => [],
                        'urls'          => [],
                        'finalUrl'      => $url,
                    ],
                ];
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }



    protected function extractLinksFromEntryContent(Entry $entry, bool $includeAssets = false): array
    {
        $fieldValues = $entry->getSerializedFieldValues();
        $rawUrls     = $this->collectUrlsFromMixed($fieldValues);

        $dedup = [];
        foreach ($rawUrls as $url) {
            $type = $this->isInternalUrl($url) ? 'internal' : 'external';

            $dedup[$url] = [
                'url'  => $url,
                'type' => $type,
            ];
        }

        return array_values($dedup);
    }



    /**
     * Recursively walk any nested array / scalar structure and extract URLs
     * from all string values.
     *
     * @param mixed $value
     * @return string[]
     */
    protected function collectUrlsFromMixed($value): array
    {
        $urls = [];

        if (is_string($value)) {
            $urls = array_merge($urls, $this->extractUrlsFromString($value));
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $urls = array_merge($urls, $this->collectUrlsFromMixed($item));
            }
        }

        return $urls;
    }

    /**
     * Helper: find URLs in a raw string using a simple regex.
     *
     * @return string[]
     */
    protected function extractUrlsFromString(string $value): array
    {
        $urls = [];

        $pattern = '/https?:\/\/[^\s"<]+/i';
        if (preg_match_all($pattern, $value, $matches)) {
            $urls = $matches[0];
        }

        return $urls;
    }


    public function scanAll(): array
    {
        $results = [];

        $entries = $this->getEntriesToScan();
        foreach ($entries as $entry) {
            $results[] = $this->scanEntry($entry);
        }        

        return $results;
    }

    private function checkUrl(string $url): array
    {
        $statusCode = null;
        $ok = false;
        $message = '';
        $redirectMeta = [
            'hasRedirects'  => false,
            'redirectCount' => 0,
            'codes'         => [],
            'urls'          => [],
            'finalUrl'      => $url,
        ];

        try {
            // Try HEAD first
            $response   = $this->client->request('HEAD', $url);
            $statusCode = $response->getStatusCode();

            $redirectMeta = $this->buildRedirectMeta($response, $url);

            // If server hates HEAD, fall back to GET
            if (in_array($statusCode, [400, 403, 404, 405], true)) {
                $response   = $this->client->request('GET', $url);
                $statusCode = $response->getStatusCode();

                // Rebuild redirect meta from the GET response
                $redirectMeta = $this->buildRedirectMeta($response, $url);
            }

            $ok      = $statusCode >= 200 && $statusCode < 400;
            $message = $response->getReasonPhrase();
        } catch (GuzzleException $e) {

            $errorMessage = $e->getMessage() ?? '';

            if (str_contains($errorMessage, 'Could not resolve host')) {
                $message = 'Domain could not be resolved (DNS error)';
            } elseif (str_contains($errorMessage, 'Connection timed out') || str_contains($errorMessage, 'timed out')) {
                $message = 'Connection timed out';
            } elseif (str_contains($errorMessage, 'SSL') || str_contains($errorMessage, 'certificate')) {
                $message = 'Invalid SSL certificate or HTTPS error';
            } elseif (str_contains($errorMessage, 'Failed to connect')) {
                $message = 'Could not connect to server';
            } else {
                $message = 'Network error: ' . $errorMessage;
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        // [statusCode, ok, message, redirectMeta]
        return [$statusCode, $ok, $message, $redirectMeta];
    }

    /**
     * Build redirect metadata from a Guzzle response.
     */
    private function buildRedirectMeta(\Psr\Http\Message\ResponseInterface $response, string $originalUrl): array
    {
        $redirectUrls  = $response->getHeader('X-Guzzle-Redirect-History');
        $redirectCodes = $response->getHeader('X-Guzzle-Redirect-Status-History');

        $hasRedirects  = !empty($redirectCodes);
        $redirectCount = $hasRedirects ? count($redirectCodes) : 0;

        // Guzzle's history header usually contains only the intermediate + final locations.
        $finalUrl = !empty($redirectUrls) ? end($redirectUrls) : $originalUrl;

        return [
            'hasRedirects'  => $hasRedirects,
            'redirectCount' => $redirectCount,
            'codes'         => $redirectCodes,
            'urls'          => $redirectUrls,
            'finalUrl'      => $finalUrl,
        ];
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->client->request('GET', $url);
            $status   = $response->getStatusCode();
            if ($status >= 200 && $status < 400) {
                return (string)$response->getBody();
            }
        } catch (\Throwable $e) {
            // ignore, we'll just have no HTML
        }
        return null;
    }

    private function extractLinksFromHtml(string $html, string $entryUrl, bool $includeAssets = false): array
    {
        $links = [];

        $baseHost = parse_url($entryUrl, PHP_URL_HOST);
        if (!$baseHost) {
            $baseHost = null;
        }

        $dom = new \DOMDocument();
        // suppress malformed HTML warnings
        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);

        // <a href="">
        foreach ($xpath->query('//a[@href]') as $node) {
            /** @var \DOMElement $node */
            $href = trim($node->getAttribute('href'));
            if (!$href || str_starts_with($href, 'mailto:') || str_starts_with($href, '#')) {
                continue; // skip emails + anchors
            }

            $url = $this->normaliseUrl($href, $entryUrl);
            if (!$url) {
                continue;
            }

            $type = $this->classifyUrl($url, $baseHost);
            if ($type === 'asset' && !$includeAssets) {
                continue;
            }

            $links[] = [
                'url'  => $url,
                'type' => $type,
            ];
        }

        // <img src>, <source src> etc as assets
        if ($includeAssets) {
            foreach ($xpath->query('//*[@src]') as $node) {
                /** @var \DOMElement $node */
                $src = trim($node->getAttribute('src'));
                if (!$src) {
                    continue;
                }
                $url = $this->normaliseUrl($src, $entryUrl);
                if (!$url) {
                    continue;
                }

                $links[] = [
                    'url'  => $url,
                    'type' => 'asset',
                ];
            }
        }

        // de-duplicate by URL+type
        $dedup = [];
        foreach ($links as $link) {
            $key = $link['type'].'|'.$link['url'];
            $dedup[$key] = $link;
        }

        return array_values($dedup);
    }

    private function normaliseUrl(string $url, string $baseUrl): ?string
    {
        // ignore javascript:void(0) etc
        if (str_starts_with($url, 'javascript:')) {
            return null;
        }

        // already absolute
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme.':'.$url;
        }

        // relative URL
        $parsed = parse_url($baseUrl);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return null;
        }

        $basePath = $parsed['path'] ?? '/';
        // if base path looks like a file, strip to directory
        if (str_contains(basename($basePath), '.')) {
            $basePath = rtrim(dirname($basePath), '/').'/';
        }

        $path = $basePath.$url;
        // normalise "foo/../bar"
        $path = preg_replace('#/\.?/#', '/', $path);
        $path = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $path);

        return $parsed['scheme'].'://'.$parsed['host'].$path;
    }

    private function classifyUrl(string $url, ?string $baseHost): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        // crude asset detection by extension
        if (preg_match('#\.(jpe?g|png|gif|webp|svg|pdf|docx?|xlsx?|pptx?)$#i', $path)) {
            return 'asset';
        }

        // multi-site aware internal detection
        if ($this->isInternalUrl($url)) {
            return 'internal';
        }

        return 'external';
    }


    protected function isInternalUrl(string $url): bool
    {
        // Relative URLs are always internal
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return true;
        }

        $linkHost = parse_url($url, PHP_URL_HOST);
        if (!$linkHost) {
            // No host, and not clearly relative? treat as internal by default
            return true;
        }

        foreach (Craft::$app->sites->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();
            if (!$baseUrl) {
                continue;
            }

            $baseHost = parse_url($baseUrl, PHP_URL_HOST);

            if ($baseHost && strcasecmp($baseHost, $linkHost) === 0) {
                return true;
            }
        }

        return false;
    }


}