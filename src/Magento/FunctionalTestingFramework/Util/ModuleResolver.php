<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\Util;

use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\Exceptions\TestFrameworkException;
use Magento\FunctionalTestingFramework\Util\Logger\LoggingUtil;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ModuleResolver, resolve module path based on enabled modules of target Magento instance.
 *
 * @api
 */
class ModuleResolver
{
    /**
     * Environment field name for module whitelist.
     */
    const MODULE_WHITELIST = 'MODULE_WHITELIST';

    /**
     * Environment field name for custom module paths.
     */
    const CUSTOM_MODULE_PATHS = 'CUSTOM_MODULE_PATHS';

    /**
     * List of path types present in Magento Component Registrar
     */
    const PATHS = ['module', 'library', 'theme', 'language'];

    /**
     * Magento Registrar Class
     */
    const REGISTRAR_CLASS = "\Magento\Framework\Component\ComponentRegistrar";

    /**
     * const for vendor
     */
    const VENDOR = 'vendor';

    /**
     * const for app/code
     */
    const APP_CODE = 'app' . DIRECTORY_SEPARATOR . "code";

    /**
     * const for dev/tests/acceptance/tests/functional
     */
    const DEV_TESTS = 'dev'
    . DIRECTORY_SEPARATOR
    . 'tests'
    . DIRECTORY_SEPARATOR
    . 'acceptance'
    . DIRECTORY_SEPARATOR
    . 'tests'
    . DIRECTORY_SEPARATOR
    . 'functional';

    /**
     * Deprecated dev tests code path
     */
    const DEPRECATED_DEV_TESTS = DIRECTORY_SEPARATOR
        . self:: DEV_TESTS
        . DIRECTORY_SEPARATOR
        . "Magento"
        . DIRECTORY_SEPARATOR
        . "FunctionalTest";

    /**
     * Enabled modules.
     *
     * @var array|null
     */
    protected $enabledModules = null;

    /**
     * Paths for enabled modules.
     *
     * @var array|null
     */
    protected $enabledModulePaths = null;

    /**
     * Paths for non flattened enabled modules.
     *
     * @var array|null
     */
    protected $nonFlattenedEnabledModulePaths = null;

    /**
     * Configuration instance.
     *
     * @var \Magento\FunctionalTestingFramework\Config\DataInterface
     */
    protected $configuration;

    /**
     * Admin url for integration token.
     *
     * @var string
     */
    protected $adminTokenUrl = "rest/V1/integration/admin/token";

    /**
     * Url for with module list.
     *
     * @var string
     */
    protected $moduleUrl = "rest/V1/modules";

    /**
     * Url for magento version information.
     *
     * @var string
     */
    protected $versionUrl = "magento_version";

    /**
     * List of known directory that does not map to a Magento module.
     *
     * @var array
     */
    protected $knownDirectories = ['SampleData' => 1];

    /**
     * ModuleResolver instance.
     *
     * @var ModuleResolver
     */
    private static $instance = null;

    /**
     * SequenceSorter instance.
     *
     * @var ModuleResolver\SequenceSorterInterface
     */
    protected $sequenceSorter;

    /**
     * List of module names that will be ignored.
     *
     * @var array
     */
    protected $moduleBlacklist = [
        'SampleTests', 'SampleTemplates'
    ];

    /**
     * Registered module list in magento instance being tested
     *
     * @var array
     */
    private $registeredModuleList = [];

    /**
     * Composer json based test module paths
     *
     * @var array
     */
    private $composerJsonModulePaths = null;

    /**
     * Composer installed test module paths
     *
     * @var array
     */
    private $composerInstalledModulePaths = null;

    /**
     * Get ModuleResolver instance.
     *
     * @return ModuleResolver
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new ModuleResolver();
        }
        return self::$instance;
    }

    /**
     * ModuleResolver constructor.
     */
    private function __construct()
    {
        $objectManager = \Magento\FunctionalTestingFramework\ObjectManagerFactory::getObjectManager();
        $this->sequenceSorter = $objectManager->get(
            \Magento\FunctionalTestingFramework\Util\ModuleResolver\SequenceSorterInterface::class
        );
    }

