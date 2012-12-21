<?php
namespace CraftBlue;

/**
 * A robust yet simple PHP PDO class for dealing with PDO database connections.
 * Takes care of many of the intricacies and quirks associated with PDO so
 * you don't have to. These problem areas include automatic closing of previous
 * cursors on new query, adding in the ability to reconnect to a database if
 * it has gone away, and also adding the ability to connect to a new database
 * with an existing connection. Inspiration for auto-reconnect came via Digg's
 * PDB class.
 *
 * It's set to use the MySQL driver by default.
 *
 * @package     SimpleSql
 * @author      Corey Ballou <corey@coreyballou.com>
 * @copyright   2012 Corey Ballou <corey@coreyballou.com>
 * @link        http://www.craftblue.com
 */
class SimpleSql {

    /**
     * Specifies the number of retry attempts if we encounter a DB timeout.
     * @var int
     */
    const MAX_RETRIES = 3;

    /**
     * A list of valid fetch constants. This is a subset SS supports.
     * @var array
     */
    protected $_fetchConstants = array(
        \PDO::FETCH_ASSOC => 1,
        \PDO::FETCH_BOTH => 1,
        \PDO::FETCH_NUM => 1,
        \PDO::FETCH_OBJ => 1
    );

    /**
     * Database connection vars.
     */
    private $host;
    private $username;
    private $password;
    private $database;
    private $driver;

    /**
     * Holds the PDO object.
     */
    public $pdo;

    /**
     * Useful set of vars based on the last ran query.
     */
    public $sql;
    public $stmt;
    public $lastInsertId;

    /**
     * Default constructor. Handles initial connection to the database. A list
     * of drivers can be found at http://php.net/manual/en/pdo.drivers.php,
     * however we really only support those
     *
     * @access  public
     * @param   string  $host       The database hostname or IP address
     * @param   string  $username   The username with access to the db
     * @param   string  $password   The password correlating to the username
     * @param   string  $database   The database name to connect to
     * @param   string  $driver     The PDO driver (mysql, ibm, mssql, oracle, postgresql, sqlite)
     * @return  void
     */
    public function __construct($host, $username, $password, $database, $driver = 'mysql')
    {
        // handle connection
        $this->connect($host, $username, $password, $database, $driver);
    }

    /**
     * Handles connecting and reconnecting.
     *
     * @access  public
     * @param   string  $host       The database hostname or IP address
     * @param   string  $username   The username with access to the db
     * @param   string  $password   The password correlating to the username
     * @param   string  $database   The database name to connect to
     * @param   string  $driver     The PDO driver (mysql, ibm, mssql, oracle, postgresql, sqlite)
     * @return  void
     */
    public function connect($host, $username, $password, $database, $driver = 'mysql')
    {
        // store connection settings
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->driver = $driver;

        // use settings to connect
        $this->reconnect();
    }

