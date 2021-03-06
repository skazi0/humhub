<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2015 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\admin\libs;

use ZipArchive;
use Yii;
use yii\web\HttpException;
use yii\base\Exception;
use humhub\models\Setting;

/**
 * Handles remote module installation, updates and module listing
 *
 * @author luke
 */
class OnlineModuleManager
{

    private $_modules = null;

    /**
     * Installs latest compatible module version
     *
     * @param type $moduleId
     */
    public function install($moduleId)
    {
        $modulePath = Yii::getAlias(Yii::$app->params['moduleMarketplacePath']);

        if (!is_writable($modulePath)) {
            throw new HttpException(500, Yii::t('AdminModule.libs_OnlineModuleManager', 'Module directory %modulePath% is not writeable!', array('%modulePath%' => $modulePath)));
        }

        $moduleInfo = $this->getModuleInfo($moduleId);

        if (!isset($moduleInfo['latestCompatibleVersion'])) {
            throw new Exception(Yii::t('AdminModule.libs_OnlineModuleManager', "No compatible module version found!"));
        }

        
        $moduleDir = $modulePath . DIRECTORY_SEPARATOR . $moduleId;
        if (is_dir($moduleDir)) {
            $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($moduleDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                $todo($fileinfo->getRealPath());
            }

            rmdir($moduleDir);
            #throw new HttpException(500, Yii::t('AdminModule.libs_OnlineModuleManager', 'Module directory for module %moduleId% already exists!', array('%moduleId%' => $moduleId)));
        }

        // Check Module Folder exists
        $moduleDownloadFolder = Yii::getAlias("@runtime/module_downloads");
        if (!is_dir($moduleDownloadFolder)) {
            if (!@mkdir($moduleDownloadFolder)) {
                throw new Exception("Could not create module download folder!");
            }
        }

        $version = $moduleInfo['latestCompatibleVersion'];

        // Download
        $downloadUrl = $version['downloadUrl'];
        $downloadTargetFileName = $moduleDownloadFolder . DIRECTORY_SEPARATOR . basename($downloadUrl);
        try {
            $http = new \Zend\Http\Client($downloadUrl, array(
                'adapter' => '\Zend\Http\Client\Adapter\Curl',
                'curloptions' => $this->getCurlOptions(),
                'timeout' => 30
            ));

            $response = $http->send();

            file_put_contents($downloadTargetFileName, $response->getBody());
        } catch (Exception $ex) {
            throw new HttpException('500', Yii::t('AdminModule.libs_OnlineModuleManager', 'Module download failed! (%error%)', array('%error%' => $ex->getMessage())));
        }

        // Extract Package
        if (file_exists($downloadTargetFileName)) {
            $zip = new ZipArchive;
            $res = $zip->open($downloadTargetFileName);
            if ($res === TRUE) {
                $zip->extractTo($modulePath);
                $zip->close();
            } else {
                throw new HttpException('500', Yii::t('AdminModule.libs_OnlineModuleManager', 'Could not extract module!'));
            }
        } else {
            throw new HttpException('500', Yii::t('AdminModule.libs_OnlineModuleManager', 'Download of module failed!'));
        }

        Yii::$app->moduleManager->flushCache();
        Yii::$app->moduleManager->register($modulePath . DIRECTORY_SEPARATOR . $moduleId);
    }

    /**
     * Updates a given module
     *
     * @param HWebModule $module
     */
    public function update($moduleId)
    {
        // Temporary disable module if enabled
        if (Yii::$app->hasModule($moduleId)) {
            Yii::$app->setModule($moduleId, null);
        }

        Yii::$app->moduleManager->removeModule($moduleId, false);

        $this->install($moduleId);

        $updatedModule = Yii::$app->moduleManager->getModule($moduleId);
        $updatedModule->migrate();
    }

    /**
     * Returns an array of all available online modules
     *
     * Key is moduleId
     *  - name
     *  - description
     *  - latestVersion
     *  - latestCompatibleVersion
     *
     * @return Array of modulles
     */
    public function getModules()
    {

        if ($this->_modules !== null) {
            return $this->_modules;
        }

        $url = Yii::$app->getModule('admin')->marketplaceApiUrl . "list?version=" . urlencode(Yii::$app->version) . "&installId=" . Setting::Get('installationId', 'admin');

        try {

            $this->_modules = Yii::$app->cache->get('onlineModuleManager_modules');
            if ($this->_modules === null || !is_array($this->_modules)) {

                $http = new \Zend\Http\Client($url, array(
                    'adapter' => '\Zend\Http\Client\Adapter\Curl',
                    'curloptions' => $this->getCurlOptions(),
                    'timeout' => 30
                ));

                $response = $http->send();
                $json = $response->getBody();

                $this->_modules = \yii\helpers\Json::decode($json);
                Yii::$app->cache->set('onlineModuleManager_modules', $this->_modules, Setting::Get('expireTime', 'cache'));
            }
        } catch (Exception $ex) {
            throw new HttpException('500', Yii::t('AdminModule.libs_OnlineModuleManager', 'Could not fetch module list online! (%error%)', array('%error%' => $ex->getMessage())));
        }
        return $this->_modules;
    }

