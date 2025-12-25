<?php
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================
namespace riprunner;

ini_set('display_errors', 'On');
error_reporting(E_ALL);

//
// This file manages routing of requests
//
if(defined('INCLUSION_PERMITTED') === false) {
    define( 'INCLUSION_PERMITTED', true);
}

require_once 'config_constants.php';
require_once 'common_functions.php';
require_once __RIPRUNNER_ROOT__ . '/db/db_connection.php';
try {
    if (!file_exists('config.php' )) {
        throw new \Exception('Config script does not exist!');
    }
    else {
        require_once 'config.php';
    }
}
catch(\Exception $e) {
    \handle_config_error('', $e);
    return;
}

require_once __RIPRUNNER_ROOT__ . '/functions.php';
require __DIR__ . '/vendor/autoload.php';

function caller_webhook_get_header($name) {
    $headers = array();
    if(function_exists('getallheaders')) {
        $headers = getallheaders();
    }
    foreach($_SERVER as $key => $value) {
        if(strpos($key, 'HTTP_') === 0) {
            $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$header] = $value;
        }
    }
    foreach($headers as $header_name => $header_value) {
        if(strcasecmp($header_name, $name) === 0) {
            return $header_value;
        }
    }
    return null;
}

function caller_webhook_check_rate_limit($identifier, $limit, $window_seconds) {
    $bucket_file = sys_get_temp_dir() . '/riprunner_caller_rate_' . md5($identifier) . '.json';
    $now = time();
    $entries = array();
    if(file_exists($bucket_file)) {
        $contents = file_get_contents($bucket_file);
        if($contents !== false) {
            $decoded = json_decode($contents, true);
            if(is_array($decoded)) {
                $entries = $decoded;
            }
        }
    }
    $entries = array_values(array_filter($entries, function($timestamp) use ($now, $window_seconds) {
        return ($timestamp + $window_seconds) >= $now;
    }));
    if(count($entries) >= $limit) {
        return false;
    }
    $entries[] = $now;
    file_put_contents($bucket_file, json_encode($entries));
    return true;
}

function caller_webhook_normalize_number($number) {
    $normalized = trim($number ?? '');
    if($normalized === '') {
        return $normalized;
    }
    if(strpos($normalized, '09') === 0 && strlen($normalized) === 11) {
        return '+98' . substr($normalized, 1);
    }
    if(strpos($normalized, '0098') === 0) {
        return '+98' . substr($normalized, 4);
    }
    return $normalized;
}

function respond_healthcheck() {
    global $FIREHALLS;
    $payload = array(
        'status' => 'ok',
        'success' => true,
        'db' => 'unknown',
        'timezone' => date_default_timezone_get(),
        'time' => date('c'),
    );
    $http_code = 200;
    $db_connection = null;
    try {
        $firehall = getFirstActiveFireHallConfig($FIREHALLS);
        if($firehall == null) {
            throw new \Exception('No active firehall configured.');
        }
        $db = new \riprunner\DbConnection($firehall);
        $db_connection = $db->getConnection();
        if($db_connection == null) {
            throw new \Exception('Database connection unavailable.');
        }
        $stmt = $db_connection->query('SELECT 1');
        if($stmt === false) {
            throw new \Exception('Database query failed.');
        }
        $stmt->fetch();
        $payload['db'] = 'ok';
    }
    catch(\Exception $e) {
        $payload['status'] = 'degraded';
        $payload['success'] = false;
        $payload['db'] = 'error';
        $payload['db_error'] = $e->getMessage();
        $http_code = 503;
    }
    if($db_connection !== null) {
        \riprunner\DbConnection::disconnect_db($db_connection);
    }
    if(headers_sent() === false) {
        header('Content-Type: application/json');
        http_response_code($http_code);
    }
    echo json_encode($payload);
}

\Flight::route('GET /health', function () {
    respond_healthcheck();
});

\Flight::route('GET /api/health', function () {
    respond_healthcheck();
});

