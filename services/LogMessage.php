<?php
/**
 * A logging system for PHP to abstract the details of logging messages on the server.
 * @author: jf
 * @date: 9/2/2017
 */

abstract class LogMessageLevel {
    const None = 0;
    const Info = 1;
    const Information = 1;
    const Warn = 2;
    const Warning = 2;
    const Err = 4;
    const Error = 4;
    const Critical = 8;
    const All = 255;
}

class LogMessage
{
    private $_activeLogFileName;
    private $_logFilePrefix;
    private $_logFilePath;
    private $_logToFile;
    private $_logToOutput;
    private $_logToURL;
    private $_logToSocket;
    private $_serverStage;
    private $_active;
    private $_logLevel;
    private $_isValid;

    function __construct ($loggingConfiguration) {
        $this->_isValid = true;
        $this->_configure($loggingConfiguration);
        $this->_setLogFileName();
    }

    private function _setLogFileName() {
        $this->_activeLogFileName = $this->_logFilePath . $this->_logFilePrefix . date('ymd') . '.log';
    }

    /**
     * Determine a default place on the server where log files should go. Requires the WWW service
     * has writable permissions.
     * @return string
     */
    private static function _defaultFileWritablePath () {
        // determines where there is a secured writable area we can manipulate file storage
        // Because this is a static function we don't yet have our constant defined SERVER_DATA_PATH
        $logSubPath = '/data/logs/';
        if ( ! empty($_SERVER['DOCUMENT_ROOT'])) {
            // when running on a web server, we're in the public folder, go up one level.
            $serverRootPath = dirname($_SERVER['DOCUMENT_ROOT']);
        } else {
            // When not running on a web server, we have to find the root folder.
            // This will only work if running from a folder within the project.
            $serverRootPath = null;
            $currentDirectory = __DIR__;
            $expectedBaseFolder = 'enginesis';
            $checkLength = strlen($expectedBaseFolder);
            while ($currentDirectory != '') {
                $currentFolder = basename($currentDirectory);
                if (strncmp(strtolower($currentFolder), $expectedBaseFolder, $checkLength) === 0) {
                    $serverRootPath = $currentDirectory;
                    break;
                } elseif (strlen($currentFolder) < 1 || $currentFolder == DIRECTORY_SEPARATOR || $currentFolder == $currentDirectory) {
                    $serverRootPath = '.';
                    break;
                } else {
                    $nextDirectory = dirname($currentDirectory);
                    if ($nextDirectory == $currentDirectory) {
                        $serverRootPath = '.';
                        break;
                    } else {
                        $currentDirectory = $nextDirectory;
                    }
                }
            }
            if ($serverRootPath == null) {
                $serverRootPath = '.';
            }
        }
        return $serverRootPath . $logSubPath;
    }

    /**
     * Set a default value to prefix all log files.
     * @return string
     */
    private static function _defaultFilePrefix () {
        if ( ! defined('ENGINESIS_SITE_KEY')) {
            define('ENGINESIS_SITE_KEY', 'enginesis');
        }
        return ENGINESIS_SITE_KEY;
    }

    /**
     * Route the log message to the configured log file.
     * @param $message
     */
    private function _logToFile ($message) {
        $setFilePermissions = false;
        try {
            if ( ! file_exists($this->_activeLogFileName)) {
                $setFilePermissions = true;
            }
            $logfile = fopen($this->_activeLogFileName, 'ab');
            if ($logfile) {
                fwrite($logfile, "$message\r\n");
                fclose($logfile);
                if ($setFilePermissions) {
                    chmod($this->_activeLogFileName, 0666);
                }
            } else {
                $this->_isValid = false;
                $phpError = error_get_last()['message'];
                error_log("LogMessage file system error $phpError on $this->_activeLogFileName: $message\n");
            }
        } catch (Exception $e) {
            $this->_isValid = false;
            $phpError = error_get_last()['message'];
            error_log("LogMessage.logToFile exception $phpError on $this->_activeLogFileName: $message\n");
        }
    }

