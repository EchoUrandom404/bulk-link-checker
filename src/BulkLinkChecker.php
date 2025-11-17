<?php
namespace acelabs\bulklinkchecker;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;


class BulkLinkChecker extends Plugin
{
    public static BulkLinkChecker $plugin;

    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Web vs console controller namespace
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'acelabs\bulklinkchecker\console\controllers';
        } else {
            $this->controllerNamespace = 'acelabs\bulklinkchecker\controllers';
        }

        // Register CP URL rules (for web)
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['bulk-link-checker']      = 'bulk-link-checker/scan/index';
                $event->rules['bulk-link-checker/scan'] = 'bulk-link-checker/scan/run';
            }
        );

        Craft::info(
            Craft::t(
                'bulk-link-checker',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    public function getScanner(): services\ScannerService
    {
        return $this->get('scanner');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'scanner' => services\ScannerService::class,
            ],
        ];
    }
}