\Flight::route('POST /api/caller/events', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    $secret = getenv('CALLER_WEBHOOK_SECRET');
    $provided_secret = caller_webhook_get_header('X-Caller-Secret');
    if($secret === false || $secret === '' || $provided_secret === null || !hash_equals($secret, $provided_secret)) {
        http_response_code(401);
        echo json_encode(array('success' => false, 'message' => 'Unauthorized'));
        return;
    }
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if(caller_webhook_check_rate_limit($remote_addr, 30, 60) === false) {
        http_response_code(429);
        echo json_encode(array('success' => false, 'message' => 'Too Many Requests'));
        return;
    }
    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body ?? '', true);
    if(is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON payload'));
        return;
    }
    $caller_number = caller_webhook_normalize_number($payload['caller_number'] ?? '');
    $timestamp = $payload['timestamp'] ?? '';
    if($caller_number === '' || $timestamp === '') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'caller_number and timestamp are required'));
        return;
    }
    try {
        $call_time = new \DateTimeImmutable($timestamp);
    }
    catch(\Exception $ex) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid timestamp'));
        return;
    }

    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }

    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    $now = new \DateTimeImmutable('now');
    $now_str = $now->format('Y-m-d H:i:s');
    $call_time_str = $call_time->format('Y-m-d H:i:s');
    $raw_payload = json_encode($payload);
    $line = $payload['line'] ?? null;
    $device_index = $payload['device_index'] ?? null;
    $call_id = $payload['call_id'] ?? null;

    $stmt = $db_connection->prepare(
        'INSERT INTO caller_events (caller_number, call_time, line, device_index, call_id, raw_payload, created_at)
        VALUES (:caller_number, :call_time, :line, :device_index, :call_id, :raw_payload, :created_at)'
    );
    $stmt->bindParam(':caller_number', $caller_number);
    $stmt->bindParam(':call_time', $call_time_str);
    $stmt->bindParam(':line', $line);
    $stmt->bindParam(':device_index', $device_index);
    $stmt->bindParam(':call_id', $call_id);
    $stmt->bindParam(':raw_payload', $raw_payload);
    $stmt->bindParam(':created_at', $now_str);
    $stmt->execute();

    $draft_stmt = $db_connection->prepare(
        'SELECT id, status FROM incident_drafts WHERE caller_number = :caller_number LIMIT 1'
    );
    $draft_stmt->bindParam(':caller_number', $caller_number);
    $draft_stmt->execute();
    $draft_row = $draft_stmt->fetch(\PDO::FETCH_ASSOC);
    $draft_stmt->closeCursor();

    if($draft_row === false) {
        $insert_stmt = $db_connection->prepare(
            'INSERT INTO incident_drafts (caller_number, last_call_time, status, created_at, updated_at)
            VALUES (:caller_number, :last_call_time, :status, :created_at, :updated_at)'
        );
        $status = 'new';
        $insert_stmt->bindParam(':caller_number', $caller_number);
        $insert_stmt->bindParam(':last_call_time', $call_time_str);
        $insert_stmt->bindParam(':status', $status);
        $insert_stmt->bindParam(':created_at', $now_str);
        $insert_stmt->bindParam(':updated_at', $now_str);
        $insert_stmt->execute();
    }
    else if($draft_row['status'] === 'new') {
        $update_stmt = $db_connection->prepare(
            'UPDATE incident_drafts SET last_call_time = :last_call_time, updated_at = :updated_at
            WHERE id = :id'
        );
        $update_stmt->bindParam(':last_call_time', $call_time_str);
        $update_stmt->bindParam(':updated_at', $now_str);
        $update_stmt->bindParam(':id', $draft_row['id']);
        $update_stmt->execute();
    }

    \riprunner\DbConnection::disconnect_db($db_connection);
    header('Content-Type: application/json');
    echo json_encode(array('success' => true));
});