    /**
     * Route the log message to the PHP output buffer.
     * @param $message
     */
    private function _logToOutput ($message) {
        echo("<pre>$message</pre>");
    }

    /**
     * Route the log message to the URL configured.
     * @param $message
     */
    private function _logToURL ($message) {
    }

    /**
     * Route the log message to the socket configured.
     * @param $message
     */
    private function _logToSocket ($message) {
    }

    /**
     * Route the log message to the database connection.
     * @param $message
     */
    private function _logToDatabase ($message) {
    }

    /**
     * Format a log message given the parameters.
     * @param $message
     * @param $level
     * @param $subsystem
     * @param $sourceFile
     * @param $lineNumber
     * @return string
     */
    private function _formatLogMessage ($message, $level, $subsystem, $sourceFile, $lineNumber) {
        $delimiter = ' | ';
        $timeNow = date('ymd H:i:s');
        if (isEmpty($level)) {
            $levelMessage = 'I   ';
        } else {
            if (($level & LogMessageLevel::Critical) === LogMessageLevel::Critical) {
                $levelMessage = 'C***';
            } elseif (($level & LogMessageLevel::Error) === LogMessageLevel::Error) {
                $levelMessage = 'E * ';
            } elseif (($level & LogMessageLevel::Warning) === LogMessageLevel::Warning) {
                $levelMessage = 'W * ';
            } else {
                $levelMessage = 'I   ';
            }
        }
        $levelMessage .= ' ' . $timeNow;
        if ( ! isEmpty($subsystem)) {
            $levelMessage .= '[' . $subsystem . ']';
        }
        $levelMessage .= $delimiter . $message;
        if ( ! isEmpty($sourceFile)) {
            $levelMessage .= $delimiter . $sourceFile;
            if ( ! isEmpty($lineNumber)) {
                $levelMessage .= '#' . $lineNumber;
            }
        }
        return $levelMessage;
    }

    /**
     * Validate a value passed in boolean true or false. If it cannot figure it out then returns false.
     * @param $value
     * @return bool
     */
    private function castToBoolean($value) {
        if (is_string($value)) {
            $value = strtolower($value);
            $value = $value === '1' || $value === 't' || $value === 'true' || $value === 'y' || $value === 'yes' || $value === 'checked';
        } elseif (is_numeric($value)) {
            $value = $value !== 0;
        } elseif ( ! is_bool($value)) {
            $value = false;
        }
        return $value;
    }

    /**
     * Check if any bits in the current log_level are represented in the provided level.
     * @param $level {int} logging level bits to check against $_logLevel
     * @return bool true if any bit matches
     */
    private function levelMatch($level) {
        return ((int) $this->_logLevel & (int) $level) > 0;
    }

