<?php /* Database.php is a database abstraction. We set this class up so that no Enginesis
* code has to have specific knowledge of the underlying database driver and services. This
* would hopefully allow use to swap out different database drivers.
*
*/

class Database {

    private $currentDBConnection;
    private $connectionName;
    private $lastResult;
    private $sqlDBs;
    private $enginesisLogger;


    /**
     * Construct a new database connection. Fails quietly. Call isValid() to determine if the
     * connection is usable.
     * @param $serviceOptions {Object} A key/value dictionary of database driver and connection 
     *        parameters.
     * @param $whichDatabase {string} A key that indicates which database to connect to.
     */
    function __construct ($serviceOptions, $whichDatabase = ACTIVE_DATABASE) {
        global $sqlDBs;
        global $enginesisLogger;

        $this->sqlDBs = $sqlDBs;
        $this->enginesisLogger = $enginesisLogger;
        // TODO: turn off warnings so we don't generate crap in the output stream (I cant get this to work anyway)
        $errorLevel = error_reporting();
        if (isset($sqlDBs[$whichDatabase])) {
            error_reporting($errorLevel & ~E_WARNING);
            $sqlDB = & $sqlDBs[$whichDatabase];
            try {
                $this->currentDBConnection = new PDO('mysql:host=' . $sqlDB['host'] . ';dbname=' . $sqlDB['db'] . ';charset=UTF8', $sqlDB['user'], $sqlDB['password']);
                $this->currentDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->currentDBConnection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $this->connectionName = $whichDatabase;
            } catch(PDOException $e) {
                $enginesisLogger->log('Error exception connecting to server ' . $sqlDB['host'] . ', ' . $sqlDB['user'] . ', ' . $sqlDB['password'] . ', ' .$sqlDB['db'] . ', ' .$sqlDB['port'] . ': ' . $e->getMessage(), LogMessageLevel::Error, 'DB', __FILE__, __LINE__);
            }
            if ($this->currentDBConnection == null) {
                $enginesisLogger->log('Database connection failed: Host=' . $sqlDB['host'] . '; User=' . $sqlDB['user'] . '; Pass=' . $sqlDB['password'] . '; DB=' . $sqlDB['db'] . '; Port=' . $sqlDB['port'], LogMessageLevel::Error, 'DB', __FILE__, __LINE__);
            }
            error_reporting($errorLevel); // put error level back to where it was
        } else {
            $enginesisLogger->log('Error connecting to unknown database ' . $whichDatabase, LogMessageLevel::Error, 'DB', __FILE__, __LINE__);
        }
        $this->lastResult = null;
    }

    /**
     * Determine if the database connection is usable.
     * @return {boolean} true if we think we have a valid database connection.
     */
    public function isValid () {
        return $this->currentDBConnection != null && $this->connectionName != null;
    }

    /**
     * Run a query against the database connection.
     * @param $sqlCommand {string} The query string.
     * @param $parametersArray {Array} A value parameter array to replace each placeholder 
     *        in the query string.
     * @return {Object} The database results object that can be used in subsequent commands 
     *        to inquire about the results.
     */
    public function query ($sqlCommand, $parametersArray = null) {
        $sqlStatement = null;
    
        if ($this->currentDBConnection != null) {
            if ($parametersArray == null) {
                $parametersArray = [];
            }
            if ( ! is_array($parametersArray)) {
                $enginesisLogger->log("dbQuery invalid query with $sqlCommand", LogMessageLevel::Error, 'DB', __FILE__, __LINE__);
                $parametersArray = [$parametersArray];
            }
            try {
                $sqlStatement = $this->currentDBConnection->prepare($sqlCommand);
                $sqlStatement->setFetchMode(PDO::FETCH_BOTH);
                $sqlStatement->execute($parametersArray);
            } catch(PDOException $e) {
                reportError('exception ' . $e->getMessage() . ' for ' . $sqlCommand . ', params ' . implode(',', $parametersArray), __FILE__, __LINE__, 'dbQuery');
            }
        } else {
            reportError('called with no DB connection for ' . $sqlCommand, __FILE__, __LINE__, 'dbQuery');
        }
        $this->lastResult = $sqlStatement;
        return $sqlStatement;
    }

    /**
     * Run a query against the database connection. There's no reason to call this method, use 
     * query instead.
     * @param $sqlCommand {string} The query string.
     * @param $parametersArray {Array} A value parameter array to replace each placeholder in 
     *        the query string.
     * @return {Object} The database results object that can be used in subsequent commands to 
     *        inquire about the results.
     */
    public function exec ($sqlCommand, $parametersArray = null) {
        return $this->query($sqlCommand, $parametersArray);
    }

