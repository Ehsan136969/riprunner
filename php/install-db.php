<?php
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================
define( 'INCLUSION_PERMITTED', true );
require_once 'config_constants.php';
try {
//	if (!file_exists('config.php' )) {
//	  throw new \Exception ('Config script does not exist!');
//	  }
//	else {
require_once 'config.php';
//	}
}
catch(\Exception $e) {    
  echo 'ERROR #1'.PRODUCT_NAME.' v'.CURRENT_VERSION.' - '.PRODUCT_URL.PHP_EOL.
       'Error detected, message : ' . $e->getMessage().', '.'Code : ' . $e->getCode().PHP_EOL.
       'Please create a config.php script.'.PHP_EOL.
       'Please generate a config.php file'.PHP_EOL;
  return;
}

require_once 'db/db_connection.php';
require_once 'db/sql_statement.php';
require_once 'authentication/authentication.php';
require_once 'functions.php';

$argv = getopt(null, ["fhid::","form_action::","adminpwd::"]);
var_dump($argv);

function seed_user($db_connection, $firehall_id, $username, $password, $access) {
    if(isset($username) === false || $username === null || $username === '') {
        return false;
    }
    if(isset($password) === false || $password === null || $password === '') {
        return false;
    }
    $sql = "SELECT id FROM user_accounts WHERE firehall_id = :fhid AND user_id = :user_id LIMIT 1";
    $qry_bind = $db_connection->prepare($sql);
    $qry_bind->bindParam(':fhid', $firehall_id);
    $qry_bind->bindParam(':user_id', $username);
    $qry_bind->execute();
    $existing = $qry_bind->fetch(\PDO::FETCH_OBJ);
    $qry_bind->closeCursor();
    if($existing !== false && $existing !== null) {
        return true;
    }
    $hashed_password = \riprunner\Authentication::encryptPassword($password);
    $insert = "INSERT INTO user_accounts (firehall_id,user_id,user_pwd,access,active,twofa) VALUES (:fhid,:user_id,:pwd,:access,1,0)";
    $insert_bind = $db_connection->prepare($insert);
    $insert_bind->bindParam(':fhid', $firehall_id);
    $insert_bind->bindParam(':user_id', $username);
    $insert_bind->bindParam(':pwd', $hashed_password);
    $insert_bind->bindParam(':access', $access);
    return $insert_bind->execute();
}

function install($FIREHALL, &$db_connection, $argv) {
    $sql_statement = new \riprunner\SqlStatement($db_connection);
    $db_exist = $sql_statement->db_exists($FIREHALL->DB->DATABASE, null);
    $db_table_exist = $sql_statement->db_exists($FIREHALL->DB->DATABASE, 'user_accounts');
    
	if($db_exist === false) {
	    $sql = $sql_statement->getSqlStatement('database_create');
	    $dbname = $FIREHALL->DB->DATABASE;
	    $sql = preg_replace_callback('(:db)', function ($m) use ($dbname) { $m; return $dbname; }, $sql);
		$db_connection->query($sql);
		
		echo 'Successfully created database [' . $FIREHALL->DB->DATABASE . ']' . PHP_EOL;
	}
	
	\riprunner\DbConnection::disconnect_db( $db_connection );
	$db_connection = null;
	
	// Connect to the database
	$db = new \riprunner\DbConnection($FIREHALL);
	$db_connection = $db->getConnection();
	
	if($db_table_exist === false) {
	    $sql_statement = new \riprunner\SqlStatement($db_connection);
	    $schema_results = $sql_statement->installSchema();
		echo 'SCHEMA Import Information, Success: ' . $schema_results["success"] . ' Total: ' . $schema_results["total"] . PHP_EOL;

		$seed_admin_username = getenv('SEED_ADMIN_USERNAME');
		$seed_admin_password = getenv('SEED_ADMIN_PASSWORD');
		$seed_dispatcher_username = getenv('SEED_DISPATCHER_USERNAME');
		$seed_dispatcher_password = getenv('SEED_DISPATCHER_PASSWORD');
		$forced_pwd = $argv['adminpwd'];

		$admin_created = false;
		if (!empty($seed_admin_username) && !empty($seed_admin_password)) {
			$admin_created = seed_user($db_connection, $FIREHALL->FIREHALL_ID, $seed_admin_username, $seed_admin_password, USER_ACCESS_ADMIN);
		}
		else if (isset($forced_pwd) === true && $forced_pwd !== null && $forced_pwd !== '') {
			$admin_created = seed_user($db_connection, $FIREHALL->FIREHALL_ID, 'admin', $forced_pwd, USER_ACCESS_ADMIN);
		}

		if ($admin_created === true) {
			echo 'Admin account seed completed.'.PHP_EOL;
		}
		else {
			echo 'Admin seed skipped: set SEED_ADMIN_USERNAME and SEED_ADMIN_PASSWORD.' . PHP_EOL;
		}

		$dispatcher_created = false;
		if (!empty($seed_dispatcher_username) && !empty($seed_dispatcher_password)) {
			$dispatcher_created = seed_user($db_connection, $FIREHALL->FIREHALL_ID, $seed_dispatcher_username, $seed_dispatcher_password, USER_ACCESS_DISPATCHER);
		}
		if ($dispatcher_created === true) {
			echo 'Dispatcher account seed completed.' . PHP_EOL;
		}
		else {
			echo 'Dispatcher seed skipped: set SEED_DISPATCHER_USERNAME and SEED_DISPATCHER_PASSWORD.' . PHP_EOL;
		}
	}
}

