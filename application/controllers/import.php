<?php

class Import_Controller extends Base_Controller {

	public $restful = true;

	private static $database = 'projecttables';
	private static $host = 'localhost';
	private static $username = 'postgres';
	private static $password = '';
	private static $schema = 'public';
	private static $connection;

	private static $options = array(
	        PDO::ATTR_CASE => PDO::CASE_LOWER,
	        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
	        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
	        PDO::ATTR_STRINGIFY_FETCHES => false,
	);

	/**
     * @return the $connection
     */
    public static function getConnection ()
    {
        return self::$connection;
    }

	/**
     * @param field_type $connection
     */
    public function setConnection ($connection)
    {
        self::$connection = $connection;
    }

	public function __construct()
	{
	    $host = self::getHost();
	    $dbname = self::getDatabase();
	    $schema = self::getSchema();
	    $dsn = "pgsql:host={$host};dbname={$dbname}";
	    self::setConnection(new PDO($dsn, self::getUsername(), self::getPassword(), self::getOptions()));
	    $connection = self::getConnection();
	    $connection->prepare("SET search_path TO {$schema}")->execute();
	}

	/**
     * @return the $options
     */
    public static function getOptions ()
    {
        return self::$options;
    }

	/**
     * @return the $database
     */
    public static function getDatabase ()
    {
        return self::$database;
    }

	/**
     * @return the $host
     */
    public static function getHost ()
    {
        return self::$host;
    }

	/**
     * @return the $schema
     */
    public static function getSchema ()
    {
        return self::$schema;
    }

	/**
     * @return the $username
     */
    public static function getUsername ()
    {
        return self::$username;
    }

	/**
     * @return the $password
     */
    public static function getPassword ()
    {
        return self::$password;
    }

	/**
     * @param string $username
     */
    public function setUsername ($username)
    {
        self::$username = $username;
    }

	/**
     * @param string $password
     */
    public function setPassword ($password)
    {
        self::$password = $password;
    }

	/**
     * @param string $database
     */
    public function setDatabase ($database)
    {
        self::$database = $database;
    }

	/**
     * @param string $host
     */
    public function setHost ($host)
    {
        self::$host = $host;
    }

	/**
     * @param string $schema
     */
    public function setSchema ($schema)
    {
        self::$schema = $schema;
    }

	private static function generate_migrations ()
    {
        $connection = self::getConnection();
        $schema = self::getSchema();
        $tablestmt = $connection->prepare(
                "select table_name
	            from INFORMATION_SCHEMA.TABLES where table_schema = '{$schema}' ORDER BY INFORMATION_SCHEMA.TABLES.table_name ASC");
        $tablestmt->execute();
        $tables = $tablestmt->fetchAll();

        $references_arr = array();
        foreach ($tables as $table) {
            $table_name = $table["table_name"];
            $sth = $connection->prepare(
                    "select column_name, data_type, character_maximum_length, numeric_precision, numeric_scale
                    from INFORMATION_SCHEMA.COLUMNS where table_name = '{$table_name}'");
            $sth->execute();
            $columns = $sth->fetchAll();

            $forbidden_fields = array(
                    'id',
                    'created_at',
                    'updated_at'
            );
            $fields_list = array();
            foreach ($columns as $column) {
                $field_command = null;
                if (! in_array($column["column_name"], $forbidden_fields)) {

                    switch (strtolower($column["data_type"])) {
                        case 'varchar':
                        case 'character varying':
                            $data_type = 'string';
                            break;

                        case 'int':
                        case 'integer':
                        case 'bigint':
                            $data_type = 'integer';
                            break;

                        case 'real':
                        case 'money':
                            $data_type = 'float';
                            break;

                        case 'decimal':
                        case 'numeric':
                            $data_type = 'decimal';
                            if (! empty($column["numeric_precision"])) {
                                $data_type .= '=' . $column["numeric_precision"];
                            }
                            if (! empty($column["numeric_scale"])) {
                                $data_type .= ', ' . $column["numeric_scale"];
                            }
                            break;

                        case 'timestamp':
                        case 'timestamp without time zone':
                            $data_type = 'timestamp';
                            break;

                        case 'time':
                        case 'time without time zone':
                            $data_type = 'timestamp';
                            break;

                        default:
                            $data_type = $column["data_type"];
                            break;
                    }

                    if (! empty($column["character_maximum_length"])) {
                        $data_type .= '=' . $column["character_maximum_length"];
                    }

                    $field_command = $column["column_name"] . ':' . $data_type;
                    $fields_list[] = $field_command;
                }
            }
            Laravel\CLI\Command::run(
                    array(
                            'generate:migration',
                            "create_{$table_name}_table",
                            $fields_list
                    ));
        }
    }

	private static function generate_references() {
		$connection = self::getConnection();
		$database = self::getDatabase();
        $schema = self::getSchema();
        $tablestmt = $connection->prepare(
                "select table_name
	            from INFORMATION_SCHEMA.TABLES where table_schema = '{$schema}' ORDER BY INFORMATION_SCHEMA.TABLES.table_name ASC");
        $tablestmt->execute();
        $tables = $tablestmt->fetchAll();

        $references_arr = array();
        foreach ($tables as $table) {
            $table_name = $table["table_name"];
            $sth = $connection->prepare("
                    SELECT rc.constraint_catalog,
                    rc.constraint_schema||'.'||tc.table_name AS foreign_table_name,
                    ccu.table_name as table_name,
                    kcu.column_name as reference_column_name,
                    ccu.column_name,
                    match_option,
                    update_rule,
                    delete_rule
                    FROM information_schema.referential_constraints AS rc
                    JOIN information_schema.table_constraints AS tc USING(constraint_catalog,constraint_schema,constraint_name)
                    JOIN information_schema.key_column_usage AS kcu USING(constraint_catalog,constraint_schema,constraint_name)
                    JOIN information_schema.key_column_usage AS ccu ON(ccu.constraint_catalog=rc.unique_constraint_catalog AND ccu.constraint_schema=rc.unique_constraint_schema AND ccu.constraint_name=rc.unique_constraint_name)
                    WHERE ccu.table_catalog='{$database}'
                    AND ccu.table_schema='{$schema}'
                    AND ccu.table_name='{$table_name}'
                    ");
               $sth->execute();
               $references = $sth->fetchAll();
               $references_arr = array();

               foreach ($references as $reference) {
                    array_push($references_arr, array(
	                    "table_name" => str_replace("$schema.",	"", $reference["table_name"]),
	                    "reference_table_name" => str_replace("$schema.",	"", $reference["foreign_table_name"]),
	                    "column_name" => $reference["column_name"],
	                    "reference_column_name" => $reference["reference_column_name"],
		    	        "reference_update_rule" => $reference["update_rule"],
		    	        "reference_delete_rule" => $reference["delete_rule"],
    	       		));
               }

				Laravel\CLI\Command::run(array( 'generate:reference', "create_{$table_name}_table", $references_arr));
        }
	}

	public function get_index()
    {
        self::generate_migrations();
        sleep(3);
        self::generate_references();
    }

	public function get_show()
    {

    }

	public function get_edit()
    {

    }

}
