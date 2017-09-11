<?php

class ModuleMetrics
{
    protected $siteName;

    /**
     * @var ModuleMetrics
     */
    private static $instance;

    /**
     * Contains the results
     * @var array
     */
    protected $result;

    /**
     * This is a map of modules which mutates the DB through DataExtension->augmentDatabase method.
     * These modules require upfront code-analysis to determine which tables they introduce so we can
     * check against them.
     *
     * This maps a module to the table-suffixes which it introduces
     * @var array
     */
    protected $specialModules = array(
        'Translatable' => array('translationgroups')
    );

    /**
     * Returns a singleton of this object.
     * @return ModuleMetrics
     */
    public static function inst()
    {

        if (!self::$instance) {
            self::$instance = new ModuleMetrics();
        }
        return self::$instance;
    }

    public function setResult()
    {
        $this->setModules();
        $this->addDataObjectAndExtensionInfo();
        $this->setModuleUsage();
    }

    public function getResult()
    {
        if (!$this->result) {
            $this->setResult();
        }

        return $this->result;
    }

    /**
     * Sets information about all DataObjects per module, excluding test DataObjects.
     */
    public function addDataObjectAndExtensionInfo()
    {
        $extensions = ClassInfo::subclassesFor('DataExtension');
        array_shift($extensions);
        $classes = array(
            'DataObjects' => ClassInfo::dataClassesFor('DataObject'),
            'DataExtensions' => $extensions
        );
        foreach ($classes as $label => $dataClasses) {
            ksort($dataClasses);
            $this->addDatabaseInfo($dataClasses, $label);
        }
    }

    /**
     * @param array $classes
     * @param $classType
     */
    public function addDatabaseInfo($classes, $classType)
    {
        foreach ($classes as $className) {
            $fields = DataObject::database_fields($className);
            $moduleName = $this->getModuleName($className);
            $tableName = $className;
            if (!$fields || ClassInfo::classImplements($className, 'TestOnly')) {
                continue;
            }

            /**
             * Find all DataObjects with this extension and add them to the table list
             * if they actually implement the DataExtension's fields
             */
            if ($classType == 'DataExtensions') {
                $tables = array();
                $extensionFields = DataObject::custom_database_fields($className);
                $dataClasses = ClassInfo::dataClassesFor('DataObject');
                foreach ($dataClasses as $dataClass) {
                    if (Object::has_extension($dataClass, $className)) {
                        foreach ($extensionFields as $fName => $fType) {
                            if (DataObject::has_own_table($dataClass) &&
                                DataObject::has_own_table_database_field($dataClass, $fName)
                            ) {
                                $tables[] = $dataClass;
                                break;
                            }
                        }
                    }
                }

                $total = count($tables);
                if ($total) {
                    $tableName = ($total == 1) ? $tables[0] : $tables;
                } else {
                    // This particular extension is available but not in use.
                    continue;
                }
            }

            // Add database fields
            $info = array(
                'Table' => $tableName,
                'Fields' => DataObject::custom_database_fields($className)
            );

            $this->result[$moduleName][$classType][strtolower($className)] = $info;
        }
    }

    /**
     * Gets the module name for a given class
     * @param $class
     * @return string
     */
    public function getModuleName($class)
    {
        $dir = SS_ClassLoader::instance()->getItemPath($class);
        $dir = str_ireplace(BASE_PATH . DIRECTORY_SEPARATOR, '', $dir);
        $path = explode(DIRECTORY_SEPARATOR, $dir);
        return $path[0];
    }

    /**
     * Sets a list of all modules within this SilverStripe installation
     * @return array
     */
    public function setModules()
    {
        $manifest = SS_ClassLoader::instance()->getManifest();
        $result = $manifest->getModules();
        array_walk($result, function (&$value, $key) {
            $value = array('Path' => $value);
        });
        ksort($result);
        $this->result = $result;
    }

    /**
     * Returns the website name
     * @return string
     */
    public function getSiteName()
    {
        if (!$this->siteName) {
            $this->siteName = Director::baseURL();
        }
        return $this->siteName;
    }

    /**
     * Determines if a module is in use or not.
     * If at least a DataObject introduced by a module, or a DataExtension introduced by a module
     * has relevant data set, that module is considered in use.
     */
    public function setModuleUsage()
    {
        foreach ($this->result as $moduleName => $moduleInfo) {
            $this->result[$moduleName]['InUse'] = 2;
            $this->determineDataObjectUsage($moduleName, $moduleInfo);
            $this->determineDataExtensionUsage($moduleName, $moduleInfo);
        }
        $this->determineAugmentDatabaseExtensionUsage();
    }

