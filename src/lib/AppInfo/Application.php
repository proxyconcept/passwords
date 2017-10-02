<?php
/**
 * Created by PhpStorm.
 * User: marius
 * Date: 26.08.17
 * Time: 17:01
 */

namespace OCA\Passwords\AppInfo;

use OCA\Passwords\Controller\AccessController;
use OCA\Passwords\Controller\AdminSettingsController;
use OCA\Passwords\Controller\Api\PasswordApiController;
use OCA\Passwords\Controller\Api\ServiceApiController;
use OCA\Passwords\Controller\PageController;
use OCA\Passwords\Cron\CheckPasswordsJob;
use OCA\Passwords\Db\PasswordMapper;
use OCA\Passwords\Db\RevisionMapper;
use OCA\Passwords\Helper\Favicon\BetterIdeaHelper;
use OCA\Passwords\Helper\Favicon\DefaultFaviconHelper;
use OCA\Passwords\Helper\Favicon\DuckDuckGoHelper;
use OCA\Passwords\Helper\Favicon\GoogleFaviconHelper;
use OCA\Passwords\Helper\Favicon\LocalFaviconHelper;
use OCA\Passwords\Helper\Image\GdHelper;
use OCA\Passwords\Helper\Image\ImagickHelper;
use OCA\Passwords\Helper\PageShot\DefaultPageShotHelper;
use OCA\Passwords\Helper\PageShot\ScreenShotApiHelper;
use OCA\Passwords\Helper\PageShot\ScreenShotLayerHelper;
use OCA\Passwords\Helper\PageShot\ScreenShotMachineHelper;
use OCA\Passwords\Helper\PageShot\WkhtmlImageHelper;
use OCA\Passwords\Helper\PasswordApiObjectHelper;
use OCA\Passwords\Helper\SecurityCheck\BigLocalDbSecurityCheckHelper;
use OCA\Passwords\Helper\SecurityCheck\HaveIBeenPwnedHelper;
use OCA\Passwords\Helper\SecurityCheck\SmallLocalDbSecurityCheckHelper;
use OCA\Passwords\Helper\Words\LocalWordsHelper;
use OCA\Passwords\Helper\Words\SnakesWordsHelper;
use OCA\Passwords\Services\ConfigurationService;
use OCA\Passwords\Services\EncryptionService;
use OCA\Passwords\Services\FaviconService;
use OCA\Passwords\Services\FileCacheService;
use OCA\Passwords\Services\HelperService;
use OCA\Passwords\Services\PageShotService;
use OCA\Passwords\Services\PasswordService;
use OCA\Passwords\Services\RevisionService;
use OCA\Passwords\Services\ValidationService;
use OCA\Passwords\Services\WordsService;
use OCA\Passwords\Settings\AdminSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;
use OCP\Files\IAppData;

/**
 * Class Application
 *
 * @package OCA\Passwords\AppInfo
 */
class Application extends App {

