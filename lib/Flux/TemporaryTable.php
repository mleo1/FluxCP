<?php
require_once 'Flux/Error.php';

/**
 * This library provides a means of creating a temporary table in MySQL and
 * populating it with the rows from various other tables.
 *
 * This is particularly useful when you need to merge the data in a
 * destructive manner allowing you to view a result set that has been
 * overridden by following tables.
 *
 * Use-case in Flux would be combining item_db/item_db2 and mob_db/mob_db2.
 */
class Flux_TemporaryTable {
	/**
	 * Connection object used to create table.
	 *
	 * @access public
	 * @var Flux_Connection
	 */
	public $connection;
	
	/**
	 * Temporary table name.
	 *
	 * @access public
	 * @var string
	 */
	public $tableName;
	
	/**
	 * Array of table names to select from and re-populate the temporary table
	 * with, overriding each duplicate record.
	 *
	 * @access public
	 * @var array
	 */
	public $fromTables;
	
	/**
	 * Exception class to raise when an error occurs.
	 *
	 * @static
	 * @access public
	 * @var array
	 */
	public static $exceptionClass = 'Flux_Error';
	
	/**
	 * Create new temporary table.
	 *
	 * @param Flux_Connection $connection
	 * @param string $tableName
	 * @param array $fromTables
	 * @access public
	 */
	public function __construct(Flux_Connection $connection, $tableName, array $fromTables)
	{
		$this->connection = $connection;
		$this->tableName  = $tableName;
		$this->fromTables = $fromTables;
		
		if (empty($fromTables)) {
			self::raise("One or more tables must be specified to import into the temporary table '$tableName'");
		}
		
		// Find the first table.
		reset($this->fromTables);
		$firstTable = current($this->fromTables);
		
		if ($this->create($firstTable)) {
			// Insert initial row set.
			// Rows imported from the following tables should overwrite these rows.
			if (!$this->import($firstTable, false)) {
				self::raise("Failed to import rows from initial table '$firstTable'");
			}
			
			foreach (array_slice($this->fromTables, 1) as $table) {
				if (!$this->import($table)) {
					self::raise("Failed to import/replace rows from table '$table'");
				}
			}
		}
	}
	
	/**
	 * Create actual temporary table in the database.
	 *
	 * @param string $firstTable
	 * @return bool
	 * @access private
	 */
	private function create($firstTable)
	{
		// Attempt to create temporary table.
		$sql = "CREATE TEMPORARY TABLE {$this->tableName} LIKE $firstTable";
		$sth = $this->connection->getStatement($sql);
		$res = $sth->execute();
		
		if (!$res) {
			$message  = "Failed to create temporary table '{$this->tableName}'.\n";
			$message .= sprintf('Error info: %s', print_r($sth->errorInfo(), true));
			self::raise($message);
		}
		
		// Add `origin_table' column.
		$len = $this->findVarcharLength();
		$sql = "ALTER TABLE {$this->tableName} ADD COLUMN origin_table VARCHAR($len) NOT NULL";
		$sth = $this->connection->getStatement($sql);
		$res = $sth->execute();
		
		if (!$res) {
			// Drop first.
			$this->drop();
			
			$message  = "Failed to add `origin_table` column to '{$this->tableName}'.\n";
			$message .= sprintf('Error info: %s', print_r($sth->errorInfo(), true));
			self::raise($message);
		}
		else {
			return true;
		}
	}
	
	/**
	 * Import rows from a specified table into the temporary table, optionally
	 * overwriting duplicate primay key rows.
	 *
	 * @param string $table
	 * @param bool $overwrite
	 * @return bool
	 * @access private
	 */
	private function import($table, $overwrite = true)
	{
		$act = $overwrite ? 'REPLACE' : 'INSERT';
		$sql = "$act INTO $this->tableName SELECT $table.*, '$table' FROM $table";
		$sth = $this->connection->getStatement($sql);
		
		return $sth->execute();
	}
	
	/**
	 * Find the length of the longest table name, which should be used to
	 * determine the length of the VARCHAR field in the temporary table.
	 *
	 * @return int
	 * @access private
	 */
	private function findVarcharLength()
	{
		$length = 0;
		foreach ($this->fromTables as $table) {
			if (($strlen=strlen($table)) > $length) {
				$length = $strlen;
			}
		}
		return $length;
	}
	
	/**
	 * Throw an exception.
	 *
	 * @param string $message
	 * @throws Flux_Error
	 * @access private
	 * @static
	 */
	private static function raise($message = '')
	{
		$class = self::$exceptionClass;
		throw new $class($message);
	}
	
	/**
	 * Drop temporary table.
	 *
	 * @return bool
	 * @access public
	 */
	public function drop()
	{
		$sql = "DROP TEMPORARY TABLE {$this->tableName}";
		$sth = $this->connection->getStatement($sql);
		
		return $sth->execute();
	}
	
	public function __destruct()
	{
		$this->drop();
	}
}
?>