    /**
     * Clear any unprocessed results pending on the connection. Many times this is required for
     * stored procedures that return more than one result set.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     */
    public function clearResults ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        if ($result != null) {
            $result->closeCursor();
        }
    }

    /**
     * Fetch a single row from a query result set.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return {Array} One row of the result set as a key/value object. The key is the 
     *        attribute name, the value is the column data.
     */
    public function fetch ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        $resultSet = null;
        if ($result != null) {
            // TODO: turn off warnings so we don't generate crap in the output stream (I cant get this to work anyway)
            $errorLevel = error_reporting();
            error_reporting($errorLevel & ~E_WARNING);
            try {
                $resultSet = $result->fetch(PDO::FETCH_BOTH);
            } catch (PDOException $e) {
                if ($result->errorCode() !== 'HY000') {
                    reportError('Error exception ' . $e->getMessage() . ' on ' . $result->queryString, __FILE__, __LINE__, 'dbFetch');
                }
            }
            error_reporting($errorLevel); // put error level back to where it was
        }
        return $resultSet;
    }

    /**
     * Fetch all rows from a query result set.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return {Array} An array of arrays where each item is one row of the result set as 
     *        a key/value object. The key is the attribute name, the value is the column data.
     */
    public function fetchAll ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        $resultSet = null;
        if ($result != null) {
            try {
                $resultSet = $result->fetchAll(PDO::FETCH_BOTH);
            } catch (PDOException $e) {
                if ($result->errorCode() !== 'HY000') {
                    reportError('Error exception ' . $e->getMessage() . ' on ' . $result->queryString, __FILE__, __LINE__, 'dbFetchAll');
                }
            }
        }
        return $resultSet;
    }
    
    /**
     * Fetch the most recent result from a prior query.
     * @return {Object} The database results object representing the most recent query
     *        executed. Null if no results are available.
     */
    public function getLastResult () {
        return $this->lastResult;
    }
    
    /**
     * Fetch the next result in a multi-result stored procedure query.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return {Object} The database results object representing the next query returned 
     *        from a prior query. Null if no more results are available.
     */
    public function nextResult ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        return $result == null ? null : $result->nextRowset();
    }

    /**
     * Return the last inserted id for a auto-increment primary key. Usually called after 
     * a query that performs an INSERT operation.
     * @return {int} The last inserted primary key id.
     */
    public function getLastInsertId () {
        $lastId = 0; // error
        if ($this->currentDBConnection != null) {
            $lastId = $this->currentDBConnection->lastInsertId();
        }
        return $lastId;
    }

    /**
     * Return the number of rows affected by the last query.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return {int} The number of rows affected.
     */
    public function rowCount ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        return $result == null ? null : $result->rowCount();
    }
    
    /**
     * Return the status and status message pending from that last run
     * stored procedure. This assumes you just ran a stored procedure query, 
     * otherwise you will get whatever was previously on the db connection. 
     * You may also need to call clearResults if a prior result set is still 
     * pending on the connection.
     * @param $status {int} Reference to a variable to hold the status.
     * @param $status_msg {string} Reference to a variable to hold the status message.
     * @return {boolean} true if we think we got a valid result.
     */
    public function getLastEnginesisStatus (& $status, & $status_msg) {
        $rc = false;
        $queryResults = $this->query('select @success, @status_msg');
        if ($queryResults) {
            $statusResults = $this->fetch($queryResults);
            if ($statusResults != null) {
                $status = $statusResults['@success'];
                $status_msg = $statusResults['@status_msg'];
                $rc = true;
            }
        }
        return $rc;
    }

    /**
     * Return the error on a query result or on the database connection handle.
     * @param $db {object} A results object returned from query, or null in which case
     *       the database connection is queried for a pending error.
     * @return {string} An error code, or null if there was no error pending.
     * TODO: dbError($result) => $db->getLastError($result)
     */
    public function getLastError ($dbOrResult) {
        $errorCode = null;
        if ($dbOrResult != null) {
            $errorInfo = $dbOrResult->errorInfo();
            if ($errorInfo != null && count($errorInfo) > 1 && $errorInfo[1] != 0) {
                if ( ! isLive()) {
                    $errorCode = $errorInfo[0] . ': (' . $errorInfo[1] . ') ' . $errorInfo[2];
                } else {
                    $errorCode = $errorInfo[2];
                }
            }
        } else {
            if ($this->currentDBConnection == null) {
                // general error no database connection
                $errorCode = 'SYSTEM_ERROR';
            } else {
                $errorCode = $this->getLastError($this->currentDBConnection);
            }
        }
        return $errorCode;
    }

    /**
     * Return the error on a query result or on the database connection handle.
     * @param $db {object} A results object returned from query, or null in which case
     *       the database connection is queried for a pending error.
     * @return {string} An error code, or null if there was no error pending.
     * TODO: dbErrorCode($result) => $db->getLastErrorCode($result)
     */
    public function getLastErrorCode ($dbOrResult) {
        $errorCode = null;
        if ($dbOrResult != null) {
            $errorCode = $dbOrResult->errorCode();
            if ($errorCode == '' || $errorCode == '00000') {
                $errorCode = null;
            }
        } else {
            if ($this->currentDBConnection == null) {
                $errorCode = '08001'; // no database connection
            } else {
                $errorCode = $this->getLastErrorCode($this->currentDBConnection);
            }
        }
        return $errorCode;
    }

    /**
     * Transform the Enginesis database error message into something human readable.
     * Enginesis error messages are formatted like "ERROR_NOT_DEFINED". This function
     * takes that and transforms it into "error not defined".
     * @param $status_msg {string} A status message returned from an Enginesis stored
     *       procedure query.
     * @return {string} The nicer string.
     */
    public function errorMessageToNiceString ($status_msg) {
        return strtolower(str_replace('_', ' ', $status_msg));
    }

    /**
     * Record an error report to the database in the hope that the error will get 
     * handled by support. This type of error reporting should only be for errors 
     * that require priority attention. Otherwise use the reportError() or
     * $enginesisLogger->log() functions to record the error to a log file.
     * @param $site_id {int} Enginesis site reporting the error.
     * @param $user_id {int} User on site-id who is reporting the error.
     * @param $error_code {string} The Enginesis error code. Should be a key in the 
     *       error_codes table.
     * @param $error_info {string} Additional information about the error.
     * @param $object_id {int} An object id that is the subject of the error report. Can be null or 0.
     * @param string $language_code {string} The language code of the user reporting the error.
     */
    public function errorReport ($site_id, $user_id, $error_code, $error_info, $object_id, $language_code = 'en') {
        if ($user_id < 9999) {
            $user_id = 9999;
        }
        $parameters = [$site_id, $user_id, $error_code, $error_info, $object_id, $language_code];
        $sql = 'call ErrorReport(?, ?, ?, ?, ?, ?, @success, @status_msg)';
        $result = $this->query($sql, $parameters);
        if ($result != null) {

        } else {
            reportError('Error exception ' . $e->getMessage() . ' on ' . $sql, __FILE__, __LINE__, 'errorReport');
        }
    }

    /**
     * Return the current database connection handle. Could be used if a method not abstracted here
     * needs to be called on for some special purpose. Not sure if this is really useful so it may 
     * get depreciated.
     * @return {Object} The current connection handle.
     */
    public function getConnection () {
        return $this->currentDBConnection;
    }

    /**
     * Return field meta information about the referenced column name.
     * @param $fieldIndex {int} 0-based column index in the result set to inquire.
     * @param {Object} The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return {Array} The metadata for a 0-indexed column in a result set as an associative array.
     */
    public function getFieldInfo($fieldIndex, $result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        return $result == null ? null : $result->getColumnMeta($fieldIndex);
    }

    /**
     * Convert the query results array into an array of objects.
     * @param $sqlCommand {string} The query string.
     * @param $parametersArray {Array} A value parameter array to replace each placeholder 
     *        in the query string.
     * @param $returnArray {Array} An existing array to update as the result of the query. 
     *        null is allowed.
     * @return {Array} The array of objects.
     */
    public function getObjectArray($query, $parameters, $returnArray) {
        $result = $this->query($query, $parameters);
        if ($result == null) {
            reportError( 'error: ' . dbError($lastDBConnection) . '<br/>' . $query . '<br/>', __FILE__, __LINE__, 'dbGetObjectArray');
        } else {
            if (! is_array($returnArray)) {
                $returnArray = [];
            }
            $numberOfRows = $this->rowCount($result);
            for ($i = 0; $i < $numberOfRows; $i ++ ) {
                $row = $this->fetch($result);
                $rowAsObject = ((object)NULL);
                foreach ($row as $key => $value) {
                    $rowAsObject->{$key} = $value;
                }
                $returnArray[$i] = $rowAsObject;
            }
        }
        return $returnArray;
    }

    /**
     * Close all open handles and mark this object as invalid.
     */
    public function close ($whichDatabaseConnection = null) {
        $this->sqlDBs = null;
        $this->enginesisLogger = null;
        $this->currentDBConnection = null;
        $this->connectionName = null;
        $this->lastResult = null;
    }

    function __destruct () {
        $this->close();
    }
}