    /**
     * Return an array of enabled modules of target Magento instance.
     *
     * @return array
     * @throws TestFrameworkException
     */
    public function getEnabledModules()
    {
        if (isset($this->enabledModules)) {
            return $this->enabledModules;
        }

        if (MftfApplicationConfig::getConfig()->getPhase() == MftfApplicationConfig::GENERATION_PHASE) {
            $this->printMagentoVersionInfo();
        }

        $token = $this->getAdminToken();

        $url = ConfigSanitizerUtil::sanitizeUrl(getenv('MAGENTO_BASE_URL')) . $this->moduleUrl;

        $headers = [
            'Authorization: Bearer ' . $token,
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);

        if (!$response) {
            $message = "Could not retrieve Modules from Magento Instance.";
            $context = [
                "Admin Module List Url" => $url,
                "MAGENTO_ADMIN_USERNAME" => getenv("MAGENTO_ADMIN_USERNAME"),
                "MAGENTO_ADMIN_PASSWORD" => getenv("MAGENTO_ADMIN_PASSWORD"),
            ];
            throw new TestFrameworkException($message, $context);
        }

        $this->enabledModules = json_decode($response);

        return $this->enabledModules;
    }

    /**
     * Return the modules path based on which modules are enabled in the target Magento instance.
     *
     * @param boolean $flat
     * @return array
     */
    public function getModulesPath($flat = true)
    {
        if (isset($this->enabledModulePaths) && $flat) {
            return $this->enabledModulePaths;
        }

        if (isset($this->nonFlattenedEnabledModulePaths) && !$flat) {
            return $this->nonFlattenedEnabledModulePaths;
        }

        $allModulePaths = $this->aggregateTestModulePaths();

        $composerBsedModulePaths = $this->aggregateTestModulePathsFromComposerJson();
        $composerBsedModulePaths = array_merge(
            $composerBsedModulePaths,
            $this->aggregateTestModulePathsFromComposerInstaller()
        );

        $allModulePaths = $this->mergeModulePaths($allModulePaths, $composerBsedModulePaths);

        if (MftfApplicationConfig::getConfig()->forceGenerateEnabled()) {
            $allModulePaths = $this->flipAndFlattenArray($allModulePaths);
            $this->enabledModulePaths = $this->applyCustomModuleMethods($allModulePaths);
            return $this->enabledModulePaths;
        }

        $enabledModules = array_merge($this->getEnabledModules(), $this->getModuleWhitelist());
        //$enabledDirectoryPaths = $this->getEnabledDirectoryPaths($enabledModules, $allModulePaths);
        $enabledDirectoryPaths = $this->flipAndFlattenArray($allModulePaths, $enabledModules);

        $this->enabledModulePaths = $this->applyCustomModuleMethods($enabledDirectoryPaths);
        return $this->enabledModulePaths;
    }

    /**
     * @param array $targetArray
     * @param array $filterArray
     * @return array
     */
    private function flipAndFlattenArray($objectArray, $filterArray = null)
    {
        $flippedArray = [];
        foreach ($objectArray as $path => $modules) {
            if (count($modules) == 1) {
                if (!is_array($filterArray)
                    || (is_array($filterArray) && in_array($modules[0], $filterArray))) {
                    $flippedArray[$modules[0]] = $path;
                }
            } else {
                if (!is_array($filterArray)) {
                    $flippedArray[$this->getPossibleVendorModuleName($path)] = $path;
                } else {
                    $skip = false;
                    foreach ($modules as $module) {
                        if(!in_array($module, $filterArray)) {
                            $skip = true;
                            break;
                        }
                    }
                    if (!$skip) {
                        $flippedArray[$this->getPossibleVendorModuleName($path)] = $path;
                    }
                }
            }
        }
        return $flippedArray;
    }


    /**
     * Sort files according module sequence.
     *
     * @param array $files
     * @return array
     */
    public function sortFilesByModuleSequence(array $files)
    {
        return $this->sequenceSorter->sort($files);
    }

    /**
     * Return an array of module whitelist that not exist in target Magento instance.
     *
     * @return array
     */
    protected function getModuleWhitelist()
    {
        $moduleWhitelist = getenv(self::MODULE_WHITELIST);

        if (empty($moduleWhitelist)) {
            return [];
        }
        return array_map('trim', explode(',', $moduleWhitelist));
    }

