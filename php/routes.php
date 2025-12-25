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

function ensure_vendor_autoload() {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if(file_exists($autoload)) {
        return;
    }
    $vendorZip = dirname(__DIR__) . '/vendor-php-8.3.3.zip';
    if(file_exists($vendorZip) && class_exists('ZipArchive')) {
        $zip = new \ZipArchive();
        if($zip->open($vendorZip) === true) {
            $zip->extractTo(__DIR__ . '/vendor');
            $zip->close();
        }
    }
    $nestedAutoload = __DIR__ . '/vendor/vendor/autoload.php';
    if(file_exists($autoload) === false && file_exists($nestedAutoload)) {
        @copy($nestedAutoload, $autoload);
    }
}

ensure_vendor_autoload();

require_once 'config_constants.php';
require_once 'common_functions.php';
require_once __RIPRUNNER_ROOT__ . '/db/db_connection.php';
require_once __RIPRUNNER_ROOT__ . '/authentication/authentication.php';
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
$autoload = __DIR__ . '/vendor/autoload.php';
if(file_exists($autoload)) {
    require $autoload;
}

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

function require_api_role($allowed_roles) {
    $auth_cache = \riprunner\Authentication::getJWTAuthCache($_REQUEST, $_SERVER);
    $role = $auth_cache['user_role'] ?? null;
    if($role === null || $role === '') {
        http_response_code(401);
        echo json_encode(array('success' => false, 'message' => 'Unauthorized'));
        return false;
    }
    $role_normalized = strtolower(trim((string)$role));
    $allowed = array_map(function($item) {
        return strtolower(trim((string)$item));
    }, $allowed_roles);
    if(in_array($role_normalized, $allowed, true) === false) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'message' => 'Forbidden'));
        return false;
    }
    return true;
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
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
    else if($driver === 'pgsql') {
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
            WHERE d.status = 'new' AND d.last_call_time >= (NOW() - (:minutes * INTERVAL '1 minute'))
            ORDER BY d.last_call_time DESC
            LIMIT 50";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':minutes', $minutes, \PDO::PARAM_INT);
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

\Flight::route('GET /api/stations', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $include_inactive = false;
    if(isset($_GET['include_inactive'])) {
        $value = strtolower(trim($_GET['include_inactive']));
        $include_inactive = ($value === '1' || $value === 'true');
    }
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $sql = 'SELECT id, name, lat, lng, is_active FROM stations';
    if($include_inactive === false) {
        $sql .= ' WHERE is_active = true';
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = $db_connection->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'stations' => $rows));
});

\Flight::route('GET /api/stations/nearest', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    if($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'lat and lng are required'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $sql = 'SELECT id, name, lat, lng, is_active FROM stations WHERE is_active = true ORDER BY id ASC';
    $stmt = $db_connection->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    if(safe_count($rows) === 0) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No active stations found'));
        return;
    }
    $nearest = findNearestStation($rows, $lat, $lng);
    if($nearest === null) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No station coordinates available'));
        return;
    }
    echo json_encode(array('success' => true, 'station' => $nearest));
});

