<?php

namespace acelabs\bulklinkchecker\jobs;

use Craft;
use craft\queue\BaseJob;
use acelabs\bulklinkchecker\BulkLinkChecker;

class ScanJob extends BaseJob
{
    public ?int $userId = null;
    
    public array $options = [];

    public function execute($queue): void
    {
        $userId  = $this->userId ?? 0;
        $options = $this->options ?? [];

        $statusKey  = "bulk-link-checker:status:{$userId}";
        $resultsKey = "bulk-link-checker:results:{$userId}";

        Craft::$app->getCache()->set($statusKey, [
            'status'    => 'running',
            'progress'  => 0,
            'scannedAt' => null,
        ], 3600);

        $scanner = BulkLinkChecker::$plugin->getScanner();
        $entries = $scanner->getEntriesToScan($options);
        $total   = count($entries) ?: 1;

        // progress: we still report per-entry progress even though scanEntries()
        // does the actual link logic
        $chunkResults = [];
        foreach ($entries as $i => $entry) {
            // scan just this one entry – re-use scanEntries() for consistency
            $resultForEntry = $scanner->scanEntries([$entry], $options);


            if (($options['linkMode'] ?? 'internal') === 'external') {
                // flat
                $chunkResults = array_merge($chunkResults, $resultForEntry);
            } else {
                // grouped
                $chunkResults = array_merge($chunkResults, $resultForEntry);
            }

            $progress = ($i + 1) / $total;
            $this->setProgress($queue, $progress, "Scanning ".($i + 1)." of {$total}");

            Craft::$app->getCache()->set($statusKey, [
                'status'    => 'running',
                'progress'  => $progress,
                'scannedAt' => null,
            ], 3600);
        }

        $now = time();

        Craft::$app->getCache()->set($resultsKey, [
            'results'   => $chunkResults,
            'scannedAt' => $now,
            'options'   => $options, // for sticky UI
        ], 3600);

        Craft::$app->getCache()->set($statusKey, [
            'status'    => 'completed',
            'progress'  => 1,
            'scannedAt' => $now,
        ], 3600);
    }


    protected function defaultDescription(): ?string
    {
        return Craft::t('bulk-link-checker', 'Bulk link checker scan');
    }
}

