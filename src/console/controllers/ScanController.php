<?php

namespace acelabs\bulklinkchecker\console\controllers;

use acelabs\bulklinkchecker\BulkLinkChecker;
use acelabs\bulklinkchecker\services\ScannerService;
use Craft;
use yii\console\Controller;
use yii\console\ExitCode;

class ScanController extends Controller
{
    /**
     * Comma-separated site IDs or handles (optional).
     * Example: --sites=1,2 or --sites=default,en
     * @var string|null
     */
    public ?string $sites = null;

    /**
     * all|section|entryType
     * @var string
     */
    public string $entryScope = 'all';

    /**
     * Comma-separated section IDs (only used when entryScope=section)
     * @var string|null
     */
    public ?string $sections = null;

    /**
     * Comma-separated entry type IDs (only used when entryScope=entryType)
     * @var string|null
     */
    public ?string $entryTypes = null;

    /**
     * both|internal|external
     * @var string
     */
    public string $linkMode = 'both';

    /**
     * Include disabled/draft entries in content scanning.
     * @var bool
     */
    public bool $includeDisabled = false;

    /**
     * Scan links in content fields.
     * @var bool
     */
    public bool $checkContentLinks = true;

    /**
     * Scan assets (images, files) in HTML/content.
     * @var bool
     */
    public bool $checkAssets = false;

    /**
     * Ignore patterns (newline-separated) – for CLI, pass single string with "\n".
     * @var string|null
     */
    public ?string $ignorePatterns = null;

    /**
     * text|json
     * @var string
     */
    public string $format = 'text';

    /**
     * When true, also print OK links.
     * @var bool
     */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return [
            'sites',
            'entryScope',
            'sections',
            'entryTypes',
            'linkMode',
            'includeDisabled',
            'checkContentLinks',
            'checkAssets',
            'ignorePatterns',
            'format',
            'verbose',
        ];
    }

    public function optionAliases(): array
    {
        return [
            's' => 'sites',
            'm' => 'linkMode',
            'i' => 'includeDisabled',
            'c' => 'checkContentLinks',
            'a' => 'checkAssets',
            'f' => 'format',
            'v' => 'verbose',
        ];
    }

    /**
     * Run a one-off scan via CLI.
     *
     * Usage:
     * php craft bulk-link-checker/scan/run
     */
    public function actionRun(): int
    {
        /** @var ScannerService $scanner */
        $scanner = BulkLinkChecker::$plugin->getScanner();

        // --- sites ---
        $siteIds = null;
        if ($this->sites) {
            $pieces = array_filter(array_map('trim', explode(',', $this->sites)));
            $siteIds = [];
            foreach ($pieces as $piece) {
                if (ctype_digit($piece)) {
                    $siteIds[] = (int)$piece;
                } else {
                    $site = Craft::$app->sites->getSiteByHandle($piece);
                    if ($site) {
                        $siteIds[] = (int)$site->id;
                    }
                }
            }
        }

        // --- sections / entry types ---
        $sectionIds = null;
        if ($this->sections) {
            $sectionIds = array_map('intval', array_filter(array_map('trim', explode(',', $this->sections))));
        }

        $entryTypeIds = null;
        if ($this->entryTypes) {
            $entryTypeIds = array_map('intval', array_filter(array_map('trim', explode(',', $this->entryTypes))));
        }

        $options = [
            'siteIds'           => $siteIds ?? [],
            'entryScope'        => $this->entryScope,
            'sectionIds'        => $sectionIds ?? [],
            'entryTypeIds'      => $entryTypeIds ?? [],
            'linkMode'          => $this->linkMode,
            'includeDisabled'   => $this->includeDisabled,
            'checkContentLinks' => $this->checkContentLinks,
            'checkAssets'       => $this->checkAssets,
            'ignorePatterns'    => $this->ignorePatterns ?? '',
            // CLI: always check page URLs too
            'checkEntryUrls'    => true,
        ];

        $entries = $scanner->getEntriesToScan($options);

        if ($this->format === 'text') {
            $this->stdout("Bulk Link Checker: scanning ".count($entries)." entries...\n");
        }

        $results = $scanner->scanEntries($entries, $options);

        // flatten into one list of links so CI/CD can reason about it easily
        $flatLinks = [];
        if ($this->linkMode === 'external') {
            // scanEntries already returned flat list
            $flatLinks = $results;
        } else {
            // grouped by page
            foreach ($results as $pageResult) {
                $page  = $pageResult['page'] ?? [];
                $links = $pageResult['links'] ?? [];

                foreach ($links as $row) {
                    $row['foundOn']    = $page['title'] ?? $page['url'] ?? '';
                    $row['foundOnUrl'] = $page['url'] ?? null;
                    $flatLinks[]       = $row;
                }
            }
        }

        $total   = count($flatLinks);
        $errors  = 0;
        $payload = [];

        foreach ($flatLinks as $link) {
            $statusCode = $link['statusCode'] ?? null;
            $ok         = $link['ok'] ?? false;
            $url        = $link['url'] ?? '';
            $type       = $link['type'] ?? '';
            $message    = $link['message'] ?? '';
            $foundOn    = $link['foundOn'] ?? '';

            if (!$ok) {
                $errors++;

                if ($this->format === 'text') {
                    $this->stderr(sprintf(
                        "[ERROR] %s %s (%s) – %s\n",
                        $statusCode ?? '—',
                        $url,
                        $type,
                        $message ?: 'Unknown error'
                    ));
                }

                $payload[] = [
                    'url'        => $url,
                    'type'       => $type,
                    'statusCode' => $statusCode,
                    'message'    => $message,
                    'foundOn'    => $foundOn,
                ];
            } elseif ($this->verbose && $this->format === 'text') {
                $this->stdout(sprintf(
                    "[OK]    %s %s (%s)\n",
                    $statusCode ?? '—',
                    $url,
                    $type
                ));
            }
        }

        if ($this->format === 'text') {
            $this->stdout(
                sprintf(
                    "\nSummary: %d links checked, %d with issues.\n",
                    $total,
                    $errors
                )
            );
        } else {
            $this->stdout(json_encode([
                'totalLinks' => $total,
                'errorCount' => $errors,
                'errors'     => $payload,
            ], JSON_PRETTY_PRINT) . PHP_EOL);
        }

        // CI/CD-friendly: non-zero exit if any broken links
        return $errors > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