\Flight::route('GET /api/caller/latest', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    $minutes = isset($_GET['minutes']) ? intval($_GET['minutes']) : 10;
    if($minutes <= 0) {
        $minutes = 10;
    }
    if($minutes > 240) {
        $minutes = 240;
    }
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    $driver = $db_connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if($driver === 'sqlite') {
        $cutoff = '-' . $minutes . ' minutes';
        $sql = "SELECT d.caller_number, d.last_call_time, d.status, d.updated_at,
                e.call_id, e.line, e.device_index, e.call_time as event_time
            FROM incident_drafts d
            LEFT JOIN caller_events e
                ON e.id = (
                    SELECT id FROM caller_events
                    WHERE caller_number = d.caller_number
                    ORDER BY call_time DESC, id DESC
                    LIMIT 1
                )
            WHERE d.status = 'new' AND d.last_call_time >= datetime('now', :cutoff)
            ORDER BY d.last_call_time DESC
            LIMIT 50";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':cutoff', $cutoff);
    }
    else {
        $sql = "SELECT d.caller_number, d.last_call_time, d.status, d.updated_at,
                e.call_id, e.line, e.device_index, e.call_time as event_time
            FROM incident_drafts d
            LEFT JOIN caller_events e
                ON e.id = (
                    SELECT id FROM caller_events
                    WHERE caller_number = d.caller_number
                    ORDER BY call_time DESC, id DESC
                    LIMIT 1
                )
            WHERE d.status = 'new' AND d.last_call_time >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            ORDER BY d.last_call_time DESC
            LIMIT 50";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':minutes', $minutes, \PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('GET|POST /tenant/(@tenant)/*', function ($tenant) {
	
	//echo "TENANT ${tenant} URL got params: " . PHP_EOL;

	$request = \Flight::request();
	//echo "TENANT ${tenant} URL: ". $request->url . " PARAMS: ";
	//print_r($request->query) . PHP_EOL;
	//echo "TENANT ${tenant} URL: ". $request->url;
	$new_url = str_replace("/tenant/${tenant}","",$request->url);
	if($tenant != null && $tenant != '') {
		if (strpos($new_url ?? '','?') == false) {
			$new_url .= '?';
		}
		else {
			$new_url .= '&';
		}
		$new_url .= 'fhid='.$tenant;
	}
	//echo "\nTENANT ${tenant} NEW URL: ". $new_url;

	\Flight::redirect($new_url);
});

\Flight::route('GET|POST /ngui/*', function () {
    global $FIREHALLS;
    
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);
	$fhid = '';
	$request = \Flight::request();
	if ($request !== null && $request->query !== null && $request->query->fhid != null) {
		$fhid = '?fhid='.$request->query->fhid;
	}

	//echo "NGUI URL: [$fhid] ";
	//print_r($request->query);
    \Flight::redirect($root_url .'/ngui/index.html'.$fhid);
});

\Flight::route('GET|POST /maprxy(/@lnk)', function ($lnk) {
	http_response_code(410);
	echo 'Google Maps is disabled. Use Neshan maps.';
});

\Flight::route('GET|POST /mapapiprxy/*', function () {
	http_response_code(410);
	echo 'Google Maps API is disabled. Use Neshan maps.';
});

