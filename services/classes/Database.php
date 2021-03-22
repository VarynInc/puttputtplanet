<?php /* Database.php is a database abstraction. We set this class up so that no Enginesis
* code has to have specific knowledge of the underlying database driver and services. This
* would hopefully allow use to swap out different database drivers.
* 
* Dependencies:
*   $EnginesisLogger: Global logging service.
*   PDO: PHP must be setup with the PDO service.
*
* TODO: This is a replacement for the global db* functions. Transition all code to use this
* object-oriented methodology instead of the globals.
*
* Overview of methods:
*
* new Database(options, databaseName) : Construct a connection to a database.
* isValid() : Determine if the database connection and state are OK for use.
* query(sqlQuery, parameters) : Run a query and return the result object.
* getLastError(queryResult) : Return error information from the most recent query.
*/

class Database {

    private $currentDBConnection;
    private $connectionName;
    private $lastResult;
    private $sqlDBs;
    private $enginesisLogger = null;
    private $lastStatus;
    private $lastStatusMessage;

    private static $loggingContext = 'DB';
    private static $databaseConnectionTable = [];

    /**
     * Create a database connection only when one is required, as some services won't need
     * a connection and there would be no point to spinning up a database connection in
     * those cases. This uses a global variable so that database connections can be shared
     * across multiple function calls without having to pass it around.
     * 
     * @param string $whichDatabase Specifies which database to use, the default is DATABASE_ENGINESIS.
     * @return Database A database connection. One is created if it does not already exist.
     */
    public static function getDatabaseConnection($whichDatabase = DATABASE_ENGINESIS) {
        global $enginesisLogger;

        $databaseConection = isset(self::$databaseConnectionTable[$whichDatabase]) ? self::$databaseConnectionTable[$whichDatabase] : null;

        if ($databaseConection == null || ! $databaseConection->isValid()) {
            $databaseOptions = null;
            $databaseConection = new Database($databaseOptions, $whichDatabase);
            if ($databaseConection == null ) {
                $enginesisLogger->log('Cannot establish a database connection to ' . $whichDatabase, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
            } else {
                self::$databaseConnectionTable[$whichDatabase] = $databaseConection;
            }
        }
        return $databaseConection;
    }

    /**
     * Construct a new database connection. Fails quietly. Call isValid() to determine if the
     * connection is usable.
     * 
     * @param $serviceOptions {Object} A key/value dictionary of database driver and connection 
     *        parameters.
     * @param $whichDatabase {string} A key that indicates which database to connect to.
     */
    function __construct ($serviceOptions, $whichDatabase = DATABASE_ENGINESIS) {
        global $sqlDBs;
        global $_CERTIFICATES_PATH;

        $this->setLoggingService();
        $this->sqlDBs = $sqlDBs;
        $this->lastStatus = 1;
        $this->lastStatusMessage = '';
        // TODO: turn off warnings so we don't generate crap in the output stream (I cant get this to work anyway)
        $errorLevel = error_reporting();
        if (isset($sqlDBs[$whichDatabase])) {
            $serverStage = serverStage();
            error_reporting($errorLevel & ~E_WARNING);
            $sqlDB = & $sqlDBs[$whichDatabase];
            $certificatePath = $_CERTIFICATES_PATH[$serverStage];
            $dbOptions = [
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            ];
            if ($sqlDB['ssl'] && file_exists($certificatePath)) {
                $dbOptions[PDO::MYSQL_ATTR_SSL_KEY] = $certificatePath . 'client-key.pem';
                $dbOptions[PDO::MYSQL_ATTR_SSL_CERT] = $certificatePath . 'client-cert.pem';
                $dbOptions[PDO::MYSQL_ATTR_SSL_CA] = $certificatePath . 'ca.pem';
                $dbOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            try {
                $this->currentDBConnection = new PDO('mysql:host=' . $sqlDB['host'] . ';dbname=' . $sqlDB['db'] . ';charset=UTF8', $sqlDB['user'], $sqlDB['password'], $dbOptions);
                $this->currentDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->currentDBConnection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $this->connectionName = $whichDatabase;
            } catch(PDOException $e) {
                $this->logMessage('Error exception connecting to server ' . $sqlDB['host'] . ', ' . $sqlDB['user'] . ', ' . $sqlDB['password'] . ', ' .$sqlDB['db'] . ', ' .$sqlDB['port'] . ': ' . $e->getMessage(), LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
            }
            if ($this->currentDBConnection == null) {
                $this->logMessage('Database connection failed: Host=' . $sqlDB['host'] . '; User=' . $sqlDB['user'] . '; Pass=' . $sqlDB['password'] . '; DB=' . $sqlDB['db'] . '; Port=' . $sqlDB['port'], LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
            }
            error_reporting($errorLevel); // put error level back to where it was
        } else {
            $this->logMessage('Error connecting to unknown database ' . $whichDatabase, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
        }
        $this->lastResult = null;
    }

    /**
     * Set a logging service to use in order to log errors or exceptions when using the database service.
     * This is an object that has a public method `log($message)`.
     * 
     * @param object $loggingService A log service object that has  public methond `log($string)`.
     * @return boolean true if the logging service is set.
     */
    public function setLoggingService($loggingService = null) {
        global $enginesisLogger;
        if ($loggingService == null) {
            $this->enginesisLogger = $enginesisLogger;
        } else {
            $this->enginesisLogger = $loggingService;
        }
        return $this->enginesisLogger != null;
    }

    /**
     * Log a message to the logging service.
     * 
     * @param string $message The message to send to the log service.
     * @param integer $level - since this is a bit mask, will log the highest order bit and Informational if no valid bits are set.
     * @param string $subsystem
     * @param string $sourceFile
     * @param integer $lineNumber
     */
    private function logMessage($message, $level = LogMessageLevel::Info, $subsystem = 'DB', $sourceFile = __FILE__, $lineNumber = __LINE__) {
        if ($this->enginesisLogger != null) {
            $this->enginesisLogger->log($message, $level, $subsystem, $sourceFile, $lineNumber);
        }
    }

    /**
     * Determine if the database connection is usable.
     * 
     * @return boolean true if we think we have a valid database connection.
     */
    public function isValid () {
        return $this->currentDBConnection != null && $this->connectionName != null;
    }

    /**
     * Run a query as a prepared statement against the database connection.
     * 
     * @param string $sqlCommand The query string.
     * @param Array $parameters A value parameter array to replace each placeholder 
     *        in the query string.
     * @return Object The database results object that can be used in subsequent commands 
     *        to inquire about the results.
     */
    public function query ($sqlCommand, $parameters = null) {
        $sqlStatement = null;
        $magicOutputParameters = '@success, @status_msg';
    
        if ($this->currentDBConnection != null) {
            $this->lastStatus = -1;
            $this->lastStatusMessage = '';
            if ($parameters == null) {
                $parameters = [];
            } elseif ( ! is_array($parameters)) {
                $this->logMessage("dbQuery invalid parameters for $sqlCommand", LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
                $parameters = [$parameters];
            }
            $parameterCount = count($parameters);
            $hasOutputParameters = stripos($sqlCommand, $magicOutputParameters) !== false && $parameterCount > 0;
            try {
                $sqlStatement = $this->currentDBConnection->prepare($sqlCommand);
                if ($sqlStatement != null) {
                    $sqlStatement->setFetchMode(PDO::FETCH_ASSOC);
                    if ($hasOutputParameters) {
                        // if we find @s,@m in query then bind those parameters to automate retrieving them later.
                        $sqlCommand = str_ireplace($magicOutputParameters, '?, ?', $sqlCommand);
                        $sqlStatement->bindParam($parameterCount + 1, $this->lastStatus, PDO::PARAM_INT, 4); 
                        $sqlStatement->bindParam($parameterCount + 2, $this->lastStatusMessage, PDO::PARAM_STR, 255); 
                    }
                    $sqlStatement->execute($parameters);
                } else {
                    $this->logMessage('failed to create prepared statement from ' . $sqlCommand . ', params ' . implode(',', $parameter), LogMessageLevel::Error, self::$loggingContext,  __FILE__, __LINE__);
                }
            } catch(PDOException $e) {
                $this->logMessage('exception ' . $e->getMessage() . ' for ' . $sqlCommand . ', params ' . implode(',', $parameters), LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
            }
        } else {
            $this->logMessage('called with no DB connection for ' . $sqlCommand, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
        }
        $this->lastResult = $sqlStatement;
        return $sqlStatement;
    }

    /**
     * Execute an SQL statement and return the number of affected rows. Typically, `exec` is used on
     * prepared statements (not queries). It does not accept query parameters.
     * 
     * @param string $sqlCommand string The query string.
     * @return integer The number of rows affected by the SQL statement.
     */
    public function exec ($sqlCommand) {
        return $this->currentDBConnection->exec($sqlCommand);
    }

    /**
     * Clear any unprocessed results pending on the connection. Many times this is required for
     * stored procedures that return more than one result set.
     * 
     * @param Object The database results object returned from a prior query. If null, 
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
     * 
     * @param Object The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return Array|null One row of the result set as a key/value object. The key is the 
     *        attribute name, the value is the column data. Returns null if there was a query error.
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
                $resultSet = $result->fetch(PDO::FETCH_ASSOC);
                if ($resultSet === false) {
                    $resultSet = null;
                    $error = $this->getLastError(null);
                    $this->logMessage('Fetch error ' . $error . ' on ' . $result->queryString, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
                }
            } catch (PDOException $e) {
                if ($result->errorCode() !== 'HY000') {
                    $this->logMessage('Error exception ' . $e->getMessage() . ' on ' . $result->queryString, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
                }
            }
            error_reporting($errorLevel); // put error level back to where it was
        }
        return $resultSet;
    }

    /**
     * Fetch all rows from a query result set.
     * 
     * @param Object The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return Array An array of arrays where each item is one row of the result set as 
     *        a key/value object. The key is the attribute name, the value is the column data.
     */
    public function fetchAll ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        $resultSet = null;
        if ($result != null) {
            try {
                $resultSet = $result->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                if ($result->errorCode() !== 'HY000') {
                    $this->logMessage('Error exception ' . $e->getMessage() . ' on ' . $result->queryString, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
                }
            }
        }
        return $resultSet;
    }
    
    /**
     * Fetch the most recent result from a prior query.
     * 
     * @return Object The database results object representing the most recent query
     *        executed. Null if no results are available.
     */
    public function getLastResult () {
        return $this->lastResult;
    }
    
    /**
     * Fetch the next result in a multi-result stored procedure query.
     * 
     * @param Object The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return Object The database results object representing the next query returned 
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
     * 
     * @return integer The last inserted primary key id.
     */
    public function getLastInsertId () {
        $lastId = 0;
        if ($this->currentDBConnection != null) {
            $lastId = $this->currentDBConnection->lastInsertId();
        }
        return $lastId;
    }

    /**
     * Return the number of rows affected by the last query.
     * 
     * @param Object The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return integer The number of rows affected.
     */
    public function rowCount ($result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        return $result == null ? -1 : $result->rowCount();
    }
    
    /**
     * Return the status and status message pending from the last run
     * stored procedure. This assumes you just ran a stored procedure query,
     * and called fetch or fetchAll on the result.Otherwise you will get
     * whatever was previously on the db connection. You may also need to
     * call clearResults if a prior result set is still pending on the
     * connection.
     * 
     * @param integer $status Reference to a variable to hold the status.
     * @param string $status_msg Reference to a variable to hold the status message.
     * @return boolean true if we think we got a valid result.
     */
    public function getLastEnginesisStatus (& $status, & $status_msg) {
        $updated = false;
        if ($this->lastStatus == -1) {
            $queryResults = $this->query('select @success, @status_msg');
            if ($queryResults) {
                $statusResults = $this->fetch($queryResults);
                if ($statusResults != null) {
                    $this->lastStatus = (int) $statusResults['@success'];
                    $this->lastStatusMessage = $statusResults['@status_msg'];
                    $updated = true;
                }
            }
        }
        $status = $this->lastStatus;
        $status_msg = $this->lastStatusMessage;
        return $updated;
    }

    /**
     * Return the error on a query result or on the database connection handle.
     * 
     * @param Object $db A results object returned from query, or null in which case
     *       the database connection is queried for a pending error.
     * @return string An error code, or null if there was no error pending.
     */
    public function getLastError ($dbOrResult) {
        $errorCode = null;
        if ($dbOrResult != null) {
            $errorInfo = $dbOrResult->errorInfo();
            if ($errorInfo != null && count($errorInfo) > 1 && $errorInfo[1] != 0) {
                if ( ! isLiveServerStage()) {
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
     * 
     * @param Object $db object A results object returned from query, or null in which case
     *       the database connection is queried for a pending error.
     * @return string An error code, or null if there was no error pending.
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
     * 
     * @param string $status_msg A status message returned from an Enginesis stored
     *       procedure query.
     * @return string The nicer string.
     */
    public function errorMessageToNiceString ($status_msg) {
        return strtolower(str_replace('_', ' ', $status_msg));
    }

    /**
     * Record an error report to the database in the hope that the error will get 
     * handled by support. This type of error reporting should only be for errors 
     * that require priority attention. Otherwise use logMessage() to record the
     * error to a log file.
     * 
     * @param integer $site_id Enginesis site reporting the error.
     * @param integer $user_id User on site-id who is reporting the error.
     * @param string $error_code The Enginesis error code. Should be a key in the 
     *       error_codes table.
     * @param string $error_info Additional information about the error.
     * @param integer $object_id An object id that is the subject of the error report. Can be null or 0.
     * @param string $language_code The language code of the user reporting the error.
     */
    public function errorReport ($site_id, $user_id, $error_code, $error_info, $object_id, $language_code = 'en') {
        if ($user_id < 9999) {
            $user_id = 9999;
        }
        $parameters = [$site_id, $user_id, $error_code, $error_info, $object_id, $language_code];
        $sql = 'call ErrorReport(?, ?, ?, ?, ?, ?, @success, @status_msg)';
        $result = $this->query($sql, $parameters);
        if ($result != null) {
            $this->clearResults($result);
        } else {
            $this->logMessage('Error exception ' . $e->getMessage() . ' on ' . $sql, LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
        }
    }

    /**
     * Return the current database connection handle. Could be used if a method not abstracted here
     * needs to be called on for some special purpose. Not sure if this is really useful so it may 
     * get depreciated.
     * 
     * @return Object The current connection handle.
     */
    public function getConnection () {
        return $this->currentDBConnection;
    }

    /**
     * Return field meta information about the referenced column name.
     * 
     * @param integer $fieldIndex 0-based column index in the result set to inquire.
     * @param Object The database results object returned from a prior query. If null, 
     *        the last known query is used.
     * @return Array The metadata for a 0-indexed column in a result set as an associative array.
     */
    public function getFieldInfo($fieldIndex, $result = null) {
        if ($result == null) {
            $result = $this->lastResult;
        }
        return $result == null ? null : $result->getColumnMeta($fieldIndex);
    }

    /**
     * Convert the query results array into an array of objects.
     * 
     * @param string $sqlCommand The query string.
     * @param Array $parametersArray A value parameter array to replace each placeholder 
     *        in the query string.
     * @param Array $returnArray An existing array to update as the result of the query. 
     *        null is allowed.
     * @return Array The array of objects.
     */
    public function getObjectArray($query, $parameters, $returnArray) {
        $result = $this->query($query, $parameters);
        if ($result == null) {
            $this->logMessage('error: ' . $this->getLastError(null) . '<br/>' . $query . '<br/>', LogMessageLevel::Error, self::$loggingContext, __FILE__, __LINE__);
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
    public function close () {
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
