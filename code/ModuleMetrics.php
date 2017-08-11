<?php

class ModuleMetrics
{
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
    public static function getAllInstallationMetrics()
    {
        $result = self::getAllModules();
//        self::addClassUsageInfoByModule($result);
//        d($result);
        self::addDataObjectUsageInfoByModule($result);
        $sql = self::generateSQL($result);
//        d($result);
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
    public static function getModulesWithDataManipulations()
    {
        $result = self::getAllInstallationMetrics();
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
    public static function getModulesWithNoDataManipulations()
    {
        $result = self::getAllInstallationMetrics();
        foreach ($result as $moduleName => $info) {
            if (isset($info['DataObjects'])) {
                unset($result[$moduleName]);
            }
        }
        return $result;
    }

    /**
     * Returns information about all DataObjects per module, excluding test DataObjects.
     * @param $result
     * @return array
     */
    public static function addDataObjectUsageInfoByModule(&$result)
    {
        $dataObjectClasses = ClassInfo::dataClassesFor('DataObject');
        array_shift($dataObjectClasses);
        $extensions = ClassInfo::subclassesFor('DataExtension');
        array_shift($extensions);
        $dataClasses = ArrayLib::array_merge_recursive($dataObjectClasses);
        ksort($dataClasses);
        foreach ($dataClasses as $class) {
            $className = $class;
            $tableName = $className;

            $fields = DataObject::database_fields($className);
            $moduleName = self::getModuleName($className);

            if (!$fields || ClassInfo::classImplements($className, 'TestOnly')) {
                continue;
            }
            // Add database fields
            $info = array(
                'Table' => $tableName,
                'Fields' => DataObject::custom_database_fields($className)
            );

            $result[$moduleName][] = $info;
        }
    }

    private static function generateSQL($result)
    {
        $schema = self::getSchemaName();
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
        foreach ($result as $moduleName => $tables) {
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
    private static function getModuleName($class)
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
    private static function getAllModules()
    {
        $manifest = SS_ClassLoader::instance()->getManifest();
        $result = $manifest->getModules();
        array_walk($result, function (&$value, $key) {
            $value = array();
        });
        ksort($result);
        return $result;
    }

    private static function getSchemaName()
    {
        global $dbName;
        return $dbName;
    }
}