\Flight::route('GET|POST /prxy(/@lnk)', function ($lnk) {
	global $FIREHALLS;
	global $log;
	$query = array();
	//parse_str($params, $query);
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);

	$shortUrl = '';
	if($lnk !== null && $lnk !== '') {
		$shortUrl = $lnk;
	}
	//else {
	//	echo "Got params\n${params}" . PHP_EOL;
	//}
	$longUrl = '';

	$firehall = getFirstActiveFireHallConfig($FIREHALLS);
	if($firehall !== null) {
		$config = new \riprunner\ConfigManager(array($firehall));

		$db = new \riprunner\DbConnection($firehall);
		$db_connection = $db->getConnection();

		// Get the long url
		if($db_connection !== null) {
			$sql_statement = new \riprunner\SqlStatement($db_connection);
			$sql = $sql_statement->getSqlStatement('url_proxy_select');

			$qry_bind = $db_connection->prepare($sql);
			$qry_bind->bindParam(':shorturl', $shortUrl);
			$qry_bind->execute();

			//$rows = $qry_bind->fetchAll(\PDO::FETCH_OBJ);
			$rows = $qry_bind->fetchAll(\PDO::FETCH_ASSOC);
			$qry_bind->closeCursor();
			\riprunner\DbConnection::disconnect_db( $db_connection );

			if($log !== null) $log->trace("Call /prxy/ SQL success for sql [$sql] row count: " . 
			safe_count($rows));
			
			if(safe_count($rows) > 0) {
				//echo 'Proxy SQL found long url [' .$rows[0]['longurl'] . ']' . PHP_EOL;
				//print_r($rows[0]);
				$longUrl = $rows[0]['longurl'];
			}
		}
	}

	if($longUrl == null || $longUrl === '') {
		echo "Proxy [$shortUrl] NOT FOUND!" . PHP_EOL;
		return;
	}
	//echo "Proxy [$shortUrl] FOUND [$longUrl]!" . PHP_EOL;
	\Flight::redirect($root_url .'/' . $longUrl);
});

\Flight::route('GET|POST /', function () {
    global $FIREHALLS;
    //$query = array();
    //parse_str($params, $query);

    $root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);
    //\Flight::redirect($root_url .'/controllers/login-controller.php?' . $params);
    \Flight::redirect($root_url .'/controllers/login-controller.php');
});

\Flight::route('GET|POST /l/@token', function ($token) {
    $_GET['token'] = $token;
    require_once __DIR__ . '/controllers/incident-location-controller.php';
});

\Flight::route('GET|POST /login|/logon(/@params)', function ($params) {
	global $FIREHALLS;
	$query = array();
	parse_str($params ?? '', $query);

	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);
	\Flight::redirect($root_url .'/controllers/login-controller.php?' . $params);
});

\Flight::route('GET|POST /mobile-login/(@params)', function ($params) {
	global $FIREHALLS;
	$query = array();
	parse_str($params ?? '', $query);
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);
	\Flight::redirect($root_url .'/controllers/login-device-controller.php?' . $params);
});

\Flight::route('GET|POST /test/(@params)', function ($params) {
    global $log;
    $log->trace("Route got TEST message: ".$params);
});
    
\Flight::route('GET|POST /ci/(@params)', function ($params) {
	global $FIREHALLS;
	$query = array();
	parse_str($params ?? '', $query);
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);

	\Flight::redirect($root_url .'/controllers/callout-details-controller.php?' . $params);
});

\Flight::route('GET|POST /cr/(@params)', function ($params) {
	global $FIREHALLS;
	global $log;
	$log->warn("Route got CR message: ".$params);
	
	$query = array();
	parse_str($params ?? '', $query);
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);

	$log->warn("Route got CR about to redirect to: ".$root_url .'/controllers/callout-response-controller.php?' . $params);
	\Flight::redirect($root_url .'/controllers/callout-response-controller.php?' . $params);
});

\Flight::route('GET|POST /ct/(@params)', function ($params) {
	global $FIREHALLS;
	$query = array();
	parse_str($params ?? '', $query);
	$root_url = getFirehallRootURLFromRequest(\Flight::request()->url, $FIREHALLS);

	\Flight::redirect($root_url .'/controllers/callout-tracking-controller.php?' . $params);
});

\Flight::route('GET|POST /android-error/(@params)', function ($params) {
	$query = array();
	parse_str($params ?? '', $query);

	echo "Got android errors\n${params}" . PHP_EOL;
});
	
\Flight::map('notFound', function () {
	// Handle not found
	echo "route NOT FOUND!" . PHP_EOL;
});
		
\Flight::set('flight.log_errors', true);	
\Flight::start();