\Flight::route('POST /api/incidents', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
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
    if($caller_number === '') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'caller_number is required'));
        return;
    }
    $description = trim((string)($payload['description'] ?? ''));
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $now = new \DateTimeImmutable('now');
    $incident_code = 'INC-' . $now->format('YmdHis') . '-' . gen_uuid(6);
    $insert_incident = $db_connection->prepare(
        'INSERT INTO incidents (incident_code, caller_phone, description, status, created_at, updated_at)
        VALUES (:incident_code, :caller_phone, :description, :status, :created_at, :updated_at)'
    );
    $status = 'new';
    $created_at = $now->format('Y-m-d H:i:s');
    $insert_incident->execute(array(
        ':incident_code' => $incident_code,
        ':caller_phone' => $caller_number,
        ':description' => ($description !== '' ? $description : null),
        ':status' => $status,
        ':created_at' => $created_at,
        ':updated_at' => $created_at,
    ));
    $incident_id = $db_connection->lastInsertId();

    $token = gen_uuid(32);
    $expires_at = $now->modify('+1 day')->format('Y-m-d H:i:s');
    $insert_token = $db_connection->prepare(
        'INSERT INTO incident_tokens (incident_id, token, expires_at) VALUES (:incident_id, :token, :expires_at)'
    );
    $insert_token->execute(array(
        ':incident_id' => $incident_id,
        ':token' => $token,
        ':expires_at' => $expires_at,
    ));

    $web_root = rtrim($firehall->WEBSITE->WEBSITE_ROOT_URL, '/');
    $location_link = $web_root . '/l/' . $token;
    $sms_message = "لطفاً موقعیت دقیق حادثه را از طریق لینک زیر ارسال کنید:\n" . $location_link;

    $signal_manager = new \riprunner\SignalManager();
    $sms_result = $signal_manager->sendSMSPlugin_Message($firehall, $sms_message, array($caller_number));
    insertNotificationRecord($db_connection, $incident_id, 'sms', $caller_number, $sms_message, 'sent', $sms_result);
    $telegram_result = sendBotMessage(
        getenv('TELEGRAM_BOT_BASE_URL') ?: 'https://api.telegram.org',
        getenv('TELEGRAM_BOT_TOKEN') ?: '',
        getenv('TELEGRAM_CHAT_ID') ?: '',
        $sms_message
    );
    insertNotificationRecord($db_connection, $incident_id, 'telegram', getenv('TELEGRAM_CHAT_ID') ?: '', $sms_message, $telegram_result['success'] ? 'sent' : 'failed', json_encode($telegram_result));
    $eitaa_result = sendBotMessage(
        getenv('EITAA_BOT_BASE_URL') ?: 'https://eitaayar.ir/api',
        getenv('EITAA_BOT_TOKEN') ?: '',
        getenv('EITAA_CHAT_ID') ?: '',
        $sms_message
    );
    insertNotificationRecord($db_connection, $incident_id, 'eitaa', getenv('EITAA_CHAT_ID') ?: '', $sms_message, $eitaa_result['success'] ? 'sent' : 'failed', json_encode($eitaa_result));

    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array(
        'success' => true,
        'incident_id' => $incident_id,
        'incident_code' => $incident_code,
        'token' => $token,
        'location_link' => $location_link,
        'sms_result' => $sms_result,
        'telegram' => $telegram_result,
        'eitaa' => $eitaa_result,
    ));
});

\Flight::route('GET /api/incidents/@incident_id/suggested-station', function ($incident_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $incident_id = (int)$incident_id;
    if($incident_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid incident id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $location_stmt = $db_connection->prepare(
        'SELECT lat, lng FROM incident_locations WHERE incident_id = :incident_id ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $location_stmt->execute(array(':incident_id' => $incident_id));
    $location = $location_stmt->fetch(\PDO::FETCH_ASSOC);
    $location_stmt->closeCursor();
    if($location === false) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No location found for incident'));
        return;
    }
    $stations_stmt = $db_connection->prepare('SELECT id, name, lat, lng, is_active FROM stations WHERE is_active = true ORDER BY id ASC');
    $stations_stmt->execute();
    $stations = $stations_stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stations_stmt->closeCursor();
    if(safe_count($stations) === 0) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No active stations found'));
        return;
    }
    $nearest = findNearestStation($stations, $location['lat'], $location['lng']);
    if($nearest === null) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No station coordinates available'));
        return;
    }
    $update_stmt = $db_connection->prepare('UPDATE incidents SET suggested_station_id = :station_id, updated_at = CURRENT_TIMESTAMP WHERE id = :incident_id');
    $update_stmt->execute(array(
        ':station_id' => $nearest['id'],
        ':incident_id' => $incident_id,
    ));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array(
        'success' => true,
        'incident_id' => $incident_id,
        'location' => $location,
        'station' => $nearest,
    ));
});

