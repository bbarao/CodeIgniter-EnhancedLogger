<?php

class MY_Log extends CI_Log {

    // New threshold levels
    protected $_levels = array(
        'CRITICAL' => 1,
        'ERROR'    => 2,
        'WARNING'  => 3,
        'INFO'     => 4,
        'DEBUG'    => 5,
        'ALL'      => 6
    );

    // Default threshold
    // (Overridable in config.php)
    protected $_threshold = 4;

    // Threshold to generate save a stacktrace
    protected $_stacktrace_threshold = 3;

    // List of facilities and max threshold levels
    // (Overridable in config.php)
    protected $_facilities = array(
        'php_error'   => 4,
        'codeigniter' => 4
    );

    // Database exclusions
    // (Overridable in config.php)
    protected $_database_exclude_facilities = array(
        'php_error',
        'codeigniter'
    );

    // Default date format as ISO6801
    // (Overridable in config.php)
    protected $_date_fmt = 'c';

    private $CI;

    public function __construct() {
        parent::__construct();

        $config =& get_config();

        // Override facilities with config?
        if (isset($config['log_facilities']) && !empty($config['log_facilities'])) {
            $this->_facilities = $config['log_facilities'];
        }

        // Override db exclusion with config?
        if (isset($config['log_facilities_db_exclude']) && !empty($config['log_facilities_db_exclude'])) {
            $this->_database_exclude_facilities = $config['log_facilities_db_exclude'];
        }

    }

    // Compat with codeigniter logging interface
    public function write_log($level = 'error', $msg, $php_error = FALSE) {
        // Assume codeigniter facility
        if(!$php_error) {
            $this->log($level, 'codeigniter', $msg);
        } else {
            $this->log($level, 'php_error', $msg);
        }
    }

    /**
     * Logs a message
     * @param  string $level    The log level
     * @param  string $facility The name of the facility
     * @param  mixed  $data     Can be a string when passing only the message, or an array when passing more data
     * @return boolean          Returns if the logging was successful
     */
    public function log($level, $facility, $data) {
        // Return if logging is disabled
        if ($this->_enabled === FALSE) {
            return FALSE;
        }

        // Check if the facility exists
        // If exists, use the defined threshold else, use the default threshold (defined in config.php)
        if(array_key_exists($facility, $this->_facilities)) {
            $threshold = $this->_facilities[$facility];
        } else {
            $threshold = $this->_threshold;
        }

        // Check if the level exists, and is <= than the threshold
        $level = strtoupper($level);
        if(!array_key_exists($level, $this->_levels) || $this->_levels[$level]>$threshold) {
            return FALSE;
        }

        // File log
        $rFile = $this->_log_file($level, $facility, $data);

        // Database log
        if(!in_array($facility, $this->_database_exclude_facilities)) {
            $rDB = $this->_log_database($level, $facility, $data);
        } else {
            $rDB = TRUE;
        }

        return $rFile && $rDB;
    }


    // Aliases

    public function critical($facility, $data) {
        $this->log('CRITICAL', $facility, $data);
    }

    public function error($facility, $data) {
        $this->log('ERROR', $facility, $data);
    }

    public function warn($facility, $data) {
        $this->log('WARNING', $facility, $data);
    }

    public function warning($facility, $data) {
        $this->log('WARNING', $facility, $data);
    }

    public function info($facility, $data) {
        $this->log('INFO', $facility, $data);
    }

    public function debug($facility, $data) {
        $this->log('DEBUG', $facility, $data);
    }


    // Helpers

    protected function _get_stacktrace($levels_to_remove=1) {
        $backtrace = Array();

        $st = debug_backtrace(FALSE);

        // Remove the call to _get_stacktrace, and more (if requested)
        while($levels_to_remove-- > 0) {
            array_shift($st);
        }

        // Get the pretty stacktrace
        foreach($st as $k=>$v){
            $args = Array();
            foreach($v['args'] as $arg) {
                if(is_object($arg) || is_array($arg)) {
                    $args[] = "[".@strval($arg)."]";
                } else if(is_null($arg)) {
                    $args[] = 'NULL';
                } else if(is_string($arg)) {
                    $args[] = "'".strval($arg)."'";
                } else if(is_bool($arg)) {
                    $args[] = $arg ? 'TRUE' : 'FALSE';
                } else {
                    $args[] = $arg;
                }
            }

            if(!isset($v['file'])) {
                $v['file'] = 'Unknown';
            }
            if(!isset($v['line'])) {
                $v['line'] = '??';
            }

            $backtrace[] = "#".$k." ".$v['function']."(".join($args,', ').") called at [".$v['file'].":".$v['line']."]";
        }

        return join($backtrace,"\n");
    }


    // Writers

    protected function _log_file($level, $facility, $data) {

        // Log on the same codeigniter file
        $filepath = $this->_log_path.'log-'.date('Y-m-d').EXT;

        $message = '';
        if ( ! file_exists($filepath)) {
            $message .= "<"."?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed'); ?".">\n\n";
        }

        if ( ! $fp = @fopen($filepath, FOPEN_WRITE_CREATE)) {
            return FALSE;
        }

        $tag = '';
        if(is_string($data)) {
            $msg = $data;
        } else {
            $msg = $data['msg'];
            if(isset($data['tag'])) {
                $tag = '/'.$data['tag'];
            }
        }
        $message .= date($this->_date_fmt).' '.$level{0}.'/'.$facility.$tag.': '.$msg."\n";

        flock($fp, LOCK_EX);
        fwrite($fp, $message);
        flock($fp, LOCK_UN);
        fclose($fp);

        @chmod($filepath, FILE_WRITE_MODE);
        return TRUE;
    }

    protected function _log_database($level, $facility, $data) {

        if(!isset($this->CI)) {
            $this->CI = &get_instance();
        }

        $tag = NULL;
        if(is_string($data)) {
            $msg = $data;
        } else {
            $msg = $data['msg'];
            if(isset($data['tag'])) {
                $tag = $data['tag'];
            }
        }

        $this->CI->db->query("INSERT INTO logs (level, facility, tag, message, stacktrace) VALUES (?,?,?,?,?)", array(
            $level,
            $facility,
            $tag,
            $msg,
            // Save stacktrace if level <= st_threshold (also remove the call to _log_database)
            ($this->_levels[$level]<=$this->_stacktrace_threshold ? $this->_get_stacktrace(2) : NULL)
        ));

    }

}

