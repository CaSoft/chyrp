<?php
	class QueryBuilder {
		/**
		 * Function: build_update_values
		 * Creates an update data part
		 */
		public static function build_update_values($data) {
			$set = array();

			foreach ($data as $field => $val)
			{
				array_push($set, "`$field` = $val");
			}

			return implode(', ', $set);
		}

		/**
		 * Function: build_insert_header
		 * Creates an insert header part
		 */
		public static function build_insert_header($data) {
			$set = array();

			foreach (array_keys($data) as $field)
				array_push($set, "`$field`");

			return '(' . implode(', ', $set) . ')';
		}

		/**
		 * Function: build_insert_values
		 * Creates an insert data part
		 */
		public static function build_insert_values($data) {
			return '(' . implode(', ', array_values($data)) . ')';
		}

		public static function build_insert($table, $data) {
			return "
				INSERT INTO `$table`
				".self::build_insert_header($data)."
				VALUES
				".self::build_insert_values($data)."
			";
		}

		public static function build_update($table, $conds, $data) {
			return "
				UPDATE `$table`
				SET ".self::build_update_values($data)."
				".($conds ? "WHERE $conds" : "")."
			";
		}

		public static function build_limits($offset, $limit) {
			if ($limit === null)
				return "";
			if ($offset !== null)
				return "LIMIT $offset, $limit";
			return "LIMIT $limit";
		}

		public static function build_from($tables) {
			if (!is_array($tables))
				$tables = array($tables);
			$set = array();
			$sql = SQL::current();
			foreach ($tables as $table) {
				$table = explode(" ", $table);
				$parts = explode(".", $table[0]);
				$parts[0] = $sql->prefix.$parts[0];
				foreach ($parts as & $part)
					$part = "`$part`";
				$table[0] = implode(".", $parts);
				array_push($set, implode(" ", $table));
			}
			return implode(", ", $set);
		}

		public static function build_count($tables, $conds) {
			return "
				SELECT COUNT(1) AS count
				FROM ".self::build_from($tables)."
				".($conds ? "WHERE $conds" : "")."
			";
		}

		public static function build_select_header($fields) {
			if (!is_array($fields))
				$fields = array($fields);
			$set = array();

			$sql = SQL::current();
			foreach ($fields as $field) {
				$field = explode(" ", $field);
				$parts = explode(".", $field[0]);
				foreach ($parts as & $part)
					if ($part != '*')
						$part = "`$part`";
				$field[0] = implode(".", $parts);
				array_push($set, implode(" ", $field));
			}

			return implode(', ', $set);
		}
		public static function build_select($tables, $fields, $conds, $order = null, $limit = null, $offset = null) {
			return "
				SELECT ".self::build_select_header($fields)."
				FROM ".self::build_from($tables)."
				".($conds ? "WHERE $conds" : "")."
				ORDER BY $order
				".self::build_limits($offset, $limit)."
			";
		}
	}

	/**
	 * Class: SQL
	 * Contains the database settings and functions for interacting with the SQL database.
	 */
	class SQL {
		/**
		 * The class constructor is private so there is only one connection.
		 */
		private function __construct() {
			$this->connected = false;
		}
		
		/**
		 * Integer: $queries
		 * Number of queries it takes to load the page.
		 */
		public $queries = 0;
		public $db;
		
		/**
		 * Function: load
		 * Loads a given database YAML file.
		 * 
		 * Parameters:
		 * 	$file - The YAML file to load into <SQL>.
		 */
		public function load($file) {
			$this->yaml = Spyc::YAMLLoad($file);
			foreach ($this->yaml as $setting => $value)
				if (!is_int($setting)) # Don't load the "---"
					$this->$setting = $value;
		}
		
		/**
		 * Function: set
		 * Sets a variable's value.
		 * 
		 * Parameters:
		 * 	$setting - The setting name.
		 * 	$value - The new value. Can be boolean, numeric, an array, a string, etc.
		 */
		public function set($setting, $value) {
			if (isset($this->$setting) and $this->$setting == $value) return false; # No point in changing it
			
			# Add the PHP protection!
			$contents = "<?php header(\"Status: 401\"); exit(\"Access denied.\"); ?>\n";
			
			# Add the setting
			$this->yaml[$setting] = $value;
			
			if (isset($this->yaml[0]) and $this->yaml[0] == "--")
				unset($this->yaml[0]);
			
			# Generate the new YAML settings
			$contents.= Spyc::YAMLDump($this->yaml, false, 0);
			
			$open = fopen(INCLUDES_DIR."/database.yaml.php", "w");
			fwrite($open, $contents);
			fclose($open);
		}
		
		/**
		 * Function: connect
		 * Connects to the SQL database.
		 */
		public function connect($checking = false) {
			$this->load(INCLUDES_DIR."/database.yaml.php");
			if ($this->connected)
				return true;
			try {
				$this->db = new PDO($this->adapter.":host=".$this->host.";".((isset($this->port)) ? "port=".$this->port.";" : "")."dbname=".$this->database, 
				                    $this->username, 
				                    $this->password, array(PDO::ATTR_PERSISTENT => true));
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				if ($this->adapter == "mysql")
					$this->db->query("set names 'utf8';");
				$this->connected = true;
				return true;
			} catch (PDOException $error) {
				$message = preg_replace("/[A-Z]+\[[0-9]+\]: .+ [0-9]+ (.*?)/", "\\1", $error->getMessage());
				return ($checking) ? false : error(__("Database Error"), $message) ;
			}
		}
		
		/**
		 * Function: query
		 * Executes a query and increases <SQL->$queries>.
		 * If the query results in an error, it will die and show the error.
		 */
		public function query($query, $params = array()) {
			$this->queries++;
			try {
				$q = $this->db->prepare($query);
				$result = $q->execute($params);
				if (!$result) throw PDOException();
			} catch (PDOException $error) {
				$message = preg_replace("/[A-Z]+\[[0-9]+\]: .+ [0-9]+ (.*?)/", "\\1", $error->getMessage());
				error(__("Database Error"), $message);
				//throw $error;
			}
			
			return $q;
		}
		
		public function count($tables, $conds, $params = array())
		{
			return $this->query(QueryBuilder::build_count($tables, $conds), $params)->fetchColumn();
		}
		
		public function select($tables, $fields, $conds, $order = null, $params = array(), $limit = null, $offset = null)
		{
			return $this->query(QueryBuilder::build_select($tables, $fields, $conds, $order, $limit, $offset), $params);
		}
		
		/**
		 * Function: quote
		 * Quotes the passed variable as needed for use in a query.
		 */
		public function quote($var) {
			return $this->db->quote($var);
		}
		
		/**
		 * Function: current
		 * Returns a singleton reference to the current connection.
		 */
		public static function & current() {
			static $instance = null;
			if (empty($instance))
				$instance = new self();
			return $instance;
		}
	}
	
	$sql = SQL::current();