\Flight::route('GET /api/incidents', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    if($limit <= 0 || $limit > 200) {
        $limit = 50;
    }
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $sql = "SELECT i.id, i.incident_code, i.caller_phone, i.description, i.status,
            i.suggested_station_id, i.dispatched_station_id, i.created_at, i.updated_at,
            s.name AS suggested_station_name, d.name AS dispatched_station_name,
            l.lat AS last_lat, l.lng AS last_lng
        FROM incidents i
        LEFT JOIN stations s ON s.id = i.suggested_station_id
        LEFT JOIN stations d ON d.id = i.dispatched_station_id
        LEFT JOIN incident_locations l ON l.id = (
            SELECT id FROM incident_locations
            WHERE incident_id = i.id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        )";
    if($status !== null && $status !== '') {
        $sql .= " WHERE i.status = :status";
    }
    $sql .= " ORDER BY i.created_at DESC LIMIT :limit";

    $stmt = $db_connection->prepare($sql);
    if($status !== null && $status !== '') {
        $stmt->bindParam(':status', $status);
    }
    $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('GET /api/incidents/@incident_id', function ($incident_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $incident_id = (int)$incident_id;
    if($incident_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid incident id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $incident_stmt = $db_connection->prepare('SELECT * FROM incidents WHERE id = :id LIMIT 1');
    $incident_stmt->execute(array(':id' => $incident_id));
    $incident = $incident_stmt->fetch(\PDO::FETCH_ASSOC);
    $incident_stmt->closeCursor();
    if($incident === false) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'Incident not found'));
        return;
    }
    $location_stmt = $db_connection->prepare(
        'SELECT lat, lng, source, created_at FROM incident_locations WHERE incident_id = :incident_id ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $location_stmt->execute(array(':incident_id' => $incident_id));
    $location = $location_stmt->fetch(\PDO::FETCH_ASSOC);
    $location_stmt->closeCursor();

    $suggested_stmt = $db_connection->prepare('SELECT id, name, lat, lng FROM stations WHERE id = :id LIMIT 1');
    $suggested_stmt->execute(array(':id' => $incident['suggested_station_id']));
    $suggested_station = $suggested_stmt->fetch(\PDO::FETCH_ASSOC);
    $suggested_stmt->closeCursor();

    $dispatched_stmt = $db_connection->prepare('SELECT id, name, lat, lng FROM stations WHERE id = :id LIMIT 1');
    $dispatched_stmt->execute(array(':id' => $incident['dispatched_station_id']));
    $dispatched_station = $dispatched_stmt->fetch(\PDO::FETCH_ASSOC);
    $dispatched_stmt->closeCursor();

    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array(
        'success' => true,
        'incident' => $incident,
        'location' => $location,
        'suggested_station' => $suggested_station,
        'dispatched_station' => $dispatched_station,
    ));
});