    const APP_NAME = 'passwords';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_NAME, $urlParams);

        $this->registerPersonalSettings();
        //$this->registerActivities();
        $this->registerDiClasses();
    }

    /**
     *
     */
    protected function registerActivities(): void {
        $this->getContainer()->getServer()->getActivityManager()->registerExtension(function () {
            return $this->getContainer()->query('ActivityService');
        });
    }

    /**
     *
     */
    protected function registerPersonalSettings(): void {
        \OCP\App::registerPersonal('passwords', 'personal/index');
    }

    /**
     *
     */
    protected function registerDiClasses(): void {
        $container = $this->getContainer();

        /**
         * Controllers
         */
        $this->registerController();

        /**
         * Mappers
         */
        $this->registerMapper();

        /**
         * Services
         */
        $this->registerServices();

        /**
         * Helper
         */
        $container->registerService('PasswordApiObjectHelper', function (IAppContainer $c) {
            return new PasswordApiObjectHelper(
                $c->query('RevisionService')
            );
        });
        $this->registerImageHelper();
        $this->registerPageShotHelper();
        $this->registerFaviconHelper();
        $this->registerWordsHelper();
        $this->registerSecurityCheckHelper();

        /**
         * Admin Settings
         */
        $container->registerService('AdminSettings', function (IAppContainer $c) {
            return new AdminSettings(
                $c->query('LocalisationService'),
                $c->query('ConfigurationService'),
                $this->getFileCacheService()
            );
        });

        /**
         * Cron Jobs
         */
        $container->registerService('CheckPasswordsJob', function (IAppContainer $c) {
            return new CheckPasswordsJob(
                $c->query('HelperService'),
                $c->query('RevisionMapper')
            );
        });

        /**
         * Alias
         */
        $container->registerAlias('AppData', IAppData::class);
        $container->registerAlias('ValidationService', ValidationService::class);
        $container->registerAlias('EncryptionService', EncryptionService::class);
    }

    /**
     * @return FileCacheService
     */
    protected function getFileCacheService(): FileCacheService {
        return clone $this->getContainer()->query('FileCacheService');
    }

    /**
     * @return string
     */
    protected function getUserId(): string {
        $user = $this->getContainer()->getServer()->getUserSession()->getUser();

        return $user === null ? '':$user->getUID();
    }

    /**
     *
     */
    protected function registerController(): void {
        $container = $this->getContainer();

        $container->registerService('PageController', function (IAppContainer $c) {
            return new PageController(
                $c->query('AppName'),
                $c->query('Request')
            );
        });

        $container->registerService('AccessController', function (IAppContainer $c) {
            return new AccessController(
                $c->query('AppName'),
                $c->query('Request'),
                $c->getServer()->getURLGenerator()
            );
        });

        $container->registerService('PasswordApiController', function (IAppContainer $c) {
            return new PasswordApiController(
                $c->query('AppName'),
                $c->query('Request'),
                $c->query('PasswordService'),
                $c->query('RevisionService'),
                $c->query('PasswordApiObjectHelper')
            );
        });

        $container->registerService('ServiceApiController', function (IAppContainer $c) {
            return new ServiceApiController(
                $c->query('AppName'),
                $c->query('Request'),
                $c->query('FaviconService'),
                $c->query('PageShotService'),
                $c->query('WordsService')
            );
        });

        $container->registerService('AdminSettingsController', function (IAppContainer $c) {
            return new AdminSettingsController(
                $c->query('AppName'),
                $c->query('Request'),
                $c->getServer()->getConfig(),
                $this->getFileCacheService()
            );
        });
    }

    /**
     *
     */
    protected function registerMapper(): void {
        $container = $this->getContainer();

        $container->registerService('PasswordMapper', function (IAppContainer $c) {
            return new PasswordMapper(
                $c->getServer()->getDatabaseConnection(),
                $this->getUserId()
            );
        });

        $container->registerService('RevisionMapper', function (IAppContainer $c) {
            return new RevisionMapper(
                $c->getServer()->getDatabaseConnection(),
                $this->getUserId()
            );
        });
    }

    /**
     *
     */
    protected function registerServices(): void {
        $container = $this->getContainer();

        $container->registerService('PasswordService', function (IAppContainer $c) {
            return new PasswordService(
                $c->getServer()->getUserSession()->getUser(),
                $c->query('PasswordMapper')
            );
        });

        $container->registerService('RevisionService', function (IAppContainer $c) {
            return new RevisionService(
                $c->getServer()->getUserSession()->getUser(),
                $c->query('ValidationService'),
                $c->query('EncryptionService'),
                $c->query('RevisionMapper'),
                $c->query('HelperService')->getSecurityHelper()
            );
        });

        $container->registerService('FileCacheService', function (IAppContainer $c) {
            return new FileCacheService(
                $c->query('AppData')
            );
        });

        $container->registerService('FaviconService', function (IAppContainer $c) {
            return new FaviconService(
                $c->query('HelperService'),
                $this->getFileCacheService(),
                $c->query('ValidationService'),
                $c->getServer()->getLogger()
            );
        });

        $container->registerService('PageShotService', function (IAppContainer $c) {
            return new PageShotService(
                $c->query('HelperService'),
                $this->getFileCacheService(),
                $c->query('ValidationService'),
                $c->getServer()->getLogger()
            );
        });

        $container->registerService('WordsService', function (IAppContainer $c) {
            return new WordsService(
                $c->query('HelperService'),
                $c->getServer()->getLogger()
            );
        });

        $container->registerService('HelperService', function (IAppContainer $c) {
            return new HelperService(
                $c->query('ConfigurationService'),
                $c->query('FileCacheService'),
                $c
            );
        });

        $container->registerService('ConfigurationService', function (IAppContainer $c) {
            return new ConfigurationService(
                $this->getUserId(),
                $c->getServer()->getConfig()
            );
        });

        $container->registerService('LocalisationService', function (IAppContainer $c) {
            return $c->query('L10NFactory')->get(self::APP_NAME);
        });
    }

    /**
     *
     */
    protected function registerImageHelper(): void {
        $container = $this->getContainer();

        $container->registerService('ImagickHelper', function (IAppContainer $c) {
            return new ImagickHelper(
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('GdHelper', function (IAppContainer $c) {
            return new GdHelper(
                $c->query('ConfigurationService')
            );
        });
    }

    /**
     *
     */
    protected function registerPageShotHelper(): void {
        $container = $this->getContainer();

        $container->registerService('WkhtmlImageHelper', function (IAppContainer $c) {
            return new WkhtmlImageHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('ScreenShotApiHelper', function (IAppContainer $c) {
            return new ScreenShotApiHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('ScreenShotLayerHelper', function (IAppContainer $c) {
            return new ScreenShotLayerHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('ScreenShotMachineHelper', function (IAppContainer $c) {
            return new ScreenShotMachineHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('DefaultPageShotHelper', function (IAppContainer $c) {
            return new DefaultPageShotHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });
    }

    /**
     *
     */
    protected function registerFaviconHelper(): void {
        $container = $this->getContainer();

        $container->registerService('BetterIdeaHelper', function () {
            return new BetterIdeaHelper(
                $this->getFileCacheService()
            );
        });

        $container->registerService('DuckDuckGoHelper', function () {
            return new DuckDuckGoHelper(
                $this->getFileCacheService()
            );
        });

        $container->registerService('GoogleFaviconHelper', function () {
            return new GoogleFaviconHelper(
                $this->getFileCacheService()
            );
        });

        $container->registerService('LocalFaviconHelper', function (IAppContainer $c) {
            return new LocalFaviconHelper(
                $this->getFileCacheService(),
                $c->query('HelperService')->getImageHelper()
            );
        });

        $container->registerService('DefaultFaviconHelper', function () {
            return new DefaultFaviconHelper(
                $this->getFileCacheService()
            );
        });
    }

    /**
     *
     */
    protected function registerWordsHelper(): void {
        $container = $this->getContainer();

        $container->registerService('LocalWordsHelper', function (IAppContainer $c) {
            return new LocalWordsHelper(
                $c->query('L10NFactory')->get('core')->getLanguageCode()
            );
        });

        $container->registerService('SnakesWordsHelper', function () {
            return new SnakesWordsHelper();
        });
    }

    /**
     *
     */
    protected function registerSecurityCheckHelper(): void {
        $container = $this->getContainer();

        $container->registerService('HaveIBeenPwnedHelper', function (IAppContainer $c) {
            return new HaveIBeenPwnedHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService')
            );
        });

        $container->registerService('BigLocalDbSecurityCheckHelper', function (IAppContainer $c) {
            return new BigLocalDbSecurityCheckHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService'),
                $c->getServer()->getLogger()
            );
        });

        $container->registerService('SmallLocalDbSecurityCheckHelper', function (IAppContainer $c) {
            return new SmallLocalDbSecurityCheckHelper(
                $this->getFileCacheService(),
                $c->query('ConfigurationService'),
                $c->getServer()->getLogger()
            );
        });
    }
}