    /**
     * Update our object state based on configuration options provided.
     * @param $loggingConfiguration {array} key/value pairs described by allConfigurationOptions()
     */
    private function _configure($loggingConfiguration) {
        if (isset($loggingConfiguration['stage'])) {
            $stage = $loggingConfiguration['stage'];
            if (strlen($stage) > 2) {
                // if it does not start with -? and it is longer than 2 chars then assume it is a host name
                $stage = serverStage($stage);
            }
        } else {
            $stage = serverStage();
        }
        $this->_serverStage = $stage;
        if (isset($loggingConfiguration['log_active'])) {
            $this->_active = $this->castToBoolean($loggingConfiguration['log_active']);
        } else {
            $this->_active = true;
        }
        if (isset($loggingConfiguration['log_level'])) {
            $this->_logLevel = $loggingConfiguration['log_level'];
        } else {
            $this->_logLevel = LogMessageLevel::All;
        }
        if (isset($loggingConfiguration['log_to_output'])) {
            $this->_logToOutput = $this->castToBoolean($loggingConfiguration['log_to_output']);
        } else {
            $this->_logToOutput = false;
        }
        if (isset($loggingConfiguration['log_to_url'])) {
            $this->_logToURL = $loggingConfiguration['log_to_url'];
        } else {
            $this->_logToURL = '';
        }
        if (isset($loggingConfiguration['log_to_socket'])) {
            $this->_logToSocket = $loggingConfiguration['log_to_socket'];
        } else {
            $this->_logToSocket = 0;
        }
        if (isset($loggingConfiguration['log_file_prefix'])) {
            $this->_logFilePrefix = $loggingConfiguration['log_file_prefix'];
        } else {
            $this->_logFilePrefix = LogMessage::_defaultFilePrefix() . $stage . '-php-';
        }
        if (isset($loggingConfiguration['log_file_path'])) {
            $this->_logFilePath = $loggingConfiguration['log_file_path'];
        } else {
            $this->_logFilePath = LogMessage::_defaultFileWritablePath();
        }
        if (isset($loggingConfiguration['log_to_file'])) {
            $this->_logToFile = $this->castToBoolean($loggingConfiguration['log_to_file']);
        } else {
            $this->_logToFile = ! isEmpty($this->_logFilePath) || ! isEmpty($this->_logFilePrefix);
        }
        if ( ! isEmpty($this->_logFilePath)) {
            @mkdir($this->_logFilePath, 0770);
        }
    }

    /**
     * Update our object state based on configuration options provided without affecting the current state
     * of unmentioned options.
     * @param $loggingConfiguration {array} key/value pairs described by allConfigurationOptions()
     */
    public function setConfigurationParameters($loggingConfiguration) {
        $logToPathChanged = false;

        if (isset($loggingConfiguration['stage'])) {
            $this->_serverStage = $loggingConfiguration['stage'];
        }
        if (isset($loggingConfiguration['log_active'])) {
            $this->_active = $this->castToBoolean($loggingConfiguration['log_active']);
        }
        if (isset($loggingConfiguration['log_level'])) {
            $this->_logLevel = $loggingConfiguration['log_level'];
        }
        if (isset($loggingConfiguration['log_to_output'])) {
            $this->_logToOutput = $this->castToBoolean($loggingConfiguration['log_to_output']);
        }
        if (isset($loggingConfiguration['log_to_url'])) {
            $this->_logToURL = $loggingConfiguration['log_to_url'];
        }
        if (isset($loggingConfiguration['log_to_socket'])) {
            $this->_logToSocket = $loggingConfiguration['log_to_socket'];
        }
        if (isset($loggingConfiguration['log_file_prefix'])) {
            $this->_logFilePrefix = $loggingConfiguration['log_file_prefix'];
            $logToPathChanged = true;
        }
        if (isset($loggingConfiguration['log_file_path'])) {
            $this->_logFilePath = $loggingConfiguration['log_file_path'];
            $logToPathChanged = true;
        }
        if (isset($loggingConfiguration['log_to_file'])) {
            $this->_logToFile = $this->castToBoolean($loggingConfiguration['log_to_file']);
            $logToPathChanged = true;
        }
        if ($logToPathChanged) {
            if (!isEmpty($this->_logFilePath)) {
                @mkdir($this->_logFilePath, 0770);
            }
            $this->_setLogFileName();
        }
    }

    /**
     * A helper function to return an array of all possible configuration options. This helps a caller
     * determine what the possible options are, their exact keys and sample values.
     * @return array
     */
    public static function allConfigurationOptions() {
        $configuration = [
            'log_active' => true,
            'log_level' => LogMessageLevel::All,
            'log_to_output' => true,
            'log_to_file' => true,
            'log_file_path' => LogMessage::_defaultFileWritablePath(),
            'log_file_prefix' => LogMessage::_defaultFilePrefix(),
            'log_to_url' => 'https://enginesis-l.com/procs/log.php',
            'log_to_socket' => 8001,
            'stage' => serverStage()
        ];
        return $configuration;
    }

