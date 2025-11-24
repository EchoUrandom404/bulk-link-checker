<?php
namespace acelabs\bulklinkchecker\controllers;

namespace acelabs\bulklinkchecker\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use acelabs\bulklinkchecker\BulkLinkChecker;
use acelabs\bulklinkchecker\jobs\ScanJob;

class ScanController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $user   = Craft::$app->getUser()->getIdentity();
        $userId = $user?->id ?? 0;

        $resultsKey = "bulk-link-checker:results:{$userId}";
        $data       = Craft::$app->getCache()->get($resultsKey) ?: null;

        $options = $data['options'] ?? [];

        $optionsKey = "bulk-link-checker:options:{$userId}";
        $optionsFromOptionsKey = Craft::$app->getCache()->get($optionsKey) ?: [];

        $data    = Craft::$app->getCache()->get($resultsKey) ?: null;
        $options = $data['options'] ?? $optionsFromOptionsKey;

        return $this->renderTemplate('bulk-link-checker/index', [
            'results'   => $data['results']   ?? null,
            'scannedAt' => $data['scannedAt'] ?? null,

            // existing sticky options
            'siteIds'           => $options['siteIds']           ?? [],
            'entryScope'        => $options['entryScope']        ?? 'all',
            'sectionIds'        => $options['sectionIds']        ?? [],
            'entryTypeIds'      => $options['entryTypeIds']      ?? [],
            'includeDisabled'   => $options['includeDisabled']   ?? false,
            'checkContentLinks' => $options['checkContentLinks'] ?? true,
            'checkAssets'       => $options['checkAssets']       ?? false,

            // new UI fields, so the toggles stay sticky
            'ignorePatterns'    => $options['ignorePatterns']    ?? '',
            'linkMode'          => $options['linkMode']          ?? 'both',
        ]);
    }



    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $user    = Craft::$app->getUser()->getIdentity();
        $userId  = $user?->id ?? 0;
        $linkMode = $request->getBodyParam('linkMode', 'both');

        // 1) Gather options from the new form
        $options = [
            'siteIds'         => (array)$request->getBodyParam('siteIds', []),
            'entryScope'      => $request->getBodyParam('entryScope', 'all'),
            'sectionIds'      => (array)$request->getBodyParam('sectionIds', []),
            'entryTypeIds'    => (array)$request->getBodyParam('entryTypeIds', []),
            'includeDisabled' => (bool)$request->getBodyParam('includeDisabled', false),

            // extra checks
            'checkContentLinks' => (bool)$request->getBodyParam('checkContentLinks', true),
            'checkAssets'       => (bool)$request->getBodyParam('checkAssets', false),
            'linkMode'          => $linkMode,
            'ignorePatterns' => (string)$request->getBodyParam('ignorePatterns', ''),
        ];

        // 2) Backwards-compat: always check entry URLs
        $options['checkEntryUrls'] = true;

        // 4) Prepare cache keys
        $statusKey  = "bulk-link-checker:status:{$userId}";
        $resultsKey = "bulk-link-checker:results:{$userId}";
        $optionsKey = "bulk-link-checker:options:{$userId}";

        // Reset status + previous results
        Craft::$app->getCache()->set($statusKey, [
            'status'    => 'queued',
            'progress'  => 0,
            'scannedAt' => null,
        ], 3600);

        Craft::$app->getCache()->delete($resultsKey);

        // 5) Push the job to the queue
        Craft::$app->getQueue()->push(new ScanJob([
            'userId'  => $userId,
            'options' => $options,
        ]));

        // 6) Tell the user it's queued (NOT completed)
        Craft::$app->getSession()->setNotice(Craft::t(
            'bulk-link-checker',
            'Scan queued – it will run via the queue manager.'
        ));

        
        Craft::$app->getCache()->set($optionsKey, $options, 3600);


        // 7) Back to main screen
        return $this->redirect('bulk-link-checker');
    }



    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();
        $userId = $user?->id ?? 0;

        $statusKey = "bulk-link-checker:status:{$userId}";
        $resultsKey = "bulk-link-checker:results:{$userId}";

        $status  = Craft::$app->getCache()->get($statusKey) ?: ['status' => 'idle', 'progress' => 0, 'scannedAt' => null];
        $results = Craft::$app->getCache()->get($resultsKey);

        return $this->asJson([
            'status'    => $status['status'],
            'progress'  => $status['progress'],
            'scannedAt' => $status['scannedAt'],
            'hasResults' => (bool)$results,
        ]);
    }

    public function actionExport(): Response
    {
        $this->requireLogin();

        $user   = Craft::$app->getUser()->getIdentity();
        $userId = $user?->id ?? 0;

        $resultsKey = "bulk-link-checker:results:{$userId}";
        $data       = Craft::$app->getCache()->get($resultsKey) ?: null;

        if (!$data || empty($data['results']) || !is_array($data['results'])) {
            Craft::$app->getSession()->setError(Craft::t(
                'bulk-link-checker',
                'No scan results found to export.'
            ));

            return $this->redirect('bulk-link-checker');
        }

        $results = $data['results'];
        $grouped = isset($results[0]['links']);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="bulk-link-checker-export.csv"'
        );

        // write CSV into a temp stream
        $fp = fopen('php://temp', 'r+');

        if ($grouped) {
            // internal/both mode
            fputcsv($fp, [
                'Page title',
                'Entry status',
                'Site',
                'Page URL',
                'Entry CP URL',
                'Link URL',
                'Link type',
                'HTTP status',
                'OK?',
                'Message',
                'Has redirects',
                'Redirect count',
                'Final URL',
            ]);

            foreach ($results as $pageResult) {
                $page  = $pageResult['page']  ?? [];
                $links = $pageResult['links'] ?? [];

                $pageTitle  = $page['title']    ?? '';
                $pageStatus = $page['status']   ?? '';
                $siteName   = $page['siteName'] ?? '';
                $pageUrl    = $page['url']      ?? '';
                $cpEditUrl  = $page['cpEditUrl']?? '';

                foreach ($links as $link) {

                    fputcsv($fp, [
                        $pageTitle,
                        $pageStatus,
                        $siteName,
                        $pageUrl,
                        $cpEditUrl,
                        $link['url']        ?? '',
                        $link['type']       ?? '',
                        $link['statusCode'] ?? '',
                        !empty($link['ok']) ? 'yes' : 'no',
                        $link['message']    ?? '',
                        !empty($link['hasRedirects']) ? 'yes' : 'no',
                        $link['redirectCount'] ?? 0,
                        $link['finalUrl'] ?? ($link['url'] ?? ''),
                    ]);
                }
            }
        } else {
            // external-only flat mode
            fputcsv($fp, [
                'Link URL',
                'Type',
                'HTTP status',
                'OK?',
                'Message',
                'Found on',
                'Found on (CP URL)',
                'Has redirects',
                'Redirect count',
                'Final URL',
            ]);

            foreach ($results as $row) {
                fputcsv($fp, [
                    $row['url']        ?? '',
                    $row['type']       ?? '',
                    $row['statusCode'] ?? '',
                    !empty($row['ok']) ? 'yes' : 'no',
                    $row['message']    ?? '',
                    $row['foundOn']    ?? '',
                    $row['foundOnUrl'] ?? '',
                    !empty($row['hasRedirects']) ? 'yes' : 'no',
                    $row['redirectCount'] ?? 0,
                    $row['finalUrl'] ?? ($row['url'] ?? ''),
                ]);
            }
        }

        rewind($fp);
        $response->content = stream_get_contents($fp);
        fclose($fp);

        return $response;
    }
}