echo 'Checking for installation parameters...' . PHP_EOL;
        $firehall_id = $argv['fhid'];
        $db_connection = null;
        if (isset($firehall_id) === true) {
            echo 'Found firehall_id = ' . $firehall_id . PHP_EOL;
			try {
			$form_action = $argv['form_action'];
        	$FIREHALL = findFireHallConfigById($firehall_id, $FIREHALLS);
            echo 'Found form_action = ' . $form_action . PHP_EOL;
        	if($FIREHALL !== null) {
				if($form_action === "install") {
				    $db = new \riprunner\DbConnection($FIREHALL, true);
				    $db_connection = $db->getConnection();
					//die('Test Master connection!');
				}
				else {
				    $db = new \riprunner\DbConnection($FIREHALL);
				    $db_connection = $db->getConnection();
				}
        	}
			}	
			catch(Exception $e) {    
			  echo 'ERROR #2'.PRODUCT_NAME.' v'.CURRENT_VERSION.' - '.PRODUCT_URL.PHP_EOL.
				   'Error detected, message : ' . $e->getMessage().', '.'Code : ' . $e->getCode().PHP_EOL.
				   'Please edit your config.php script and correct any errors.'.PHP_EOL;
			  return;
			}
        }
        else {
			echo 'No action to perform' . PHP_EOL;
		}
        
		if (isset($firehall_id) === true) {
            echo 'Checking to see if the Firehall is already installed:'.PHP_EOL.
                 $FIREHALL->WEBSITE->FIREHALL_NAME.PHP_EOL;
            if($form_action === 'install') {
                $sql_statement = new \riprunner\SqlStatement($db_connection);
                $db_exist = $sql_statement->db_exists($FIREHALL->DB->DATABASE, 'user_accounts');
				if($db_exist === true) {
					echo 'The Firehall already exists.' . PHP_EOL;
				}
				else {
					echo 'The Firehall DOES NOT exist, starting installation...' . PHP_EOL;
					install($FIREHALL, $db_connection, $argv);
				}
			}
        }
		else {
            echo PRODUCT_NAME.' v'.CURRENT_VERSION.' - '.PRODUCT_URL. PHP_EOL.
                 'Please select the firehall config to check if installation is required.'.PHP_EOL;
        }

        if($db_connection !== null) {
        	\riprunner\DbConnection::disconnect_db( $db_connection );
        }
?>