    /**
     * Helper function to convert a parameters key/value array into a printable string.
     */
    public static function parametersToString($parameters) {
        $parametersString = '';
        foreach ($parameters as $key => $value) {
            if ($key == 'authtok' || strpos($key, 'password') !== false) {
                $value = 'XXXXXX';
            }
            if (is_array($value)) {
                $value = '[' . implode(',', $value) . ']';
            }
            $parametersString .= (strlen($parametersString) == 0 ? '' : ', ') . $key . '=' . $value;
        }
        return $parametersString;
    }

    /**
     * This is the main logging function to log a message string to the active log. All parameters except $message
     * are optional.
     * @param $message
     * @param int $level - since this is a bit mask, will log the highest order bit and Informational if no valid bits are set.
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function log ($message, $level = LogMessageLevel::Info, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        if ($this->_active && $this->levelMatch($level)) {
            $logMessage = $this->_formatLogMessage($message, $level, $subsystem, $sourceFile, $lineNumber);
            if ($this->_logToOutput) {
                $this->_logToOutput($logMessage);
            }
            if ($this->_logToFile) {
                $this->_logToFile($logMessage);
            }
            if ($this->_logToURL) {
                $this->_logToURL($logMessage);
            }
            if ($this->_logToSocket) {
                $this->_logToSocket($logMessage);
            }
        }
    }

    /**
     * Same as log() but takes a variable as the first parameter and converts that variable to a string and then logs it.
     * @param $value
     * @param int $level
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function logVar ($value, $level = LogMessageLevel::Info, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        $message = var_export($value, true);
        $this->log($message, $level, $subsystem, $sourceFile, $lineNumber);
        return $message;
    }

    /**
     * Helper function to log an information message.
     * @param $message
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function logInfo ($message, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        $this->log($message, LogMessageLevel::Info, $subsystem, $sourceFile, $lineNumber);
    }

    /**
     * Helper function to log a warning message.
     * @param $message
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function logWarn ($message, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        $this->log($message, LogMessageLevel::Warn, $subsystem, $sourceFile, $lineNumber);
    }

    /**
     * Helper function to log an error message.
     * @param $message
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function logError ($message, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        $this->log($message, LogMessageLevel::Error, $subsystem, $sourceFile, $lineNumber);
    }

    /**
     * Helper function to log a critical message.
     * @param $message
     * @param null $subsystem
     * @param null $sourceFile
     * @param null $lineNumber
     */
    public function logCritical ($message, $subsystem = null, $sourceFile = null, $lineNumber = null) {
        $this->log($message, LogMessageLevel::Critical, $subsystem, $sourceFile, $lineNumber);
    }

    /**
     * Helper function when debugging session logic, to keep it separated from everything else.
     * Messages are only logged when global constant DEBUG_SESSION is set true.
     * @param $message {string} Message to show in the logs.
     * @param null $subsystem {string} Keep messages organized by domain, default is SESSION.
     * @param null $sourceFile {string} Optional, log the file that generated the message.
     * @param null $lineNumber {integer} Optional, log the line number that generated the message.
     */
    public function logSession($message, $subsystem = 'SESSION', $sourceFile = null, $lineNumber = null) {
        if ( ! defined('DEBUG_SESSION')) {
            define('DEBUG_SESSION', false);
        }
        if (DEBUG_SESSION) {
            $this->log($message, LogMessageLevel::Information, $subsystem, $sourceFile, $lineNumber);
        }
    }

    /**
     * Determine if the logging is working or not.
     */
    public function isValid() {
        return $this->_isValid;
    }

    /**
     * Immediately display a variable and a log message in the output stream.
     * @param any $variable A PHP variable to display.
     * @param string $message A message to give context to the debug reason.
     */
    static function logImmediate($variable, $message = null) {
        if ( ! isset($message) || $message == null) {
            $caller = debug_backtrace()[0];
            $message = 'From ' . basename($caller['file']) . ':' . $caller['line'];
        }
        echo("<div><h3>$message</h3>");
        echo '<pre>';
        echo(var_export($variable, true));
        echo '</pre></div>';
    }
}