    /**
     * A simple wrapper function which handles reconnecting to the database
     * based on the existing connection settings.
     *
     * @access  public
     * @return  void
     */
    public function reconnect()
    {
        // close any open cursors
        $this->closeCursor();

        // close a prior connection if one exists
        $this->close();

        // generate the connection string
        $connStr = $this->driver . ':host=' . $this->host . ';dbname=' . $this->database;

        // generate driver specific options
        $opts = NULL;
        if (strtolower($this->driver) == 'mysql') {
            $opts = array(\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8");
        }

        // let any exceptions bubble, not our problem
        $this->pdo = new \PDO(
            $connStr,
            $this->username,
            $this->password,
            $opts
        );

        // trigger exceptions
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Handle changing the database. A wrapper around connect() since there's
     * no way to change connections on the fly.
     *
     * @access  public
     * @param   string  $database
     * @return  void
     */
    public function setDatabase($database)
    {
        // ensure we reset back to defaults
        $this->reset();

        // reconnect
        $this->connect($this->host, $this->username, $this->password, $database);
    }

    /**
     * Standard PDO method for querying. Assumes the user has escaped
     * everything themselves via ->quote(). You should instead by using a prepared
     * statement method, i.e. select, fetchRow, fetchRows, insert, update, or
     * delete.
     *
     * @access  public
     * @param   string  $sql
     * @param   int     $fetch_mode
     * @return  PDOStatement|false
     */
    public function query($sql, $fetch_mode = \PDO::FETCH_ASSOC)
    {
        $this->closeCursor();

        $this->sql = $sql;

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->query($sql, $fetch_mode);
                return $this->stmt;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Standard PDO method for exec, generally used for commands which return
     * rows. Assumes the user has escaped everything themselves via ->quote().
     * Only use this function if you know what you're doing. You should instead
     * by using a prepared statement, i.e. select, fetchRow, fetchRows, insert,
     * update, or delete.
     *
     * @access  public
     * @param   string  $sql
     * @return  PDOStatement|false
     */
    public function exec($sql)
    {
        $this->closeCursor();

        $this->sql = $sql;

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->exec($sql);
                return $this->stmt;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Handles fetching an individual row. The user is responsible for supplying
     * a prepared statement (parameterized query). This means the individual
     * SQL in addition to an array of values (or :key => values).
     *
     * @access  public
     * @param   string  $sql
     * @param   array   $data
     * @param   int     $fetch_mode
     */
    public function fetchRow($sql, $data = NULL, $fetch_mode = \PDO::FETCH_ASSOC)
    {
        $this->closeCursor();

        $this->sql = $sql;

        if ($data !== null) {
            if (is_array($data)) {
                $data = array_values($data);
            } else {
                $data = (array) $data;
            }
        }

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->prepare($sql);
                $this->stmt->setFetchMode($this->_validFetchMode($fetch_mode));

                if ($this->stmt->execute($data)) {
                    return $this->stmt->fetch();
                }

                return FALSE;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Handles fetching rows. The user is responsible for supplying
     * a prepared statement (parameterized query). This means the individual
     * SQL in addition to an array of values (or :key => values).
     *
     * @access  public
     * @param   string  $sql
     * @param   array   $data
     * @param   int     $fetch_mode
     */
    public function fetchRows($sql, $data = NULL, $fetch_mode = \PDO::FETCH_ASSOC)
    {
        $this->closeCursor();
        $this->sql = $sql;
        $data = $this->_fixData($data);

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->prepare($sql);
                $this->stmt->setFetchMode($this->_validFetchMode($fetch_mode));

                if ($this->stmt->execute($data)) {
                    return $this->stmt;
                }

                return FALSE;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Handle insertion. Returns the last insert id on success, FALSE on failure.
     *
     * @access  public
     * @param   string  $table
     * @param   array   $data
     * @return  int|bool
     */
    public function insert($table, array $data)
    {
        $this->closeCursor();

        $sql = 'INSERT INTO ' . $table . ' SET ';

        $data = $this->_fixData($data);
        if (!$this->_isAssociative($data)) {
            throw new Exception('Insert requires $data to be associative.');
        }

        // handle data portion
        $dataSql = array();
        foreach ($data as $key => $val) {
            if (strpos($key, ':') === 0) {
                $dataSql[] = substr($key, 1) . ' = ' . $key;
            } else {
                $dataSql[] = $key . ' = :' . $key;
            }
        }
        $sql .= implode(",\n", $dataSql);
        $this->sql = $sql;

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->prepare($sql);
                if ($this->stmt->execute($data)) {
                    $this->lastInsertId = $this->pdo->lastInsertId();
                    return $this->lastInsertId;
                }

                return FALSE;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Handle update. We need associative arrays for both $data and $where.
     *
     * @access  public
     * @param   string  $table
     * @param   array   $data
     * @param   array   $where
     * @return  int|bool
     */
    public function update($table, array $data, $where = array())
    {
        $this->closeCursor();

        // generate SQL
        $sql = 'UPDATE ' . $table . ' SET ';

        $data = $this->_fixData($data);
        $where = $this->_fixData($where);

        // handle data portion
        $dataSql = array();
        foreach ($data as $key => $val) {
            if (strpos($key, ':') === 0) {
                $dataSql[] = substr($key, 1) . ' = ' . $key;
            } else {
                $dataSql[] = $key . ' = :' . $key;
            }
        }
        $sql .= implode(",\n", $dataSql);

        // handle where clause
        if (!empty($where)) {
            if (!$this->_isAssociative($data) || !$this->_isAssociative($where)) {
                throw new Exception('Update requires $data and $where to be associative.');
            }

            // merge data for prepared statement
            $data = array_merge($data, $where);

            // generate where SQL
            $whereClause = array();
            foreach ($where as $key => $val) {
                if (strpos($key, ':') === 0) {
                    $whereClause[] = substr($key, 1) . ' = ' . $key;
                } else {
                    $whereClause[] = $key . ' = :' . $key;
                }
            }
            $sql .= implode(' AND ', $whereClause);
        }

        $this->sql = $sql;

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->prepare($sql);
                if ($this->stmt->execute($data)) {
                    return $this->count();
                }

                return FALSE;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Handles deletion. Assumes the table name is developer supplied as it is not
     * sanitized. Do not allow for dynamic, user-supplied table names as they
     * are unsafe. The $where clause is assumed to be an array of key => value
     * pairs formatted to match table column names. SimpleSql automatically
     * turns these into a prepared statement of :key => val pairs.
     *
     * @access  public
     * @param   string  $table
     * @param   array   $where
     * @return  int|bool
     */
    public function delete($table, array $where = array())
    {
        $this->closeCursor();

        // generate SQL
        $sql = 'DELETE FROM ' . $table . ' WHERE ';

        if (!empty($where)) {
            if (!$this->_isAssociative($where)) {
                throw new Exception('Delete requires $where to be associative.');
            }

            $whereClause = array();
            foreach ($where as $key => $val) {
                if (strpos($key, ':') === 0) {
                    $whereClause[] = substr($key, 1) . ' = ' . $key;
                } else {
                    $whereClause[] = $key . ' = :' . $key;
                }
            }
            $sql .= implode(' AND ', $whereClause);
        }

        $this->sql = $sql;
        $where = $this->_fixData($where);

        // track attempts
        $attempts = 0;

        do {

            try {

                $this->stmt = $this->pdo->prepare($sql);
                if ($this->stmt->execute($where)) {
                    return $this->count();
                }

                return FALSE;

            } catch (Exception $e) {
                if (strpos($e->getMessage(), '2006 MySQL') !== false) {
                    $this->reconnect();
                } else {
                    throw $e;
                }
            }

        } while ($attempts++ < self::MAX_RETRIES);

        throw new Exception('Exhausted retries on timed out DB connection.');
    }

    /**
     * Returns the number of rows affected by the last DELETE, INSERT, or UPDATE.
     * If the last SQL statement executed by the associated PDOStatement was a
     * SELECT statement, some databases may return the number of rows returned
     * by that statement. However, this behaviour is not guaranteed for all
     * databases and should not be relied on for portable applications.
     *
     * @access  public
     * @return  int
     */
    public function count()
    {
        if (!empty($this->stmt)) {
            return $this->stmt->rowCount();
        }

        return 0;
    }

    /**
     * Quote a string for prevention of SQL injection. To be used with
     * ->query(). Usage of this method in combination with query() is
     * not recommended. You should instead by using a prepared statement
     * method, i.e. select, fetchRow, fetchRows, insert, update, or delete.
     *
     * @access  public
     * @param   string  $string
     * @param   int     $parameter_type
     */
    public function quote($string, $parameter_type = \PDO::PARAM_STR)
    {
        return $this->pdo->quote($string, $parameter_type);
    }

    /**
     * Begin a transaction.
     *
     * @access  public
     * @return  void
     */
    public function beginTransaction()
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    /**
     * Handles committing an existing transaction.
     *
     * @access  public
     * @return  void
     */
    public function commit()
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /**
     * Allow for transaction rollbacks.
     *
     * @access  public
     * @return  void
     */
    public function rollback()
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * A wrapper around beginTransaction.
     *
     * @access  public
     * @return  void
     */
    public function startTransaction()
    {
        $this->beginTransaction();
    }

    /**
     * A wrapper method for committing a transaction.
     *
     * @access  public
     * @return  void
     */
    public function endTransaction()
    {
        $this->commit();
    }

    /**
     * PDO doesn't play nicely with unfetched rows, i.e. if you ran `fetchRows`
     * and ended your loop early and then tried another query. This would mean
     * the PDOStatement object was left in a state with unfetched rows. To fix
     * this little problem, we need to ensure that we close the cursor prior
     * to performing any new queries.
     *
     * @access  public
     * @return  void
     */
    public function closeCursor()
    {
        if ($this->stmt) {
            $this->stmt->closeCursor();
        }
    }

    /**
     * Handles closing the PDO connection.
     *
     * @access  public
     * @return  void
     */
    public function close()
    {
        $this->pdo = null;
    }

    /**
     * Reset the class back to it's initial state. Mainly used in conjunction
     * with changing the database connection.
     *
     * @access  public
     * @return  void
     */
    public function reset()
    {
        $this->sql = NULL;
        $this->stmt = NULL;
        $this->lastInsertId = NULL;
    }

    /**
     * Given a data value (or values) to be used with a parameterized query,
     * ensure that we return the data in an appropriate format.
     *
     * @access  public
     * @param   mixed   $data
     * @return  mixed
     */
    protected function _fixData($data)
    {
        if ($data !== null) {
            if (is_array($data)) {
                if (!$this->_isAssociative($data)) {
                    $data = array_values($data);
                }
            } else {
                $data = (array) $data;
            }
        }

        return $data;
    }

    /**
     * Checks if a given array is associative or not.
     *
     * @access  protected
     * @param   array       $arr
     * @return  bool
     */
    protected function _isAssociative($arr)
    {
        return (bool) count(array_filter(array_keys($arr), 'is_string'));
    }

    /**
     * Ensure we are using a valid fetch mode. Defaults to associative.
     *
     * @access  protected
     * @param   int     $fetch_mode
     * @return  int
     */
    protected function _validFetchMode($fetch_mode)
    {
        if (isset($this->_fetchConstants[$fetch_mode])) {
            $fetch_mode;
        }

        return \PDO::FETCH_ASSOC;
    }

    /**
     * Handles closing the PDO connection.
     *
     * @access  public
     * @return  void
     */
    public function __destruct()
    {
        $this->pdo = null;
    }
}
