<?php
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================

//define( 'INCLUSION_PERMITTED', true );
//require_once( 'config.php' );

if ( defined('INCLUSION_PERMITTED') === false ||
    (defined('INCLUSION_PERMITTED') === true && INCLUSION_PERMITTED === false)) { 
	die( 'This file must not be invoked directly.' ); 
}

if(defined('__RIPRUNNER_ROOT__') === false) {
    define('__RIPRUNNER_ROOT__', dirname(__FILE__));
}

$log = null;
$autoload = __DIR__ . '/vendor/autoload.php';
if(file_exists($autoload)) {
    require $autoload;
}

if(class_exists('\\Monolog\\Logger')) {
    // Use Monolog's `Logger` namespace:
    $baseLoggerClass = '\\Monolog\\Logger';
    $handlerClass = '\\Monolog\\Handler\\StreamHandler';

    class RipLogger extends \Monolog\Logger {
        private $log;
        public function setLogger($mylogger) {
            $this->log = $mylogger;
        }
        public function trace($msg) {
            //$this->log->info($msg);
        }
        public function warn($msg) {
            $this->log->warning($msg);
        }
        public function getRootLoggerPath() {
            return $this->log->getHandlers()[0]->getUrl();
        }
    }

    $log = new RipLogger('myLogger');
    $log->setLogger($log);
    $logstream = new $handlerClass(__RIPRUNNER_ROOT__ . '/riprunner.log', $baseLoggerClass::INFO);
    $log->pushHandler($logstream);
}
else {
    class RipLogger {
        private $path;
        public function __construct($path) {
            $this->path = $path;
        }
        public function trace($msg) {
        }
        public function warn($msg) {
            $this->write('WARN', $msg);
        }
        public function error($msg) {
            $this->write('ERROR', $msg);
        }
        public function getRootLoggerPath() {
            return $this->path;
        }
        private function write($level, $msg) {
            $line = '[' . date('c') . '] ' . $level . ': ' . $msg . PHP_EOL;
            @file_put_contents($this->path, $line, FILE_APPEND);
        }
    }
    $log = new RipLogger(__RIPRUNNER_ROOT__ . '/riprunner.log');
}

function throwExceptionAndLogError($ui_error_msg, $log_error_msg) {
    global $log;
	try {
		throw new \Exception($log_error_msg);
	}
	catch(Exception $ex) {
	    if($log != null) $log->error($ui_error_msg, array('exception' => $ex));
		die($ui_error_msg);
	}
}
