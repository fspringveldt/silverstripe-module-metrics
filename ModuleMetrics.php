<?php

class ModuleMetrics
{
    private static $includeSchema = false;

    private $siteName;

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
                'Fields' => DataObject::custom_database_fields($className)
            );

            $this->result[$moduleName][$classType][strtolower($className)] = $info;
        }
    }

    public function addModuleUsageInfo($classes, $classType)
    {
        foreach ($classes as $className) {
            $fields = DataObject::database_fields($className);
            $moduleName = $this->getModuleName($className);
            $tableName = $className;
            if (!$fields || ClassInfo::classImplements($className, 'TestOnly')) {
                continue;
            }

        }
    }

    /**
     * Returns a SQL dump of module data interaction, runnable in mysql.
     * @todo Make database agnostic by implementing Convert::symbol2sql
     *
     * @return string
     */
    public function getResultAsSQL()
    {
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
        $dropAndCreate = "DROP TABLE IF EXISTS $moduleTableName;";
        $dropAndCreate .= $this->createIfNotExists($moduleTableName, $fieldSchemas);
        $statements[] = $dropAndCreate;
        $statements[] = $this->dataObjectsToSQL($moduleTableName, $fieldsOnly);
        $statements[] = $this->dataExtensionsToSQL($moduleTableName, $fieldsOnly);
        $sql = $statements;
        $sql[] = "SELECT * FROM $moduleTableName WHERE 1;";
        // Remove all false entries
        $sql = array_filter($sql);
        return implode(' ', $sql);
    }

    /**
     * Echo's the SQL to the screen and halts script execution.
     */
    public function printSQL()
    {
        echo $this->getResultAsSQL();
        exit;
    }

    public function createIfNotExists($tableName, array $fields)
    {
        if (!is_array($fields)) {
            user_error('Parameter $fields should be an array');
        }

        array_walk($fields, function (&$value, $key) {
            $replacements = array(
                'ForeignKey' => 'Int',
                'HTMLText' => 'mediumtext',
                "HTMLText('meta, link')" => 'mediumtext',
                'HTMLVarchar' => 'varchar',
                'Currency' => 'decimal',
                'SS_Datetime' => 'datetime',
                'DBLocale' => 'varchar',
                'Boolean(true)' => 'tinyint(1)',
                'Boolean(false)' => 'tinyint(1)',
                'Boolean(1)' => 'tinyint(1)',
                'Boolean(0)' => 'tinyint(1)',
                'Boolean' => 'tinyint(1)'
            );
            $val = $value;
            if (array_key_exists(trim($value), $replacements)) {
                $val = $replacements[$value];
            }

            // Check and fix varchar field types with no size
            if (substr(strtolower($val), 0, 7) == 'varchar' && strpos($val, '(') === false) {
                $val = 'VARCHAR(100)';
            }
            $value = "`$key` $val";
        });

        $fields = implode(',', $fields);
        $result = "CREATE TABLE IF NOT EXISTS `$tableName` ($fields);";
        return $result;
    }

    /**
     * @param $moduleTableName
     * @param $fieldsOnly
     * @return string
     */
    public function dataObjectsToSQL($moduleTableName, $fieldsOnly)
    {
        $result = '';
        $dataType = 'DataObjects';
        foreach ($this->getResult() as $moduleName => $moduleInfo) {
            if (!isset($moduleInfo[$dataType])) {
                continue;
            };
            $dataObjects = $moduleInfo[$dataType];
            // First create temporary table
            $statement = array();
            foreach ($dataObjects as $name => $tableRow) {
                $table = $tableRow['Table'];
                $baseTable = ClassInfo::baseDataClass($table);
                $output = "SELECT '$moduleName' as Module, `$table`.*";
                $output = rtrim($output, ',');
                $output .= " FROM `$table`";
                $statement[] = $this->createIfNotExists($baseTable, array('ID' => 'Int', 'LastEdited' => 'datetime'));
                $statement[] = $this->createIfNotExists($table, array('ID' => 'Int'));
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
            $result .= implode('', $statement);
        }
        return $result;
    }

    public function dataExtensionsToSQL($moduleTableName, $fieldsOnly)
    {
        $result = '';
        $dataType = 'DataExtensions';
        foreach ($this->getResult() as $moduleName => $moduleInfo) {
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
                        $baseTable,
                        $moduleTableName,
                        $fieldsOnly,
                        $moduleName,
                        $name,
                        $dataType
                    );
                }
            }
            $result .= implode('', $statement);
        }
        return $result;
    }

    public function addSingleExtensionField(
        $tableRow,
        $baseTable,
        $moduleTableName,
        $fieldsOnly,
        $moduleName,
        $name,
        $dataType
    )
    {
        $extensionFields = array_keys($tableRow['Fields']);
        array_walk($extensionFields, function (&$value, $key) {
            $value = "NULLIF($value,'')";
        });
        $extensionFields = implode(',', $extensionFields);
        $output = "SELECT COALESCE($extensionFields) as `Status`";
        $output .= " FROM `$baseTable`";
        $output .= " WHERE COALESCE($extensionFields) IS NOT NULL";
        $fields = DataObject::database_fields($baseTable);
        $insertInto = $this->createIfNotExists($baseTable, $fields);
        $insertInto .= "INSERT INTO $moduleTableName ($fieldsOnly)
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
        if (!self::$includeSchema) {
            return '';
        }
        global $dbName;
        return $dbName;
    }

    /**
     * Gets class and Sub-class information for each module
     * @param bool $includeSubclasses
     */
    public function addClassInfoPerModule($includeSubclasses = false)
    {
        foreach ($this->getResult() as $key => $value) {
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
            if (array_key_exists('DataObjects', $moduleInfo)) {
                $this->result[$moduleName]['InUse'] = 0;
                foreach ($moduleInfo['DataObjects'] as $dataObjectInfo) {
                    $table = $dataObjectInfo['Table'];
                    $count = DB::query("SELECT COUNT(*) FROM `$table`")->value();
                    if ($count > 0) {
                        $this->result[$moduleName]['InUse'] = true;
                        $this->result[$moduleName]['RecordCount'] = $count;
                        break;
                    }
                }
            }
            if (array_key_exists('DataExtensions', $moduleInfo)) {
                $this->result[$moduleName]['InUse'] = 0;
                foreach ($moduleInfo['DataExtensions'] as $extensionName => $extensionInfo) {
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
                                    if ($baseTableDataObject::get()->where("$fName IS NOT NULL")->count() > 0) {
                                        //If any of the fields in this module has a non-null value, then it is in use
                                        $this->result[$moduleName]['InUse'] = 1;
                                        $this->result[$moduleName]['FieldInUse'] = "$baseTable.$fName";
                                        break(4);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function toJSON()
    {
        $result = array();
        foreach ($this->getResult() as $moduleName => $moduleInfo) {
            $result[] = array(
                'Site' => $this->getSiteName(),
                'ModuleName' => $moduleName,
                'InUse' => (isset($moduleInfo['InUse']) ? $moduleInfo['InUse'] : 'Unknown'),
                'RecordsFound' => (isset($moduleInfo['RecordCount']) ? $moduleInfo['RecordCount'] : 0),
                'FieldInUse' => (isset($moduleInfo['FieldInUse']) ? $moduleInfo['FieldInUse'] : '')
            );
        }
        echo Convert::array2json($result);
    }
}