\Flight::route('GET /api/caller/drafts', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $minutes = isset($_GET['minutes']) ? intval($_GET['minutes']) : 30;
    if($minutes <= 0 || $minutes > 240) {
        $minutes = 30;
    }
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $driver = $db_connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if($driver === 'pgsql') {
        $sql = "SELECT caller_number, last_call_time, status, updated_at
            FROM incident_drafts
            WHERE status = 'new' AND last_call_time >= (NOW() - (:minutes * INTERVAL '1 minute'))
            ORDER BY last_call_time DESC";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':minutes', $minutes, \PDO::PARAM_INT);
    }
    else if($driver === 'sqlite') {
        $cutoff = '-' . $minutes . ' minutes';
        $sql = "SELECT caller_number, last_call_time, status, updated_at
            FROM incident_drafts
            WHERE status = 'new' AND last_call_time >= datetime('now', :cutoff)
            ORDER BY last_call_time DESC";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':cutoff', $cutoff);
    }
    else {
        $sql = "SELECT caller_number, last_call_time, status, updated_at
            FROM incident_drafts
            WHERE status = 'new' AND last_call_time >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            ORDER BY last_call_time DESC";
        $stmt = $db_connection->prepare($sql);
        $stmt->bindParam(':minutes', $minutes, \PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('POST /api/incidents/@incident_id/dispatch', function ($incident_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $incident_id = (int)$incident_id;
    if($incident_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid incident id'));
        return;
    }
    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body ?? '', true);
    if(is_array($payload) === false && $raw_body !== '') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON payload'));
        return;
    }
    $station_id = isset($payload['station_id']) ? (int)$payload['station_id'] : 0;

    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }

    $incident_stmt = $db_connection->prepare('SELECT * FROM incidents WHERE id = :incident_id LIMIT 1');
    $incident_stmt->execute(array(':incident_id' => $incident_id));
    $incident = $incident_stmt->fetch(\PDO::FETCH_ASSOC);
    $incident_stmt->closeCursor();
    if($incident === false) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'Incident not found'));
        return;
    }

    if($station_id <= 0) {
        $station_id = (int)($incident['dispatched_station_id'] ?: $incident['suggested_station_id']);
    }
    if($station_id <= 0) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'station_id is required'));
        return;
    }

    $location_stmt = $db_connection->prepare(
        'SELECT lat, lng FROM incident_locations WHERE incident_id = :incident_id ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $location_stmt->execute(array(':incident_id' => $incident_id));
    $location = $location_stmt->fetch(\PDO::FETCH_ASSOC);
    $location_stmt->closeCursor();
    if($location === false) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No location found for incident'));
        return;
    }

    $shift = getActiveShiftForStation($db_connection, $station_id);
    if($shift === null) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'Shift rule not found'));
        return;
    }
    $shift_group = $shift['shift_group'];

    $firefighters = getFirefightersForStationShift($db_connection, $station_id, $shift_group);
    if(safe_count($firefighters) === 0) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No firefighters found for active shift'));
        return;
    }
    $recipients = array();
    foreach($firefighters as $firefighter) {
        if(!empty($firefighter['phone'])) {
            $recipients[] = $firefighter['phone'];
        }
    }
    if(safe_count($recipients) === 0) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'No phone numbers found for active shift'));
        return;
    }

    $dispatch_token = gen_uuid(32);
    $dispatch_expires = (new \DateTimeImmutable('now'))->modify('+7 days')->format('Y-m-d H:i:s');
    $dispatch_token_stmt = $db_connection->prepare(
        'INSERT INTO incident_dispatch_tokens (incident_id, token, expires_at)
        VALUES (:incident_id, :token, :expires_at)'
    );
    $dispatch_token_stmt->execute(array(
        ':incident_id' => $incident_id,
        ':token' => $dispatch_token,
        ':expires_at' => $dispatch_expires,
    ));

    $web_root = rtrim($firehall->WEBSITE->WEBSITE_ROOT_URL, '/');
    $mission_link = $web_root . '/m/' . $dispatch_token;
    $map_link = 'https://neshan.org/maps/@' . $location['lat'] . ',' . $location['lng'] . ',16z';
    $sms_message = "اعزام حادثه " . $incident['incident_code'] .
        " - شیفت فعال: " . $shift_group .
        "\nنقشه: " . $map_link .
        "\nماموریت: " . $mission_link;

    $signal_manager = new \riprunner\SignalManager();
    $sms_result = $signal_manager->sendSMSPlugin_Message($firehall, $sms_message, $recipients);
    foreach($recipients as $recipient) {
        insertNotificationRecord($db_connection, $incident_id, 'sms', $recipient, $sms_message, 'sent', $sms_result);
    }

    $telegram_chat_id = getenv('TELEGRAM_CREW_CHAT_ID');
    if($telegram_chat_id === false || $telegram_chat_id === '') {
        $telegram_chat_id = getenv('TELEGRAM_CHAT_ID') ?: '';
    }
    $telegram_crew = sendBotMessage(
        getenv('TELEGRAM_BOT_BASE_URL') ?: 'https://api.telegram.org',
        getenv('TELEGRAM_BOT_TOKEN') ?: '',
        $telegram_chat_id,
        $sms_message
    );
    insertNotificationRecord($db_connection, $incident_id, 'telegram', $telegram_chat_id, $sms_message, $telegram_crew['success'] ? 'sent' : 'failed', json_encode($telegram_crew));

    $eitaa_chat_id = getenv('EITAA_CREW_CHAT_ID');
    if($eitaa_chat_id === false || $eitaa_chat_id === '') {
        $eitaa_chat_id = getenv('EITAA_CHAT_ID') ?: '';
    }
    $eitaa_crew = sendBotMessage(
        getenv('EITAA_BOT_BASE_URL') ?: 'https://eitaayar.ir/api',
        getenv('EITAA_BOT_TOKEN') ?: '',
        $eitaa_chat_id,
        $sms_message
    );
    insertNotificationRecord($db_connection, $incident_id, 'eitaa', $eitaa_chat_id, $sms_message, $eitaa_crew['success'] ? 'sent' : 'failed', json_encode($eitaa_crew));

    $dispatch_stmt = $db_connection->prepare(
        'INSERT INTO dispatches (incident_id, station_id, dispatched_by, dispatched_at, status)
        VALUES (:incident_id, :station_id, :dispatched_by, :dispatched_at, :status)'
    );
    $dispatched_at = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $dispatch_status = 'dispatched';
    $dispatch_stmt->execute(array(
        ':incident_id' => $incident_id,
        ':station_id' => $station_id,
        ':dispatched_by' => null,
        ':dispatched_at' => $dispatched_at,
        ':status' => $dispatch_status,
    ));

    $update_incident = $db_connection->prepare(
        "UPDATE incidents SET status = 'dispatched', dispatched_station_id = :station_id, updated_at = CURRENT_TIMESTAMP WHERE id = :incident_id"
    );
    $update_incident->execute(array(
        ':station_id' => $station_id,
        ':incident_id' => $incident_id,
    ));

    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array(
        'success' => true,
        'incident_id' => $incident_id,
        'station_id' => $station_id,
        'shift_group' => $shift_group,
        'mission_link' => $mission_link,
        'recipients' => $recipients,
        'sms_result' => $sms_result,
        'telegram' => $telegram_crew,
        'eitaa' => $eitaa_crew,
    ));
});