    /**
     * Retrieves all module directories which might contain pertinent test code.
     *
     * @return array
     * @throws TestFrameworkException
     */
    private function aggregateTestModulePaths()
    {
        $allModulePaths = [];

        // Define the Module paths from magento bp
        $magentoBaseCodePath = MAGENTO_BP;

        // Define the Module paths from default TESTS_MODULE_PATH
        $modulePath = defined('TESTS_MODULE_PATH') ? TESTS_MODULE_PATH : TESTS_BP;
        $modulePath = rtrim($modulePath, DIRECTORY_SEPARATOR);

        $vendorCodePath = DIRECTORY_SEPARATOR . self::VENDOR;
        $appCodePath = DIRECTORY_SEPARATOR . self::APP_CODE;

        $codePathsToPattern = [
            $modulePath => '',
            $magentoBaseCodePath . $vendorCodePath => 'Test' . DIRECTORY_SEPARATOR . 'Mftf',
            $magentoBaseCodePath . $appCodePath => 'Test' . DIRECTORY_SEPARATOR . 'Mftf',
            $magentoBaseCodePath . self::DEPRECATED_DEV_TESTS => ''
        ];

        foreach ($codePathsToPattern as $codePath => $pattern) {
            $allModulePaths = array_merge_recursive($allModulePaths, $this->globRelevantPaths($codePath, $pattern));
        }

        return $allModulePaths;
    }

    /**
     * Function which takes a code path and a pattern and determines if there are any matching subdir paths. Matches
     * are returned as an associative array keyed by basename (the last dir excluding pattern) to an array containing
     * the matching path.
     *
     * @param string $testPath
     * @param string $pattern
     * @return array
     */
    private function globRelevantPaths($testPath, $pattern)
    {
        $modulePaths = [];
        $relevantPaths = [];

        if (file_exists($testPath)) {
            $relevantPaths = $this->globRelevantWrapper($testPath, $pattern);
        }

        $allComponents = $this->getRegisteredModuleList();

        foreach ($relevantPaths as $codePath) {
            // Reduce magento/app/code/Magento/AdminGws/<pattern> to magento/app/code/Magento/AdminGws to read symlink
            // Symlinks must be resolved otherwise they will not match Magento's filepath to the module
            $potentialSymlink = str_replace(DIRECTORY_SEPARATOR . $pattern, "", $codePath);
            if (is_link($potentialSymlink)) {
                $codePath = realpath($potentialSymlink) . DIRECTORY_SEPARATOR . $pattern;
            }

            $mainModName = array_search($codePath, $allComponents);
            if (!$mainModName) {
                $mainModName = $this->getPossibleVendorName($codePath)
                    . '_'
                    . basename(str_replace($pattern, '', $codePath));
            }
            $modulePaths[$mainModName][] = $codePath;

            if (MftfApplicationConfig::getConfig()->verboseEnabled()) {
                LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->debug(
                    "including module",
                    ['module' => $mainModName, 'path' => $codePath]
                );
            }
        }

        // Suppress print during unit testing
        if (MftfApplicationConfig::getConfig()->getPhase() !== MftfApplicationConfig::UNIT_TEST_PHASE
            && strpos($testPath, self::DEPRECATED_DEV_TESTS) !== false
            && !empty($module)
        ) {
            $deprecatedPath = self::DEPRECATED_DEV_TESTS;
            $suggestedPath = self::DEV_TESTS . DIRECTORY_SEPARATOR . 'Vendor';
            LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->warning(
                "DEPRECATION: $deprecatedPath is deprecated! Please move mftf test modules to $suggestedPath"
            );
            print ("\nDEPRECATION: $deprecatedPath is deprecated! Please move mftf tests to $suggestedPath\n\n");
        }
        return $modulePaths;
    }

