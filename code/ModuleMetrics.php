<?php

class ModuleMetrics
{

    /**
     * @var ModuleMetrics
     */
    private static $instance;

    /**
     * Contains the results
     * @var array
     */
    private $result;

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

    /**
     * Returns the following info about each module:
     * array(a
     *      'Path', // The path tot he module
     *      'Classes', // Returns all classes (and subclasses where applicable)
     *                              contained in this modules folder
     *      'DataObjects', // Further info about all DataObjects introduced by this module
     * )
     * @return array
     */
    public function setUp()
    {
        $this->setModules();
        $this->addClassInfoPerModule();
        $this->addDataObjectAndExtensionInfo();
        $sql = $this->toSQL();
        echo $sql;
        exit;
    }

    /**
     * Returns the following info about each module that affects the database:
     * array(
     *      'Path', // The path tot he module
     *      'Classes', // Returns all classes (and subclasses where applicable)
     *                              contained in this modules folder
     *      'DataObjects', // Further info about all DataObjects introduced by this module
     * )
     * @return array
     */
    private function getModulesWithDataManipulations()
    {
        $result = $this->setUp();
        foreach ($result as $moduleName => $info) {
            if (!isset($info['DataObjects'])) {
                unset($result[$moduleName]);
            }
        }

        return $result;
    }

    /**
     * Returns the following info about each module that has no database interaction:
     * array(
     *      'Path', // The path tot he module
     *      'Classes', // Returns all classes (and subclasses where applicable)
     *                              contained in this modules folder
     * )
     * @return array
     */
    public function getModulesWithNoDataManipulations()
    {
        $result = $this->setUp();
        foreach ($result as $moduleName => $info) {
            if (isset($info['DataObjects'])) {
                unset($result[$moduleName]);
            }
        }
        return $result;
    }

    /**
     * Returns information about all DataObjects per module, excluding test DataObjects.
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
                'Fields' => array_keys(DataObject::custom_database_fields($className))
            );

            $this->result[$moduleName][$classType][strtolower($className)] = $info;
        }
    }

    /**
     * Generates SQL statements. Expects $result to look like this
     * <code>
     * array(
     *  'moduleName'=>array(
     *      'Path'=>'Path/to/module'
     *      'Classes'=>array()
     *  )
     * )
     * </code>
     * @return string
     */
    private function toSQL()
    {
        $schema = $this->getSchemaName();
        $moduleTableName = 'ModuleDataObjectRowCount';
        $statements = array();
        $fieldSchemas = array(
            'ModuleName' => 'VARCHAR(100)',
            'TableName' => 'VARCHAR(100)',
            'BaseTableName' => 'VARCHAR(100)',
            'RowsFound' => 'INTEGER',
            'LastUsed' => 'DATETIME',
            'InUse' => 'VARCHAR(8)',
            'Type' => 'VARCHAR(100)'
        );
        $fieldsOnly = implode(',', array_keys($fieldSchemas));
        array_walk($fieldSchemas, function (&$value, $key) {
            $value = "$key $value";
        });
        $definitionsOnly = implode(',', array_values($fieldSchemas));
        $statements[] = "DROP TABLE IF EXISTS $moduleTableName;
                        CREATE TABLE IF NOT EXISTS $moduleTableName 
                        ($definitionsOnly);";
        $statements[] = $this->dataObjectsToSQL($schema, $moduleTableName, $fieldsOnly);
        $statements[] = $this->dataExtensionsToSQL($schema, $moduleTableName, $fieldsOnly);
        $sql = $statements;
        // Remove all false entries
        $sql = array_filter($sql);
        return implode(' ', $sql);
    }

    /**
     * @param $schema
     * @param $moduleTableName
     * @return string
     */
    public function dataObjectsToSQL($schema, $moduleTableName, $fieldsOnly)
    {
        $result = '';
        $dataType = 'DataObjects';
        foreach ($this->result as $moduleName => $moduleInfo) {
            if (!isset($moduleInfo[$dataType])) {
                continue;
            };
            $dataObjects = $moduleInfo[$dataType];
            // First create temporary table
            $statement = array();
            foreach ($dataObjects as $name => $tableRow) {
                $table = $tableRow['Table'];
                $baseTable = ClassInfo::baseDataClass($table);
                $output = "SELECT '$moduleName' as Module,";
                foreach ($tableRow['Fields'] as $columnName) {
                    $output .= "`$columnName`,";
                }
                $output = rtrim($output, ',');
                $output .= " FROM `$schema`.`$table`";
                $insertInto = "INSERT INTO $moduleTableName ($fieldsOnly) 
                                SELECT '$moduleName' as ModuleName,
                                        '$table' as TableName, 
                                        '$baseTable' as BaseTableName,
                                        count(*) as 'RowsFound',
                                        (Select Max(`$baseTable`.LastEdited) from `$baseTable` JOIN 
                                        `$table` as child on `$baseTable`.`ID` = child.`ID`
                                        )as LastUsed,
                                        CASE WHEN count(*) > 0 THEN 1 ELSE 0 END as InUse,
                                        '$dataType' as `Type`";
                $insertInto .= " FROM ($output) as `$table`;";
                $statement[] = $insertInto;
            }
            $result .= implode(';', $statement);
        }
        return $result;
    }