\Flight::route('GET /api/admin/stations', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare('SELECT id, name, lat, lng, is_active FROM stations ORDER BY id ASC');
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('POST /api/admin/stations', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if(is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON payload'));
        return;
    }
    $name = trim((string)($payload['name'] ?? ''));
    $lat = $payload['lat'] ?? null;
    $lng = $payload['lng'] ?? null;
    $is_active = isset($payload['is_active']) ? (bool)$payload['is_active'] : true;
    if($name === '' || $lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'name, lat, lng are required'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'INSERT INTO stations (name, lat, lng, is_active) VALUES (:name, :lat, :lng, :is_active)'
    );
    $stmt->execute(array(
        ':name' => $name,
        ':lat' => $lat,
        ':lng' => $lng,
        ':is_active' => $is_active,
    ));
    $id = $db_connection->lastInsertId();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'id' => $id));
});

\Flight::route('PUT /api/admin/stations/@station_id', function ($station_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $station_id = (int)$station_id;
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if($station_id <= 0 || is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid input'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'UPDATE stations SET name = :name, lat = :lat, lng = :lng, is_active = :is_active WHERE id = :id'
    );
    $stmt->execute(array(
        ':name' => trim((string)($payload['name'] ?? '')),
        ':lat' => $payload['lat'] ?? null,
        ':lng' => $payload['lng'] ?? null,
        ':is_active' => isset($payload['is_active']) ? (bool)$payload['is_active'] : true,
        ':id' => $station_id,
    ));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('DELETE /api/admin/stations/@station_id', function ($station_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $station_id = (int)$station_id;
    if($station_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid station id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare('DELETE FROM stations WHERE id = :id');
    $stmt->execute(array(':id' => $station_id));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('GET /api/admin/firefighters', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $station_id = isset($_GET['station_id']) ? (int)$_GET['station_id'] : null;
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if($firehall === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'No active firehall configured'));
        return;
    }
    $db = new \riprunner\DbConnection($firehall);
    $db_connection = $db->getConnection();
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $sql = 'SELECT id, station_id, name, phone, role_in_station, shift_group, is_active FROM firefighters';
    if($station_id !== null && $station_id > 0) {
        $sql .= ' WHERE station_id = :station_id';
    }
    $sql .= ' ORDER BY station_id, id';
    $stmt = $db_connection->prepare($sql);
    if($station_id !== null && $station_id > 0) {
        $stmt->bindParam(':station_id', $station_id, \PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('POST /api/admin/firefighters', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if(is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON payload'));
        return;
    }
    $station_id = isset($payload['station_id']) ? (int)$payload['station_id'] : 0;
    $name = trim((string)($payload['name'] ?? ''));
    $phone = trim((string)($payload['phone'] ?? ''));
    if($station_id <= 0 || $name === '' || $phone === '') {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'station_id, name, phone are required'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'INSERT INTO firefighters (station_id, name, phone, role_in_station, shift_group, is_active)
        VALUES (:station_id, :name, :phone, :role_in_station, :shift_group, :is_active)'
    );
    $stmt->execute(array(
        ':station_id' => $station_id,
        ':name' => $name,
        ':phone' => $phone,
        ':role_in_station' => $payload['role_in_station'] ?? null,
        ':shift_group' => $payload['shift_group'] ?? null,
        ':is_active' => isset($payload['is_active']) ? (bool)$payload['is_active'] : true,
    ));
    $id = $db_connection->lastInsertId();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'id' => $id));
});

\Flight::route('PUT /api/admin/firefighters/@firefighter_id', function ($firefighter_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $firefighter_id = (int)$firefighter_id;
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if($firefighter_id <= 0 || is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid input'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'UPDATE firefighters
        SET station_id = :station_id, name = :name, phone = :phone, role_in_station = :role_in_station,
            shift_group = :shift_group, is_active = :is_active
        WHERE id = :id'
    );
    $stmt->execute(array(
        ':station_id' => isset($payload['station_id']) ? (int)$payload['station_id'] : null,
        ':name' => trim((string)($payload['name'] ?? '')),
        ':phone' => trim((string)($payload['phone'] ?? '')),
        ':role_in_station' => $payload['role_in_station'] ?? null,
        ':shift_group' => $payload['shift_group'] ?? null,
        ':is_active' => isset($payload['is_active']) ? (bool)$payload['is_active'] : true,
        ':id' => $firefighter_id,
    ));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('DELETE /api/admin/firefighters/@firefighter_id', function ($firefighter_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $firefighter_id = (int)$firefighter_id;
    if($firefighter_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid firefighter id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare('DELETE FROM firefighters WHERE id = :id');
    $stmt->execute(array(':id' => $firefighter_id));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('GET /api/admin/shift-rules', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'SELECT id, station_id, json_rule, rotation_start, change_time, timezone FROM shift_rules ORDER BY station_id'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'results' => $rows));
});

\Flight::route('POST /api/admin/shift-rules', function () {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if(is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON payload'));
        return;
    }
    $station_id = isset($payload['station_id']) ? (int)$payload['station_id'] : 0;
    $json_rule = $payload['json_rule'] ?? null;
    $rotation_start = $payload['rotation_start'] ?? null;
    $change_time = $payload['change_time'] ?? null;
    $timezone = $payload['timezone'] ?? 'Asia/Tehran';
    if($station_id <= 0 || $json_rule === null || $rotation_start === null || $change_time === null) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'station_id, json_rule, rotation_start, change_time are required'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'INSERT INTO shift_rules (station_id, json_rule, rotation_start, change_time, timezone)
        VALUES (:station_id, :json_rule, :rotation_start, :change_time, :timezone)'
    );
    $stmt->execute(array(
        ':station_id' => $station_id,
        ':json_rule' => is_string($json_rule) ? $json_rule : json_encode($json_rule, JSON_UNESCAPED_UNICODE),
        ':rotation_start' => $rotation_start,
        ':change_time' => $change_time,
        ':timezone' => $timezone,
    ));
    $id = $db_connection->lastInsertId();
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true, 'id' => $id));
});

\Flight::route('PUT /api/admin/shift-rules/@rule_id', function ($rule_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $rule_id = (int)$rule_id;
    $payload = json_decode(file_get_contents('php://input') ?? '', true);
    if($rule_id <= 0 || is_array($payload) === false) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid input'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare(
        'UPDATE shift_rules
        SET station_id = :station_id, json_rule = :json_rule, rotation_start = :rotation_start,
            change_time = :change_time, timezone = :timezone
        WHERE id = :id'
    );
    $stmt->execute(array(
        ':station_id' => isset($payload['station_id']) ? (int)$payload['station_id'] : null,
        ':json_rule' => is_string($payload['json_rule'] ?? null) ? $payload['json_rule'] : json_encode($payload['json_rule'] ?? null, JSON_UNESCAPED_UNICODE),
        ':rotation_start' => $payload['rotation_start'] ?? null,
        ':change_time' => $payload['change_time'] ?? null,
        ':timezone' => $payload['timezone'] ?? 'Asia/Tehran',
        ':id' => $rule_id,
    ));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('DELETE /api/admin/shift-rules/@rule_id', function ($rule_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin')) === false) {
        return;
    }
    $rule_id = (int)$rule_id;
    if($rule_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid rule id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $stmt = $db_connection->prepare('DELETE FROM shift_rules WHERE id = :id');
    $stmt->execute(array(':id' => $rule_id));
    \riprunner\DbConnection::disconnect_db($db_connection);
    echo json_encode(array('success' => true));
});

\Flight::route('GET /api/stations/@station_id/active-shift', function ($station_id) {
    global $FIREHALLS;
    header('Content-Type: application/json');
    if(require_api_role(array('admin', 'dispatcher')) === false) {
        return;
    }
    $station_id = (int)$station_id;
    if($station_id <= 0) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'message' => 'Invalid station id'));
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
    if($db_connection === null) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'message' => 'Database connection unavailable'));
        return;
    }
    $result = null;
    try {
        $result = getActiveShiftForStation($db_connection, $station_id);
    }
    catch(\Exception $ex) {
        \riprunner\DbConnection::disconnect_db($db_connection);
        http_response_code(500);
        echo json_encode(array('success' => false, 'message' => 'Unable to resolve active shift', 'error' => $ex->getMessage()));
        return;
    }
    \riprunner\DbConnection::disconnect_db($db_connection);
    if($result === null) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'Shift rule not found'));
        return;
    }
    echo json_encode(array('success' => true, 'data' => $result));
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

\Flight::route('GET|POST /m/@token', function ($token) {
    $_GET['token'] = $token;
    require_once __DIR__ . '/controllers/mission-view-controller.php';
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