    /**
     * Glob wrapper for globRelevantPaths function
     *
     * @param string $testPath
     * @param string $pattern
     * @return array
     */
    private static function globRelevantWrapper($testPath, $pattern)
    {
        if ($pattern == "") {
            return glob($testPath . '*' . DIRECTORY_SEPARATOR . '*' . $pattern);
        }
        $subDirectory = "*" . DIRECTORY_SEPARATOR;
        $directories = glob($testPath . $subDirectory . $pattern, GLOB_ONLYDIR);
        foreach (glob($testPath . $subDirectory, GLOB_ONLYDIR) as $dir) {
            $directories = array_merge_recursive($directories, self::globRelevantWrapper($dir, $pattern));
        }
        return $directories;
    }

    /**
     * Retrieves all code paths by searching composer json where might contain pertinent test modules
     *
     * @return array
     */
    private function aggregateTestModulePathsFromComposerJson()
    {
        // Define the Module paths from magento bp
        $magentoBaseCodePath = MAGENTO_BP;

        // Define the Module paths from default TESTS_MODULE_PATH
        $modulePath = defined('TESTS_MODULE_PATH') ? TESTS_MODULE_PATH : TESTS_BP;
        $modulePath = rtrim($modulePath, DIRECTORY_SEPARATOR);

        $searchCodePaths = [
            $magentoBaseCodePath . DIRECTORY_SEPARATOR . self::DEV_TESTS,
        ];

        // Add TESTS_MODULE_PATH if it's not included
        if (array_search($modulePath, $searchCodePaths) === false) {
            $searchCodePaths[] = $modulePath;
        }

        return $this->getComposerJsonTestModulePaths($searchCodePaths);
    }

    /**
     * Retrieve composer json based test module paths from give $codePath
     *
     * @param array $codePaths
     * @return array
     */
    private function getComposerJsonTestModulePaths($codePaths)
    {
        if (null !== $this->composerJsonModulePaths) {
            return $this->composerJsonModulePaths;
        }
        try {
            $this->composerJsonModulePaths = [];
            $resolver = new ComposerModuleResolver();
            $this->composerJsonModulePaths = $resolver->getTestModulesFromPaths($codePaths);
        } catch (TestFrameworkException $e) {
        }

        return $this->composerJsonModulePaths;
    }
    
    /**
     * Retrieves all module directories which might contain pertinent test code.
     *
     * @return array
     */
    private function aggregateTestModulePathsFromComposerInstaller()
    {
        // Define the Module paths from magento bp
        $magentoBaseCodePath = MAGENTO_BP;
        $composerFile = $magentoBaseCodePath . DIRECTORY_SEPARATOR . 'composer.json';

        return $this->getComposerInstalledTestModulePaths($composerFile);
    }

    /**
     * Retrieve composer json based test module paths from give $codePath
     *
     * @params string $composerFile
     * @return array
     */
    private function getComposerInstalledTestModulePaths($composerFile)
    {
        if (null !== $this->composerInstalledModulePaths) {
            return $this->composerInstalledModulePaths;
        }
        try {
            $this->composerInstalledModulePaths = [];
            $resolver = new ComposerModuleResolver();
            $this->composerInstalledModulePaths = $resolver->getComposerInstalledTestModules($composerFile);
        } catch (TestFrameworkException $e) {
        }

        return $this->composerInstalledModulePaths;
    }

    /**
     * Merge an associated array in 1st argument with another flattened associated array in 2nd argument
     * and process duplicates
     *
     * @param array $nameKeyedArray
     * @param array $pathKeyedArray
     * @return array
     */
    private function mergeModulePaths($nameKeyedArray, $pathKeyedArray)
    {
        $flippedArray = [];
        foreach ($nameKeyedArray as $name => $path) {
            $flippedArray[$path[0]] = [$name];
        }
        foreach ($pathKeyedArray as $path => $modules) {
            // Do nothing when array_key_exists
            if (!array_key_exists($path, $flippedArray)) {
                $flippedArray[$path] = $modules;
            }
        }
        return $flippedArray;
    }

