<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\FunctionalTestingFramework\StaticCheck;

use Magento\FunctionalTestingFramework\Config\MftfApplicationConfig;
use Magento\FunctionalTestingFramework\DataGenerator\Handlers\DataObjectHandler;
use Magento\FunctionalTestingFramework\Exceptions\TestReferenceException;
use Magento\FunctionalTestingFramework\Exceptions\XmlException;
use Magento\FunctionalTestingFramework\Page\Handlers\PageObjectHandler;
use Magento\FunctionalTestingFramework\Page\Handlers\SectionObjectHandler;
use Magento\FunctionalTestingFramework\Test\Handlers\ActionGroupObjectHandler;
use Magento\FunctionalTestingFramework\Test\Handlers\TestObjectHandler;
use Magento\FunctionalTestingFramework\Test\Objects\ActionObject;
use Magento\FunctionalTestingFramework\Util\TestGenerator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Finder\Finder;
use Exception;
use Magento\FunctionalTestingFramework\Util\Script\ScriptUtil;

/**
 * Class TestDependencyCheck
 * @package Magento\FunctionalTestingFramework\StaticCheck
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TestDependencyCheck implements StaticCheckInterface
{
    const EXTENDS_REGEX_PATTERN = '/extends=["\']([^\'"]*)/';
    const ACTIONGROUP_REGEX_PATTERN = '/ref=["\']([^\'"]*)/';
    const ACTIONGROUP_ARGUMENT_REGEX_PATTERN = '/<argument[^\/>]*name="([^"\']*)/';

    const ERROR_LOG_FILENAME = 'mftf-dependency-checks';
    const ERROR_LOG_MESSAGE = 'MFTF File Dependency Check';

    /**
     * Array of FullModuleName => [dependencies]
     * @var array
     */
    private $allDependencies;

    /**
     * Array of FullModuleName => [dependencies], including flattened dependency tree
     * @var array
     */
    private $flattenedDependencies;

    /**
     * Array of FullModuleName => PathToModule
     * @var array
     */
    private $moduleNameToPath;

    /**
     * Array of FullModuleName => ComposerModuleName
     * @var array
     */
    private $moduleNameToComposerName;

    /**
     * Transactional Array to keep track of what dependencies have already been extracted.
     * @var array
     */
    private $alreadyExtractedDependencies;

    /**
     * Array containing all errors found after running the execute() function.
     * @var array
     */
    private $errors = [];

    /**
     * String representing the output summary found after running the execute() function.
     * @var string
     */
    private $output;

    /**
     * Array containing all entities after resolving references.
     * @var array
     */
    private $allEntities = [];

    /**
     * Checks test dependencies, determined by references in tests versus the dependencies listed in the Magento module
     *
     * @param InputInterface $input
     * @return string
     * @throws Exception;
     */
    public function execute(InputInterface $input)
    {
        $allModules = ScriptUtil::getAllModulePaths();

        if (!class_exists('\Magento\Framework\Component\ComponentRegistrar')) {
            return "TEST DEPENDENCY CHECK ABORTED: MFTF must be attached or pointing to Magento codebase.";
        }
        $registrar = new \Magento\Framework\Component\ComponentRegistrar();
        $this->moduleNameToPath = $registrar->getPaths(\Magento\Framework\Component\ComponentRegistrar::MODULE);
        $this->moduleNameToComposerName = $this->buildModuleNameToComposerName($this->moduleNameToPath);
        $this->flattenedDependencies = $this->buildComposerDependencyList();

        $filePaths = [
            DIRECTORY_SEPARATOR . 'Test' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'ActionGroup' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR,
        ];
        // These files can contain references to other modules.
        $testXmlFiles = ScriptUtil::buildFileList($allModules, $filePaths[0]);
        $actionGroupXmlFiles = ScriptUtil::buildFileList($allModules, $filePaths[1]);
        $dataXmlFiles= ScriptUtil::buildFileList($allModules, $filePaths[2]);

        $this->errors = [];
        $this->errors += $this->findErrorsInFileSet($testXmlFiles);
        $this->errors += $this->findErrorsInFileSet($actionGroupXmlFiles);
        $this->errors += $this->findErrorsInFileSet($dataXmlFiles);

        // hold on to the output and print any errors to a file
        $this->output = ScriptUtil::printErrorsToFile(
            $this->errors,
            self::ERROR_LOG_FILENAME,
            self::ERROR_LOG_MESSAGE
        );
    }

    /**
     * Return array containing all errors found after running the execute() function.
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Return string of a short human readable result of the check. For example: "No Dependency errors found."
     * @return string
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * Finds all reference errors in given set of files
     * @param Finder $files
     * @return array
     * @throws TestReferenceException
     * @throws XmlException
     */
    private function findErrorsInFileSet($files)
    {
        $testErrors = [];
        foreach ($files as $filePath) {
            $modulePath = dirname(dirname(dirname(dirname($filePath))));
            $moduleFullName = array_search($modulePath, $this->moduleNameToPath) ?? null;
            // Not a module, is either dev/tests/acceptance or loose folder with test materials
            if ($moduleFullName == null) {
                continue;
            }

            $contents = file_get_contents($filePath);
            preg_match_all(ActionObject::ACTION_ATTRIBUTE_VARIABLE_REGEX_PATTERN, $contents, $braceReferences);
            preg_match_all(self::ACTIONGROUP_REGEX_PATTERN, $contents, $actionGroupReferences);
            preg_match_all(self::EXTENDS_REGEX_PATTERN, $contents, $extendReferences);

            // Remove Duplicates
            $braceReferences[0] = array_unique($braceReferences[0]);
            $actionGroupReferences[1] = array_unique($actionGroupReferences[1]);
            $braceReferences[1] = array_unique($braceReferences[1]);
            $braceReferences[2] = array_filter(array_unique($braceReferences[2]));

            // resolve data entity references
            $this->resolveDataEntityReferences($braceReferences[0], $contents);

            //resolve entity references
            $this->resolveParametrizedReferences($braceReferences[2], $contents);

            // Check actionGroup references
            $this->resolveEntityReferences($actionGroupReferences[1]);

            // Check extended objects
            $this->resolveEntityReferences($extendReferences[1]);

            // Find violating references and set error output
            $violatingReferences = $this->findViolatingReferences($moduleFullName);
            $testErrors = $this->setErrorOutput($violatingReferences, $filePath);
        }
        return $testErrors;
    }

    /**
     * Drill down into params in {{ref.params('string', $data.key$, entity.reference)}}
     * and resolve references.
     *
     * @param array  $braceReferences
     * @param string $contents
     * @return void
     * @throws XmlException
     */
    private function resolveParametrizedReferences($braceReferences, $contents)
    {
        foreach ($braceReferences as $parameterizedReference) {
            preg_match(
                ActionObject::ACTION_ATTRIBUTE_VARIABLE_REGEX_PARAMETER,
                $parameterizedReference,
                $arguments
            );
            $splitArguments = explode(',', ltrim(rtrim($arguments[0], ")"), "("));
            foreach ($splitArguments as $argument) {
                // Do nothing for 'string' or $persisted.data$
                if (preg_match(ActionObject::STRING_PARAMETER_REGEX, $argument)) {
                    continue;
                } elseif (preg_match(TestGenerator::PERSISTED_OBJECT_NOTATION_REGEX, $argument)) {
                    continue;
                }
                // trim `data.field` to `data`
                preg_match('/([^.]+)/', $argument, $entityName);
                // Double check that {{data.field}} isn't an argument for an ActionGroup
                $entity = $this->findEntity($entityName[1]);
                preg_match_all(self::ACTIONGROUP_ARGUMENT_REGEX_PATTERN, $contents, $possibleArgument);
                if (array_search($entityName[1], $possibleArgument[1]) !== false) {
                    continue;
                }
                if ($entity !== null) {
                    $this->allEntities[$entity->getName()] = $entity;
                }
            }
        }
    }

    /**
     * Check `data` entities in {{data.field}} or {{data.field('param')}} and resolve references
     *
     * @param array  $braceReferences
     * @param string $contents
     * @return void
     * @throws XmlException

     */
    private function resolveDataEntityReferences($braceReferences, $contents)
    {
        foreach ($braceReferences as $reference) {
            // trim `{{data.field}}` to `data`
            preg_match('/{{([^.]+)/', $reference, $entityName);
            // Double check that {{data.field}} isn't an argument for an ActionGroup
            $entity = $this->findEntity($entityName[1]);
            preg_match_all(self::ACTIONGROUP_ARGUMENT_REGEX_PATTERN, $contents, $possibleArgument);
            if (array_search($entityName[1], $possibleArgument[1]) !== false) {
                continue;
            }
            if ($entity !== null) {
                $this->allEntities[$entity->getName()] = $entity;
            }
        }
    }

    /**
     * Resolve entity references
     *
     * @param array $references
     * @return void
     * @throws XmlException
     */
    private function resolveEntityReferences($references)
    {
        foreach ($references as $reference) {
            $entity = $this->findEntity($reference);
            if ($entity !== null) {
                $this->allEntities[$entity->getName()] = $entity;
            }
        }
    }

    /**
     * Find violating references
     *
     * @param string $moduleName
     * @return array
     */
    private function findViolatingReferences($moduleName)
    {
        // Find Violations
        $violatingReferences = [];
        $currentModule = $this->moduleNameToComposerName[$moduleName];
        $modulesReferencedInTest = $this->getModuleDependenciesFromReferences($this->allEntities);
        $moduleDependencies = $this->flattenedDependencies[$moduleName];
        foreach ($modulesReferencedInTest as $entityName => $files) {
            $valid = false;
            foreach ($files as $module) {
                if (array_key_exists($module, $moduleDependencies) || $module == $currentModule) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                $violatingReferences[$entityName] = $files;
            }
        }

        return $violatingReferences;
    }

    /**
     * Builds and returns error output for violating references
     *
     * @param array  $violatingReferences
     * @param string $path
     * @return mixed
     */
    private function setErrorOutput($violatingReferences, $path)
    {
        $testErrors = [];

        if (!empty($violatingReferences)) {
            // Build error output
            $errorOutput = "\nFile \"{$path->getRealPath()}\"";
            $errorOutput .= "\ncontains entity references that violate dependency constraints:\n\t\t";
            foreach ($violatingReferences as $entityName => $files) {
                $errorOutput .= "\n\t {$entityName} from module(s): " . implode(", ", $files);
            }
            $testErrors[$path->getRealPath()][] = $errorOutput;
        }

        return $testErrors;
    }

    /**
     * Builds and returns array of FullModuleNae => composer name
     * @param array $array
     * @return array
     */
    private function buildModuleNameToComposerName($array)
    {
        $returnList = [];
        foreach ($array as $moduleName => $path) {
            $composerData = json_decode(file_get_contents($path . DIRECTORY_SEPARATOR . "composer.json"));
            $returnList[$moduleName] = $composerData->name;
        }
        return $returnList;
    }

    /**
     * Builds and returns flattened dependency list based on composer dependencies
     * @return array
     */
    private function buildComposerDependencyList()
    {
        $flattenedDependencies = [];

        foreach ($this->moduleNameToPath as $moduleName => $pathToModule) {
            $composerData = json_decode(file_get_contents($pathToModule . DIRECTORY_SEPARATOR . "composer.json"), true);
            $this->allDependencies[$moduleName] = $composerData['require'];
        }
        foreach ($this->allDependencies as $moduleName => $dependencies) {
            $this->alreadyExtractedDependencies = [];
            $flattenedDependencies[$moduleName] = $this->extractSubDependencies($moduleName);
        }
        return $flattenedDependencies;
    }

    /**
     * Recursive function to fetch dependencies of given dependency, and its child dependencies
     * @param string $subDependencyName
     * @return array
     */
    private function extractSubDependencies($subDependencyName)
    {
        $flattenedArray = [];

        if (array_search($subDependencyName, $this->alreadyExtractedDependencies) !== false) {
            return $flattenedArray;
        }

        if (isset($this->allDependencies[$subDependencyName])) {
            $subDependencyArray = $this->allDependencies[$subDependencyName];
            $flattenedArray = array_merge($flattenedArray, $this->allDependencies[$subDependencyName]);

            // Keep track of dependencies that have already been used, prevents circular dependency problems
            $this->alreadyExtractedDependencies[] = $subDependencyName;
            foreach ($subDependencyArray as $composerDependencyName => $version) {
                $subDependencyFullName = array_search($composerDependencyName, $this->moduleNameToComposerName);
                $flattenedArray = array_merge(
                    $flattenedArray,
                    $this->extractSubDependencies($subDependencyFullName)
                );
            }
        }
        return $flattenedArray;
    }

    /**
     * Finds unique array ofcomposer dependencies of given testObjects
     * @param array $array
     * @return array
     */
    private function getModuleDependenciesFromReferences($array)
    {
        $filenames = [];
        foreach ($array as $item) {
            // Should it append ALL filenames, including merges?
            $allFiles = explode(",", $item->getFilename());
            foreach ($allFiles as $file) {
                $modulePath = dirname(dirname(dirname(dirname($file))));
                $fullModuleName = array_search($modulePath, $this->moduleNameToPath);
                $composerModuleName = $this->moduleNameToComposerName[$fullModuleName];
                $filenames[$item->getName()][] = $composerModuleName;
            }
        }
        return $filenames;
    }

    /**
     * Attempts to find any MFTF entity by its name. Returns null if none are found.
     * @param string $name
     * @return mixed
     * @throws XmlException
     */
    private function findEntity($name)
    {
        if ($name == '_ENV' || $name == '_CREDS') {
            return null;
        }

        if (DataObjectHandler::getInstance()->getObject($name)) {
            return DataObjectHandler::getInstance()->getObject($name);
        } elseif (PageObjectHandler::getInstance()->getObject($name)) {
            return PageObjectHandler::getInstance()->getObject($name);
        } elseif (SectionObjectHandler::getInstance()->getObject($name)) {
            return SectionObjectHandler::getInstance()->getObject($name);
        }

        try {
            return ActionGroupObjectHandler::getInstance()->getObject($name);
        } catch (TestReferenceException $e) {
        }
        try {
            return TestObjectHandler::getInstance()->getObject($name);
        } catch (TestReferenceException $e) {
        }
        return null;
    }
}