    public function dataExtensionsToSQL($schema, $moduleTableName, $fieldsOnly)
    {
        $result = '';
        $dataType = 'DataExtensions';
        foreach ($this->result as $moduleName => $moduleInfo) {
            if (!isset($moduleInfo[$dataType])) {
                continue;
            };
            $dataObjects = $moduleInfo[$dataType];
            // First create temporary table
            $statement = array();
            foreach ($dataObjects as $name => $tableRow) {
                $baseTables = array();
                if (is_array($tableRow['Table'])) {
                    $baseTables = $tableRow['Table'];
                } else {
                    $baseTables[] = $tableRow['Table'];
                }
                foreach ($baseTables as $baseTable) {
                    $statement[] = $this->addSingleExtensionField(
                        $tableRow,
                        $schema,
                        $baseTable,
                        $moduleTableName,
                        $fieldsOnly,
                        $moduleName,
                        $name,
                        $dataType
                    );
                }
            }
            $result .= implode(';', $statement);
        }
        return $result;
    }

    public function addSingleExtensionField(
        $tableRow,
        $schema,
        $baseTable,
        $moduleTableName,
        $fieldsOnly,
        $moduleName,
        $name,
        $dataType
    )
    {

        $extensionFields = $tableRow['Fields'];
        array_walk($extensionFields, function (&$value, $key) {
            $value = "NULLIF($value,'')";
        });
        $extensionFields = implode(',', $extensionFields);
        $output = "SELECT COALESCE($extensionFields) as `Status`";
        $output .= " FROM `$schema`.`$baseTable`";
        $output .= " WHERE COALESCE($extensionFields) IS NOT NULL";
        $insertInto = "INSERT INTO $moduleTableName ($fieldsOnly)
                        SELECT '$moduleName' as ModuleName,
                                '$name' as TableName,
                                '$baseTable' as BaseTableName,
                                count(*) as 'RowsFound',
                                null as LastUsed,
                                CASE WHEN count(*) > 0 THEN 1 ELSE 0 END as InUse,
                                '$dataType' as `Type`";
        $insertInto .= " FROM ($output) as `$name`;";
        return $insertInto;
    }

    /**
     * Gets the module name for a given class
     * @param $class
     * @return string
     */
    private function getModuleName($class)
    {
        $dir = SS_ClassLoader::instance()->getItemPath($class);
        $dir = str_ireplace(BASE_PATH . DIRECTORY_SEPARATOR, '', $dir);
        $path = explode(DIRECTORY_SEPARATOR, $dir);
        return $path[0];
    }

    /**
     * Returns a list of all modules within this SilverStripe installation
     * @return array
     */
    private function setModules()
    {
        $manifest = SS_ClassLoader::instance()->getManifest();
        $result = $manifest->getModules();
        array_walk($result, function (&$value, $key) {
            $value = array('Path' => $value);
        });
        ksort($result);
        $this->result = $result;
        return $result;
    }

    private function getSchemaName()
    {
        global $dbName;
        return $dbName;
    }

    /**
     * Gets class and Sub-class information for each module
     * @param bool $includeSubclasses
     */
    public function addClassInfoPerModule($includeSubclasses = false)
    {
        foreach ($this->result as $key => $value) {
            $classes = ClassInfo::classes_for_folder($value['Path']);
            asort($classes);
            foreach ($classes as $index => $class) {
                $this->result[$key]['Classes'][$class] = null;
                if ($includeSubclasses) {
                    $this->addSubClassInfo($key, $class);
                }
            }
        }
    }

    public function addSubClassInfo($key, $class)
    {
        if ($subClasses = ClassInfo::subclassesFor($class)) {
            array_shift($subClasses);
            if (count($subClasses)) {
                // Add subclass info
                $subClasses = array_keys($subClasses);
                asort($subClasses);
                $this->result[$key]['Classes'][$class]['SubClasses'] = $subClasses;
            } else {
                $this->result[$key]['Classes'][$class] = null;
            }
        }
    }
}