    /**
     * Takes a multidimensional array of module paths and flattens to return a one dimensional array of test paths
     *
     * @param array $modulePaths
     * @return array
     */
    private function flattenAllModulePaths($modulePaths)
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($modulePaths));
        $resultArray = [];

        foreach ($it as $value) {
            $resultArray[] = $value;
        }

        return $resultArray;
    }

    /**
     * Runs through enabled modules and maps them known module paths by name.
     * @param array $enabledModules
     * @param array $allModulePaths
     * @return array
     */
    private function getEnabledDirectoryPaths($enabledModules, $allModulePaths)
    {
        $enabledDirectoryPaths = [];
        foreach ($enabledModules as $magentoModuleName) {
            if (!isset($this->knownDirectories[$magentoModuleName]) && !isset($allModulePaths[$magentoModuleName])) {
                continue;
            } elseif (isset($this->knownDirectories[$magentoModuleName])
                && !isset($allModulePaths[$magentoModuleName])) {
                LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->warn(
                    "Known directory could not match to an existing path.",
                    ['knownDirectory' => $magentoModuleName]
                );
            } else {
                $enabledDirectoryPaths[$magentoModuleName] = $allModulePaths[$magentoModuleName];
            }
        }
        return $enabledDirectoryPaths;
    }

    /**
     * Executes a REST call to the supplied Magento Base Url for version information to display during generation
     *
     * @return void
     */
    private function printMagentoVersionInfo()
    {
        if (MftfApplicationConfig::getConfig()->forceGenerateEnabled()) {
            return;
        }
        $url = ConfigSanitizerUtil::sanitizeUrl(getenv('MAGENTO_BASE_URL')) . $this->versionUrl;
        LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->info(
            "Fetching version information.",
            ['url' => $url]
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

        if (!$response) {
            $response = "No version information available.";
        }

        LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->info(
            'version information',
            ['version' => $response]
        );
    }

    /**
     * Get the API token for admin.
     *
     * @return string|boolean
     */
    protected function getAdminToken()
    {
        $login = $_ENV['MAGENTO_ADMIN_USERNAME'] ?? null;
        $password = $_ENV['MAGENTO_ADMIN_PASSWORD'] ?? null;
        if (!$login || !$password || !$this->getBackendUrl()) {
            $message = "Cannot retrieve API token without credentials and base url, please fill out .env.";
            $context = [
                "MAGENTO_BASE_URL" => getenv("MAGENTO_BASE_URL"),
                "MAGENTO_BACKEND_BASE_URL" => getenv("MAGENTO_BACKEND_BASE_URL"),
                "MAGENTO_ADMIN_USERNAME" => getenv("MAGENTO_ADMIN_USERNAME"),
                "MAGENTO_ADMIN_PASSWORD" => getenv("MAGENTO_ADMIN_PASSWORD"),
            ];
            throw new TestFrameworkException($message, $context);
        }

        $url = ConfigSanitizerUtil::sanitizeUrl($this->getBackendUrl()) . $this->adminTokenUrl;
        $data = [
            'username' => $login,
            'password' => $password
        ];
        $headers = [
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $responseCode = curl_getinfo($ch)['http_code'];

        if ($responseCode !== 200) {
            if ($responseCode == 0) {
                $details = "Could not find Magento Backend Instance at MAGENTO_BACKEND_BASE_URL or MAGENTO_BASE_URL";
            } else {
                $details = $responseCode . " " . Response::$statusTexts[$responseCode];
            }

            $message = "Could not retrieve API token from Magento Instance ({$details})";
            $context = [
                "tokenUrl" => $url,
                "responseCode" => $responseCode,
                "MAGENTO_ADMIN_USERNAME" => getenv("MAGENTO_ADMIN_USERNAME"),
                "MAGENTO_ADMIN_PASSWORD" => getenv("MAGENTO_ADMIN_PASSWORD"),
            ];
            throw new TestFrameworkException($message, $context);
        }

        return json_decode($response);
    }

    /**
     * A wrapping method for any custom logic which needs to be applied to the module list
     *
     * @param array $modulesPath
     * @return string[]
     */
    protected function applyCustomModuleMethods($modulesPath)
    {
        $modulePathsResult = $this->removeBlacklistModules($modulesPath);
        $customModulePaths = $this->getCustomModulePaths();

        array_map(function ($value) {
            LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->info(
                "including custom module",
                ['module' => $value]
            );
        }, $customModulePaths);

        if (!isset($this->nonFlattenedEnabledModulePaths)) {
            $this->nonFlattenedEnabledModulePaths = array_merge($modulePathsResult, $customModulePaths);
        }
        return $this->flattenAllModulePaths(array_merge($modulePathsResult, $customModulePaths));
    }

    /**
     * Remove blacklist modules from input module paths.
     *
     * @param array $modulePaths
     * @return string[]
     */
    private function removeBlacklistModules($modulePaths)
    {
        $modulePathsResult = $modulePaths;
        foreach ($modulePathsResult as $moduleName => $modulePath) {
            // Remove module if it is in blacklist
            if (in_array($moduleName, $this->getModuleBlacklist())) {
                unset($modulePathsResult[$moduleName]);
                LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->info(
                    "excluding module",
                    ['module' => $moduleName]
                );
            }
        }

        return $modulePathsResult;
    }

    /**
     * Returns an array of custom module paths defined by the user
     *
     * @return string[]
     */
    private function getCustomModulePaths()
    {
        $customModulePaths = getenv(self::CUSTOM_MODULE_PATHS);

        if (!$customModulePaths) {
            return [];
        }

        return array_map('trim', explode(',', $customModulePaths));
    }

    /**
     * Getter for moduleBlacklist.
     *
     * @return string[]
     */
    private function getModuleBlacklist()
    {
        return $this->moduleBlacklist;
    }

    /**
     * Calls Magento method for determining registered modules.
     *
     * @return string[]
     */
    private function getRegisteredModuleList()
    {
        if (!empty($this->registeredModuleList)) {
            return $this->registeredModuleList;
        }

        if (array_key_exists('MAGENTO_BP', $_ENV)) {
            $autoloadPath = realpath(MAGENTO_BP . "/app/autoload.php");
            if ($autoloadPath) {
                require_once($autoloadPath);
            } else {
                throw new TestFrameworkException("Magento app/autoload.php not found with given MAGENTO_BP:"
                    . MAGENTO_BP);
            }
        }

        try {
            $allComponents = [];
            if (!class_exists(self::REGISTRAR_CLASS)) {
                throw new TestFrameworkException("Magento Installation not found when loading registered modules.\n");
            }
            $components = new \Magento\Framework\Component\ComponentRegistrar();
            foreach (self::PATHS as $componentType) {
                $allComponents = array_merge($allComponents, $components->getPaths($componentType));
            }
            array_walk($allComponents, function (&$value) {
                // Magento stores component paths with unix DIRECTORY_SEPARATOR, need to stay uniform and convert
                $value = realpath($value);
                $value .= '/Test/Mftf';
            });
            return $allComponents;
        } catch (TestFrameworkException $e) {
            LoggingUtil::getInstance()->getLogger(ModuleResolver::class)->warning(
                "$e"
            );
        }
        return [];
    }

    /**
     * Returns custom Backend URL if set, fallback to Magento Base URL
     * @return string|null
     */
    private function getBackendUrl()
    {
        return getenv('MAGENTO_BACKEND_BASE_URL') ?: getenv('MAGENTO_BASE_URL');
    }

    /**
     * Return possible vendor name from a path given
     *
     * @param string $path
     * @return string
     */
    private function getPossibleVendorModuleName($path)
    {
        $path = str_replace(DIRECTORY_SEPARATOR . 'Test' . DIRECTORY_SEPARATOR . 'Mftf', '', $path);
        return $this->getPossibleVendorName($path) . '_' . basename($path);
    }

    /**
     * Return possible vendor name from a path given
     *
     * @param string $path
     * @return string
     */
    private function getPossibleVendorName($path)
    {
        $possibleVendorName = 'UnknownVendor';
        $dirPaths = [
            self::VENDOR,
            self::APP_CODE,
            self::DEV_TESTS
        ];

        foreach ($dirPaths as $dirPath) {
            $regex = "~.+\\/" . $dirPath . "\/(?<" . self::VENDOR . ">[^\/]+)\/.+~";
            $match = [];
            preg_match($regex, $path, $match);
            if (isset($match[self::VENDOR])) {
                $possibleVendorName = ucfirst($match[self::VENDOR]);
                return $possibleVendorName;
            }
        }
        return $possibleVendorName;
    }
}