    /**
     * Cycles through all DataObjects to determine if they have a specific data-extension which overrides
     * DataExtension->augmentDatabase method. If it does, it checks whether a table exists containing a suffix
     * introduced in the method. If it does, it checks if that table has any rows.
     */
    public function determineAugmentDatabaseExtensionUsage()
    {
        foreach ($this->specialModules as $moduleName => $tableSuffixes) {
            // Loops through all the DataObjects to determine if they have the extension
            foreach (ClassInfo::subclassesFor('DataObject') as $dataObject) {
                if (!Object::has_extension($dataObject, $moduleName)) {
                    continue;
                }
                $baseDataClass = ClassInfo::baseDataClass($dataObject);
                if ($dataObject != $baseDataClass) {
                    continue;
                }

                foreach ($tableSuffixes as $key => $tableSuffix) {
                    $table = sprintf('%s_%s', $baseDataClass, $tableSuffix);
                    if (!ClassInfo::hasTable($table)) {
                        continue;
                    }

                    $count = DB::query("SELECT COUNT(*) FROM `$table`")->value();
                    if ($count > 0) {
                        $lModuleName = strtolower($moduleName);
                        $this->result[$lModuleName]['InUse'] = 1;
                        $this->result[$lModuleName]['TableInUse'] = $table;
                        $this->result[$lModuleName]['RecordCount'] = $count;
                        return;
                    }
                }
            }
        }
    }

    /**
     * Checks if any of the DataObjects introduced by $moduleName has any records and returns true.
     * false otherwise
     * @param $moduleName
     * @param $moduleInfo
     */
    public function determineDataObjectUsage($moduleName, $moduleInfo)
    {
        if (array_key_exists('DataObjects', $moduleInfo)) {
            foreach ($moduleInfo['DataObjects'] as $dataObjectInfo) {
                $table = $dataObjectInfo['Table'];
                if (!ClassInfo::hasTable($table)) {
                    continue;
                }
                $count = DB::query("SELECT COUNT(*) FROM `$table`")->value();
                if ($count > 0) {
                    $this->result[$moduleName]['InUse'] = 1;
                    $this->result[$moduleName]['RecordCount'] = $count;
                    $this->result[$moduleName]['TableInUse'] = $table;
                    return;
                }
            }
        }
    }

    /**
     * Checks if any of the fields introduced by a DataExtension within $moduleName has a non-null value. If it does
     * then it is assumed to be in use.
     * @param $moduleName
     * @param $moduleInfo
     */
    public function determineDataExtensionUsage($moduleName, $moduleInfo)
    {
        if (array_key_exists('DataExtensions', $moduleInfo)) {
            foreach ($moduleInfo['DataExtensions'] as $extensionName => $extensionInfo) {
                $this->usageBasedOnColumnValues($moduleName, $extensionName, $extensionInfo);
            }
        }
    }

    /**
     * Determines if a module is in use based by checking for a valid value in any new field introduced.
     * @param string $moduleName
     * @param string $extensionName
     * @param array $extensionInfo
     */
    public function usageBasedOnColumnValues($moduleName, $extensionName, $extensionInfo)
    {
        $baseTables = array();
        if (is_array($extensionInfo['Table'])) {
            $baseTables = $extensionInfo['Table'];
        } else {
            $baseTables[] = $extensionInfo['Table'];
        }
        foreach ($baseTables as $baseTable) {
            $baseTableDataObject = singleton($baseTable);
            $extensionFields = $extensionInfo['Fields'];
            $dataClasses = ClassInfo::dataClassesFor('DataObject');
            foreach ($dataClasses as $dataClass) {
                if (Object::has_extension($dataClass, $extensionName)) {
                    foreach ($extensionFields as $fName => $fType) {
                        $escapedFieldName = sprintf(
                            '%s.%s',
                            Convert::symbol2sql($baseTable),
                            Convert::symbol2sql($fName)
                        );

                        if ($baseTableDataObject::get()
                                ->where("$escapedFieldName IS NOT NULL AND $escapedFieldName <> 0")->count() > 0
                        ) {
                            // If any of the fields in this module has a non-null value, then it is in use
                            $this->result[$moduleName]['InUse'] = 1;
                            $this->result[$moduleName]['FieldInUse'] = $escapedFieldName;
                            return;
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns $this->result as json
     * @return string
     */
    public function toJSON($prettyPrint = false)
    {
        $result = array();
        foreach ($this->getResult() as $moduleName => $moduleInfo) {
            $result[] = array(
                'Site' => $this->getSiteName(),
                'ModuleName' => $moduleName,
                'InUse' => (isset($moduleInfo['InUse']) ? $moduleInfo['InUse'] : 2),
                'RecordsFound' => (isset($moduleInfo['RecordCount']) ? $moduleInfo['RecordCount'] : 0),
                'FieldInUse' => (isset($moduleInfo['FieldInUse']) ? $moduleInfo['FieldInUse'] : 'n/a'),
                'TableInUse' => (isset($moduleInfo['TableInUse']) ? $moduleInfo['TableInUse'] : 'n/a')
            );
        }
        return Convert::array2json($result, $prettyPrint ? JSON_PRETTY_PRINT : 0);
    }
}
