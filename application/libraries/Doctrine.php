<?php
if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;

define('DEBUGGING', false);

/**
 * @package   Jagger
 * @author    Middleware Team HEAnet
 * @author    Janusz Ulanowski <janusz.ulanowski@heanet.ie>
 * @copyright 2016, HEAnet Limited (http://www.heanet.ie)
 * @license   MIT http://www.opensource.org/licenses/mit-license.php
 *
 */
class Doctrine
{

    public $em = null;

    public function __construct() {
        // load database configuration and custom config from CodeIgniter
        require APPPATH . 'config/database.php';

        $entitiesClassLoader = new \Doctrine\Common\ClassLoader('models', rtrim(APPPATH, '/'));
        $entitiesClassLoader->register();

        $proxiesClassLoader = new \Doctrine\Common\ClassLoader('Proxies', APPPATH . 'models');
        $proxiesClassLoader->register();
        $cache = new \Doctrine\Common\Cache\ArrayCache;

        // Choose caching method based on application mode
        if (ENVIRONMENT === 'production' && extension_loaded('apc') && ini_get('apc.enabled')) {
            $cache = new \Doctrine\Common\Cache\ApcCache;
        }
        $config = new Configuration;

        // Metadata driver
        $driverImpl = $config->newDefaultAnnotationDriver(APPPATH . 'models');
        $config->setMetadataDriverImpl($driverImpl);


        // Caching
        $config->setMetadataCacheImpl($cache);
        $config->setQueryCacheImpl($cache);
        $config->setResultCacheImpl($cache);

        // Proxies
        $config->setProxyDir(APPPATH . 'models/Proxies');
        $config->setProxyNamespace('Proxies');
        $config->setAutoGenerateProxyClasses(false);
        if (ENVIRONMENT === 'development') {
            $config->setAutoGenerateProxyClasses(true);
        }

        // SQL query logger
        if (DEBUGGING) {
            $logger = new \Doctrine\DBAL\Logging\EchoSQLLogger;
            $config->setSQLLogger($logger);
        }

        /**
         * keep compatibility with old configs
         */
        if (!isset($db['default']) || !is_array($db['default'])) {
            log_message('error', __METHOD__ . ' ::: database.php conf file is misconfigured. missing array : $db[\'default\']');
            throw new \Exception('system misconfigured');
        }
        $connectionOptions = self::getDBDriver($db['default']);
        
        // Create EntityManager
        $this->em = EntityManager::create($connectionOptions, $config);
    }

    private static function getDBDriver($dbconfig) {
        if (!isset($dbconfig['dbdriver'])) {
            log_message('error', __METHOD__ . ' ::: database.php conf file is misconfigured. missing : $db[\'default\'][\'dbdriver\']');
            throw new \Exception('system misconfigured');
        }
        $dbriver = $dbconfig['dbdriver'];
        if ($dbriver === 'pdo') {
            if (!isset($dbconfig['dsn'])) {
                log_message('error', __METHOD__ . ' ::: database.php conf file is misconfigured: $db[\'default\'][\'dbdriver\'] is set to "pdo" but $db[\'default\'][\'dsn\'] is missing');
                throw new \Exception('system misconfigured');
            }
            if (preg_match('/([^:]+):/', $dbconfig['dsn'], $match) && count($match) === 2) {
                $dbriver = 'pdo_' . $match['1'];
            }


        } elseif ($dbriver === 'mysql') {
            $dbriver = 'pdo_mysql';
        }

        // Database connection information
        $connectionOptions = array(
            'driver'   => $dbriver,
            'user'     => $dbconfig['username'],
            'password' => $dbconfig['password'],
            'host'     => $dbconfig['hostname'],
            'dbname'   => $dbconfig['database']
        );
        if (isset($dbconfig['port'])) {
            $connectionOptions['port'] = $dbconfig['port'];

        }

        return $connectionOptions;
    }

}