    public function getModuleUpdates()
    {
        $updates = array();

        foreach ($this->getModules() as $moduleId => $moduleInfo) {

            if (isset($moduleInfo['latestCompatibleVersion']) && Yii::$app->moduleManager->hasModule($moduleId)) {

                $module = Yii::$app->moduleManager->getModule($moduleId);

                if ($module !== null) {
                    if (version_compare($moduleInfo['latestCompatibleVersion'], $module->getVersion(), 'gt')) {
                        $updates[$moduleId] = $moduleInfo;
                    }
                } else {
                    Yii::error("Could not load module: " . $moduleId . " to get updates");
                }
            }
        }

        return $updates;
    }

    /**
     * Returns an array of informations about a module
     */
    public function getModuleInfo($moduleId)
    {

        $moduleInfo = [];

        // get all module informations
        $url = Yii::$app->getModule('admin')->marketplaceApiUrl . "info?id=" . urlencode($moduleId) . "&version=" . Yii::$app->version . "&installId=" . Setting::Get('installationId', 'admin');
        try {
            $http = new \Zend\Http\Client($url, array(
                'adapter' => '\Zend\Http\Client\Adapter\Curl',
                'curloptions' => $this->getCurlOptions(),
                'timeout' => 30
            ));

            $response = $http->send();

            if ($response->getStatusCode() == '404') {
                throw new \yii\base\InvalidParamException("Could not find module online!");
            }

            $json = $response->getBody();

            $moduleInfo = \yii\helpers\Json::decode($json);
        } catch (Exception $ex) {
            throw new HttpException('500', Yii::t('AdminModule.libs_OnlineModuleManager', 'Could not get module info online! (%error%)', array('%error%' => $ex->getMessage())));
        }

        return $moduleInfo;
    }

    /**
     * Returns latest HumHub Version
     */
    public function getLatestHumHubVersion()
    {
        $url = Yii::$app->getModule('admin')->marketplaceApiUrl . "getLatestVersion?version=" . Yii::$app->version . "&installId=" . Setting::Get('installationId', 'admin');
        try {
            $http = new \Zend\Http\Client($url, array(
                'adapter' => '\Zend\Http\Client\Adapter\Curl',
                'curloptions' => $this->getCurlOptions(),
                'timeout' => 30
            ));

            $response = $http->send();
            $json = \yii\helpers\Json::decode($response->getBody());

            if (isset($json['latestVersion'])) {
                return $json['latestVersion'];
            }
        } catch (Exception $ex) {
            Yii::error('Could not get latest HumHub Version!' . $ex->getMessage());
        }

        return "";
    }

    private function getCurlOptions()
    {
        $options = array(
            CURLOPT_SSL_VERIFYPEER => (Yii::$app->getModule('admin')->marketplaceApiValidateSsl) ? true : false,
            CURLOPT_SSL_VERIFYHOST => (Yii::$app->getModule('admin')->marketplaceApiValidateSsl) ? 2 : 0,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CAINFO => Yii::getAlias('@humhub/config/cacert.pem')
        );


        if (Setting::Get('enabled', 'proxy')) {
            $options[CURLOPT_PROXY] = Setting::Get('server', 'proxy');
            $options[CURLOPT_PROXYPORT] = Setting::Get('port', 'proxy');
            if (defined('CURLOPT_PROXYUSERNAME')) {
                $options[CURLOPT_PROXYUSERNAME] = Setting::Get('user', 'proxy');
            }
            if (defined('CURLOPT_PROXYPASSWORD')) {
                $options[CURLOPT_PROXYPASSWORD] = Setting::Get('pass', 'proxy');
            }
            if (defined('CURLOPT_NOPROXY')) {
                $options[CURLOPT_NOPROXY] = Setting::Get('noproxy', 'proxy');
            }
        }

        return $options;
    }

}
