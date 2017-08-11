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
     * array(
     *      'Path', // The path tot he module
     *      'Classes', // Returns all classes (and subclasses where applicable)
     *                              contained in this modules folder
     *      'DataObjects', // Further info about all DataObjects introduced by this module
     * )
     * @return array
     */
    public function setUp()
    {
        $this->getAllModules();
        $this->addClassInfoPerModule();
        $this->addDataObjectAndExtensionInfo();
        d($this->result);
        $sql = $this->toSQL();

        echo($sql);
        exit;
        return $result;
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
        $classes = array(
            'DataObjects' => ClassInfo::dataClassesFor('DataObject'),
            'DataExtensions' => ClassInfo::subclassesFor('DataExtension')
        );
        foreach ($classes as $label => $dataClasses) {
            array_shift($dataClasses);
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

            if ($classType == 'DataExtensions') {
                $tables = array();
                // Find all DataObjects with this extension, and add them to the table list
                foreach (ClassInfo::dataClassesFor('DataObject') as $dataClass) {
                    if (Object::has_extension($dataClass, $className)) {
                        $tables[] = $dataClass;
                    }
                }

                if (count($tables)) {
                    $tableName = $tables;
                }
            }

            // Add database fields
            $info = array(
                'Table' => $tableName,
                'Fields' => DataObject::custom_database_fields($className)
            );

            $this->result[$moduleName][$classType . 's'][strtolower($className)] = $info;
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
        $statements[] = "DROP TABLE IF EXISTS $moduleTableName;
                        CREATE TABLE IF NOT EXISTS $moduleTableName 
                        (ModuleName VARCHAR(100), 
                        TableName VARCHAR(100),
                        BaseTableName VARCHAR(100),
                        RowsFound INTEGER,
                        LastUsed DATETIME,
                        ColumnsInUse INTEGER
                        ); 
                        TRUNCATE TABLE $moduleTableName;";
        foreach ($this->result as $moduleName => $moduleInfo) {
            $tables = $moduleInfo['Classes'];
            // First create temporary table
            $statement = array();
            foreach ($tables as $tableRow) {
                $table = $tableRow['Table'];
                $baseTable = ClassInfo::baseDataClass($table);
                $output = "SELECT '$moduleName' as Module,";
                foreach ($tableRow['Fields'] as $columnName => $fieldType) {
                    $output .= "`$columnName`,";
                }
                $output = rtrim($output, ',');
                $output .= " FROM `$schema`.`$table`";
                $insertInto = "INSERT INTO $moduleTableName (ModuleName, BaseTableName, RowsFound, 
                                TableName, LastUsed, ColumnsInUse) 
                                SELECT '$moduleName' as ModuleName, '$baseTable' as BaseTableName,
                                        count(*) as 'RowsFound',
                                        '$table' as TableName, 
                                        (Select Max(`$baseTable`.LastEdited) from `$baseTable` JOIN 
                                        `$table` as child on `$baseTable`.`ID` = child.`ID`
                                        )
                                        as LastEdited,
                                        0 as ColumnsInUse ";


                $insertInto .= "FROM ($output) as `$table`;";
                $statement[] = $insertInto;
            }
            $statements[] = implode(';', $statement);
        }
        $sql = $statements;
        $sql[] = "SELECT * FROM $moduleTableName;";
        $sql = array_filter($sql);
        return implode(' ', $sql);
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
    private function getAllModules()
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
