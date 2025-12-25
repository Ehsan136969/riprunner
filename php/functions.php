<?php
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================

if ( defined('INCLUSION_PERMITTED') === false ||
    (defined('INCLUSION_PERMITTED') === true && INCLUSION_PERMITTED === false)) { 
	die( 'This file must not be invoked directly.' ); 
}

require_once 'db/db_connection.php';
require_once 'db/sql_statement.php';
require_once 'ldap_functions.php';
require_once 'url/http-cli.php';
require_once 'config/config_manager.php';
require_once 'core/CalloutStatusType.php';
require_once 'common_functions.php';
require_once 'logging.php';

function getAddressForMapping($FIREHALL, $address) {
	//global $log;
	//$log->trace("About to find google map address for [$address]");
	
	$result_address = $address;
	
	$streetSubstList = $FIREHALL->WEBSITE->WEBSITE_CALLOUT_DETAIL_STREET_NAME_SUBSTITUTION;
	if(isset($streetSubstList) === true && $streetSubstList !== null && safe_count($streetSubstList) > 0) {
		foreach($streetSubstList as $sourceStreetName => $destStreetName) {
			$result_address = str_replace($sourceStreetName, $destStreetName, $result_address);
		}
	}
	
	//$log->trace("After street subst map address is [$result_address]");
	
	$citySubstList = $FIREHALL->WEBSITE->WEBSITE_CALLOUT_DETAIL_CITY_NAME_SUBSTITUTION;
	if(isset($citySubstList) === true && $citySubstList !== null && safe_count($citySubstList) > 0) {
		foreach($citySubstList as $sourceCityName => $destCityName) {
			$result_address = str_replace($sourceCityName, $destCityName, $result_address);
		}
	}
	
	//$log->trace("After city subst map address is [$result_address]");
	
	return $result_address;
}

function getGEOCoordinatesFromAddress($FIREHALL, $address) {
	global $log;
		
	$result_geo_coords = null;
	$result_address = $address;

	$result_address = getAddressForMapping($FIREHALL, $address);
    $webRoot = rtrim($FIREHALL->WEBSITE->WEBSITE_ROOT_URL, '/');
	$url = $webRoot . '/mapapiprxy/geocode/json?address=' . urlencode($result_address) . '&sensor=false';

    if($log !== null) $log->warn("GEO MAP JSON [$url]");
	$httpclient = new \riprunner\HTTPCli($url);
	$result = $httpclient->execute(true);
	
	$geoloc = json_decode($result, true);
		
	if ( isset($geoloc['results']) === true &&
		 isset($geoloc['results'][0]) === true &&
		 isset($geoloc['results'][0]['geometry']) === true &&
		 isset($geoloc['results'][0]['geometry']['location']) === true &&
		 isset($geoloc['results'][0]['geometry']['location']['lat']) === true &&
		 isset($geoloc['results'][0]['geometry']['location']['lng']) === true) {
			
		$result_geo_coords = array( $geoloc['results'][0]['geometry']['location']['lat'],
									$geoloc['results'][0]['geometry']['location']['lng']);
	}
	else {
		if($log !== null) {
            $log->warn("GEO MAP JSON response error google geo api url [$url] result [$result]");
            echo "GEO #1 MAP JSON response error google geo api url [$url] result [$result]".PHP_EOL;
        }
        else {
            echo "GEO #2 MAP JSON response error google geo api url [$url] result [$result]".PHP_EOL;
        }
	}

	return $result_geo_coords;
}
	
function findFireHallConfigById($fhid, $list) {
    global $log;
	foreach ($list as &$firehall) {
	    //$log->trace("Scanning for fhid [$fhid] compare with [$firehall->FIREHALL_ID]");
		if((string)$firehall->FIREHALL_ID === (string)$fhid) {
			return $firehall;
		}
	}
	if($log !== null) $log->error("Scanning for fhid [$fhid] NOT FOUND!");
	return null;
}

function getFirstActiveFireHallConfig($list) {
    global $log;
	foreach ($list as &$firehall) {
		if($firehall->ENABLED == true) {
			return $firehall;
		}
		else {
		    $log->trace("In getFirstActiveFireHallConfig skipping: ".$firehall->toString());
		}
	}
	return null;
}

function getUserNameFromMobilePhone($FIREHALL, $db_connection, $matching_sms_user) {
    global $log;

    // Find matching user for mobile #
    $must_close_db = false;
    if(isset($db_connection) === false) {
        $db = new \riprunner\DbConnection($FIREHALL);
        $db_connection = $db->getConnection();
    
        $must_close_db = true;
    }
    
    $sql_statement = new \riprunner\SqlStatement($db_connection);

    if($FIREHALL->LDAP->ENABLED == true) {
        create_temp_users_table_for_ldap($FIREHALL, $db_connection);

        $sql = $sql_statement->getSqlStatement('ldap_user_accounts_select_by_mobile');
    }
    else {
        $sql = $sql_statement->getSqlStatement('user_accounts_select_by_mobile');
    }

    $qry_bind = $db_connection->prepare($sql);
    $qry_bind->bindParam(':fhid', $FIREHALL->FIREHALL_ID);
    $qry_bind->bindParam(':mobile_phone', $matching_sms_user);

    $qry_bind->execute();

    $rows = $qry_bind->fetchAll(\PDO::FETCH_OBJ);
    $qry_bind->closeCursor();

    if($log !== null) $log->trace("SMS Host got firehall_id [$FIREHALL->FIREHALL_ID] mobile [$matching_sms_user] got count: " . safe_count($rows));

    if($must_close_db === true) {
        \riprunner\DbConnection::disconnect_db( $db_connection );
    }
    
    foreach($rows as $row){
        //$result->setUserAccountId($row->id);
        //$result->setUserId($row->user_id);
        return $row->user_id;
    }
    return null;
}

function getMobilePhoneListFromDB($FIREHALL, $db_connection, $filtered_sms_users=null, $include_admins=null) {
	global $log;

	$must_close_db = false;
	if(isset($db_connection) === false) {
	    $db = new \riprunner\DbConnection($FIREHALL);
	    $db_connection = $db->getConnection();
	     
		$must_close_db = true;
	}
    
    $sql_admin_access = USER_ACCESS_ADMIN . " = ". USER_ACCESS_ADMIN;
	$sql_sms_access = USER_ACCESS_SIGNAL_SMS . " = ". USER_ACCESS_SIGNAL_SMS;
	$sql_statement = new \riprunner\SqlStatement($db_connection);
    $sql = $sql_statement->getSqlStatement('users_mobile_access_list');
    if($include_admins != null && $include_admins === true) {
        $sql = preg_replace_callback('(:sms_access)', function ($m) use ($sql_sms_access, $sql_admin_access) { 
            $m; 
            return "$sql_sms_access OR access & $sql_admin_access"; 
        }, $sql);
    }
    else {
        $sql = preg_replace_callback('(:sms_access)', function ($m) use ($sql_sms_access) {
            $m;
            return $sql_sms_access;
        }, $sql);
    }

    if($log !== null) $log->trace("Call getMobilePhoneListFromDB SQL text for sql [$sql]");

	$qry_bind = $db_connection->prepare($sql);
	$qry_bind->execute();
	
	$rows = $qry_bind->fetchAll(\PDO::FETCH_OBJ);
	$qry_bind->closeCursor();
	
	if($log !== null) $log->trace("Call getMobilePhoneListFromDB SQL success for sql [$sql] row count: " . safe_count($rows));
	
	$result = array();
	foreach($rows as $row) {
	    if($filtered_sms_users == null || in_array($row->id,$filtered_sms_users) == true)
		array_push($result, $row->mobile_phone);
	}
	
	if($must_close_db === true) {
		\riprunner\DbConnection::disconnect_db( $db_connection );
	}
	return $result;
}

function normalizeShiftNow($now, $timezone) {
    if($now instanceof \DateTimeInterface) {
        $timestamp = $now->getTimestamp();
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    }
    if($now !== null && $now !== '') {
        return new \DateTimeImmutable($now, $timezone);
    }
    return new \DateTimeImmutable('now', $timezone);
}

function resolveShiftRule($rule_json) {
    if($rule_json === null || $rule_json === '') {
        return null;
    }
    $decoded = json_decode($rule_json, true);
    if(!is_array($decoded)) {
        return null;
    }
    $sequence = isset($decoded['sequence']) && is_array($decoded['sequence']) ? $decoded['sequence'] : array();
    $normalized_sequence = array();
    foreach($sequence as $entry) {
        $value = trim((string)$entry);
        if($value !== '') {
            $normalized_sequence[] = strtoupper($value);
        }
    }
    $startShift = $decoded['startShift'] ?? null;
    if($startShift !== null) {
        $startShift = strtoupper(trim((string)$startShift));
    }
    $rotationDays = isset($decoded['rotationDays']) ? (int)$decoded['rotationDays'] : 1;
    if($rotationDays <= 0) {
        $rotationDays = 1;
    }
    return array(
        'sequence' => $normalized_sequence,
        'startShift' => $startShift,
        'rotationDays' => $rotationDays,
    );
}

function getActiveShiftForStation($db_connection, $station_id, $now = null) {
    if($db_connection === null) {
        throw new \Exception('Database connection not provided.');
    }
    $stmt = $db_connection->prepare('SELECT json_rule, rotation_start, change_time, timezone FROM shift_rules WHERE station_id = :station_id LIMIT 1');
    $stmt->bindParam(':station_id', $station_id);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if($row === false) {
        return null;
    }

    $rule = resolveShiftRule($row['json_rule'] ?? null);
    if($rule === null || safe_count($rule['sequence']) === 0) {
        return null;
    }

    $timezone = new \DateTimeZone($row['timezone'] ?? 'Asia/Tehran');
    $rotation_start = $row['rotation_start'] ?? '';
    $change_time = $row['change_time'] ?? '08:00:00';
    if($rotation_start === null || trim($rotation_start) === '') {
        return null;
    }

    $rotation_boundary = new \DateTimeImmutable(trim($rotation_start . ' ' . $change_time), $timezone);
    $now_time = normalizeShiftNow($now, $timezone);

    $seconds_since = $now_time->getTimestamp() - $rotation_boundary->getTimestamp();
    $days_since = (int)floor($seconds_since / 86400);

    $rotation_days = $rule['rotationDays'];
    $periods_since = (int)floor($days_since / $rotation_days);

    $sequence = $rule['sequence'];
    $sequence_count = safe_count($sequence);
    $start_index = array_search($rule['startShift'], $sequence, true);
    if($start_index === false) {
        $start_index = 0;
    }
    $shift_index = ($start_index + $periods_since) % $sequence_count;
    if($shift_index < 0) {
        $shift_index += $sequence_count;
    }

    $shift_start = $rotation_boundary->modify('+' . ($periods_since * $rotation_days) . ' days');
    $shift_end = $shift_start->modify('+' . $rotation_days . ' days');

    return array(
        'shift_group' => $sequence[$shift_index],
        'shift_start' => $shift_start->format('Y-m-d H:i:s'),
        'shift_end' => $shift_end->format('Y-m-d H:i:s'),
        'rotation_start' => $rotation_start,
        'change_time' => $change_time,
        'timezone' => $timezone->getName(),
        'rule' => $rule,
    );
}

function computeDistanceKm($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371;
    $lat1_rad = deg2rad((float)$lat1);
    $lat2_rad = deg2rad((float)$lat2);
    $delta_lat = deg2rad((float)$lat2 - (float)$lat1);
    $delta_lng = deg2rad((float)$lng2 - (float)$lng1);

    $a = sin($delta_lat / 2) * sin($delta_lat / 2) +
        cos($lat1_rad) * cos($lat2_rad) *
        sin($delta_lng / 2) * sin($delta_lng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth_radius * $c;
}

function findNearestStation($stations, $lat, $lng) {
    $closest = null;
    $closest_distance = null;
    foreach($stations as $station) {
        $station_lat = isset($station['lat']) ? $station['lat'] : null;
        $station_lng = isset($station['lng']) ? $station['lng'] : null;
        if($station_lat === null || $station_lng === null) {
            continue;
        }
        $distance = computeDistanceKm($lat, $lng, $station_lat, $station_lng);
        if($closest_distance === null || $distance < $closest_distance) {
            $closest_distance = $distance;
            $closest = $station;
            $closest['distance_km'] = round($distance, 3);
        }
    }
    return $closest;
}

function sendBotMessage($base_url, $token, $chat_id, $text) {
    if($token === null || $token === '' || $chat_id === null || $chat_id === '') {
        return array('success' => false, 'message' => 'not_configured');
    }
    $base_url = rtrim($base_url, '/');
    $url = $base_url . '/bot' . $token . '/sendMessage';
    $data = array(
        'chat_id' => $chat_id,
        'text' => $text,
    );
    $s = curl_init();
    curl_setopt($s, CURLOPT_URL, $url);
    curl_setopt($s, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($s, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($s);
    $error = curl_errno($s) ? curl_error($s) : null;
    curl_close($s);
    if($error !== null) {
        return array('success' => false, 'message' => $error);
    }
    $decoded = json_decode($result, true);
    if(isset($decoded['ok'])) {
        return array('success' => (bool)$decoded['ok']);
    }
    return array('success' => false, 'message' => 'unexpected_response');
}

function getFirefightersForStationShift($db_connection, $station_id, $shift_group) {
    if($db_connection === null) {
        throw new \Exception('Database connection not provided.');
    }
    $shift_group = strtoupper(trim((string)$shift_group));
    $stmt = $db_connection->prepare(
        'SELECT id, name, phone FROM firefighters
        WHERE station_id = :station_id AND shift_group = :shift_group AND is_active = true'
    );
    $stmt->execute(array(
        ':station_id' => $station_id,
        ':shift_group' => $shift_group,
    ));
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    return $rows;
}

function insertNotificationRecord($db_connection, $incident_id, $channel, $recipient, $message, $status, $provider_response = null) {
    if($db_connection === null) {
        return;
    }
    $stmt = $db_connection->prepare(
        'INSERT INTO notifications (incident_id, channel, recipient, message, status, provider_response)
        VALUES (:incident_id, :channel, :recipient, :message, :status, :provider_response)'
    );
    $stmt->execute(array(
        ':incident_id' => $incident_id,
        ':channel' => $channel,
        ':recipient' => $recipient,
        ':message' => $message,
        ':status' => $status,
        ':provider_response' => $provider_response,
    ));
}

function getEmailListFromDB($FIREHALL, $db_connection) {
    global $log;
    
    $must_close_db = false;
    if(isset($db_connection) === false) {
        $db = new \riprunner\DbConnection($FIREHALL);
        $db_connection = $db->getConnection();
        
        $must_close_db = true;
    }
    
    //$sql_sms_access = USER_ACCESS_SIGNAL_SMS . " = ". USER_ACCESS_SIGNAL_SMS;
    $sql_statement = new \riprunner\SqlStatement($db_connection);
    $sql = $sql_statement->getSqlStatement('users_email_list');
    //$sql = preg_replace_callback('(:sms_access)', function ($m) use ($sql_sms_access) { $m; return $sql_sms_access; }, $sql);
    
    $qry_bind = $db_connection->prepare($sql);
    $qry_bind->execute();
    
    $rows = $qry_bind->fetchAll(\PDO::FETCH_OBJ);
    $qry_bind->closeCursor();
    
    if($log !== null) $log->trace("Call getEmailListFromDB SQL success for sql [$sql] row count: " . safe_count($rows));
    
//     $result = array();
//     foreach($rows as $row) {
//         array_push($result, $row->mobile_phone);
//     }
    
    if($must_close_db === true) {
        \riprunner\DbConnection::disconnect_db( $db_connection );
    }
    return $rows;
}

function getCallStatusDisplayText($dbStatus, $FIREHALL) {
	$result = 'unknown [' . ((isset($dbStatus) === true) ? $dbStatus : 'null') . ']';
	if(\riprunner\CalloutStatusType::isValidValue($dbStatus, $FIREHALL) == true) {
	    $result = \riprunner\CalloutStatusType::getStatusById($dbStatus, $FIREHALL)->getDisplayName();
	}
	else if(\riprunner\CalloutStatusType::isValidName($dbStatus, $FIREHALL) == true) {
	    $result = \riprunner\CalloutStatusType::getStatusByName($dbStatus, $FIREHALL)->getDisplayName();
	}
	return $result;
}

function isCalloutInProgress($callout_status, $FIREHALL) {
    if(isset($callout_status) === true) {
        if(\riprunner\CalloutStatusType::isValidValue($callout_status, $FIREHALL) == true) {
            $result = \riprunner\CalloutStatusType::getStatusById($callout_status, $FIREHALL);
            return !($result->IsCancelled($FIREHALL) || $result->IsCompleted($FIREHALL));
        }
    }
	return true;
}

function validateDate($date, $format='Y-m-d H:i:s') {
	$date_format = DateTime::createFromFormat($format, $date);
	return $date_format && $date_format->format($format) == $date;
}

function getFirehallRootURLFromRequest($request_url, $firehalls, $use_firehall=null) {
	global $log;
	
    if ($use_firehall !== null || safe_count($firehalls) === 1) {
        if ($use_firehall !== null) {
            if ($log !== null) $log->trace("#1 Looking for website root URL req [$request_url] use_firehall root [" . $use_firehall->WEBSITE->WEBSITE_ROOT_URL . "]");
            return rtrim($use_firehall->WEBSITE->WEBSITE_ROOT_URL, '/');
        } 
        else {
            if ($log !== null) $log->trace("#1 Looking for website root URL req [$request_url] firehall root [" . $firehalls[0]->WEBSITE->WEBSITE_ROOT_URL . "]");
            return rtrim($firehalls[0]->WEBSITE->WEBSITE_ROOT_URL, '/');
        }
	}
	else {
		if(isset($request_url) === false && isset($_SERVER['REQUEST_URI']) === true) {
			$request_url = htmlspecialchars($_SERVER['REQUEST_URI']);
		}
		foreach ($firehalls as &$firehall) {
			if($log !== null) $log->trace("#2 Looking for website root URL req [$request_url] firehall root [" . $firehall->WEBSITE->WEBSITE_ROOT_URL . "]");
			
			if($firehall->ENABLED == true && 
					strpos($request_url ?? '', $firehall->WEBSITE->WEBSITE_ROOT_URL) === 0) {
				return rtrim($firehall->WEBSITE->WEBSITE_ROOT_URL, '/');
			}
		}
		
		$url_parts = explode('/', $request_url);
		if(isset($url_parts)  === true && safe_count($url_parts) > 0) {
			$url_parts_count = safe_count($url_parts);
			
			foreach ($firehalls as &$firehall) {
				if($log !== null) $log->trace("#3 Looking for website root URL req [$request_url] firehall root [" . $firehall->WEBSITE->WEBSITE_ROOT_URL . "]");
				
				$fh_parts = explode('/', $firehall->WEBSITE->WEBSITE_ROOT_URL);
				if(isset($fh_parts)  === true && safe_count($fh_parts) > 0) {
					$fh_parts_count = safe_count($fh_parts);
					
					for($index_fh = 0; $index_fh < $fh_parts_count; $index_fh++) {
						for($index = 0; $index < $url_parts_count; $index++) {
							if($log !== null) $log->trace("#3 fhpart [" .  $fh_parts[$index_fh] . "] url part [" . $url_parts[$index] . "]");
							
							if($fh_parts[$index_fh] !== '' && $url_parts[$index] !== '' &&
								$fh_parts[$index_fh] === $url_parts[$index]) {

                                    if($log !== null) $log->trace("#3 website matched!");
								return rtrim($firehall->WEBSITE->WEBSITE_ROOT_URL, '/');
							}
						}
					}
				}
			}
		}
	}
	return '';
}

function getTriggerHashList($type, $FIREHALL, $db_connection) {

    $adhoc_db = false;
    if($db_connection === null) {
        $db = new \riprunner\DbConnection($FIREHALL);
        $db_connection = $db->getConnection();
        $adhoc_db = true;
    }
    
    $firehall_id = $FIREHALL->FIREHALL_ID;
    
    $result = array();
    
    $sql_statement = new \riprunner\SqlStatement($db_connection);
    $sql = $sql_statement->getSqlStatement('check_trigger_history_by_type');
    
    $qry_bind = $db_connection->prepare($sql);
    if ($qry_bind !== false) {
        $qry_bind->bindParam(':type', $type);
        $qry_bind->bindParam(':fhid', $firehall_id);
        $qry_bind->execute();
    
    	$rows = $qry_bind->fetchAll(\PDO::FETCH_OBJ);
    	$qry_bind->closeCursor();
    	    	
    	foreach($rows as $row) {
    		array_push($result, $row->hash_data);
    	}
    }
    
    if($adhoc_db === true) {
        \riprunner\DbConnection::disconnect_db($db_connection);
    }
    return $result;
}
function addTriggerHash($type, $FIREHALL, $hash_data, $db_connection) {
    
    $adhoc_db = false;
    if($db_connection === null) {
        $db = new \riprunner\DbConnection($FIREHALL);
        $db_connection = $db->getConnection();
        $adhoc_db = true;
    }
    
    $firehall_id = $FIREHALL->FIREHALL_ID;

    $sql_statement = new \riprunner\SqlStatement($db_connection);
    $sql = $sql_statement->getSqlStatement('trigger_history_insert');
     
    $qry_bind = $db_connection->prepare($sql);
    $qry_bind->bindParam(':type', $type);
    $qry_bind->bindParam(':fhid', $firehall_id);
    $qry_bind->bindParam(':hash_data', $hash_data);
    $qry_bind->execute();
    
    $result = $qry_bind->rowCount();
    
    if($adhoc_db === true) {
        \riprunner\DbConnection::disconnect_db($db_connection);        
    }
    return $result;
}

function validate_email_sender($FIREHALL, $from) {
    global $log;

    $valid_email_trigger = true;

    if(isset($FIREHALL->EMAIL->EMAIL_FROM_TRIGGER) === true &&
        $FIREHALL->EMAIL->EMAIL_FROM_TRIGGER !== null &&
        $FIREHALL->EMAIL->EMAIL_FROM_TRIGGER !== '') {
    
        $valid_email_trigger = false;
        if(isset($from) === true && $from !== null) {
            if($log !== null) $log->warn('Email trigger check on From field for ['.$FIREHALL->EMAIL->EMAIL_FROM_TRIGGER.']');
            
            $valid_email_from_triggers = explode(';', $FIREHALL->EMAIL->EMAIL_FROM_TRIGGER);
            foreach($valid_email_from_triggers as $valid_email_from_trigger) {
    
                if($log !== null) $log->warn('Email trigger check on From field for ['.$valid_email_from_trigger.']');
    

                // Match on exact email address if @ in trigger text
                $valid_email_from_trigger_parts = explode('@', $valid_email_from_trigger);
                if(strpos($valid_email_from_trigger ?? '', '@') !== false && 
                safe_count($valid_email_from_trigger_parts) > 1 &&
                        $valid_email_from_trigger_parts[0] !== '') {
                    $fromaddr = $from;
                }
                // Match on all email addresses from the same domain
                else {
                    if(safe_count($valid_email_from_trigger_parts) > 1 &&
                        $valid_email_from_trigger_parts[0] === '') {
                        $valid_email_from_trigger = $valid_email_from_trigger_parts[1];
                    }
                    
                    $fromaddr = explode('@', $from);
                    if(safe_count($fromaddr) > 1) {
                        $fromaddr = $fromaddr[1];
                    }
                }
                 
                if($fromaddr === $valid_email_from_trigger) {
                    $valid_email_trigger = true;
                }
                 
                if($log !== null) $log->warn("Email trigger check on From field result: " . 
                        (($valid_email_trigger === true) ? "true" : "false") . " for value [$fromaddr]".
                        " expected [$valid_email_from_trigger]");
                
                if($valid_email_trigger === true) {
                    break;
                }
            }
        }
        else {
            if($log !== null) $log->warn("Email webhook trigger check from field Error not set!");
        }
    }
    
    return $valid_email_trigger;
}

function gen_uuid($len=8) {
    $hex = md5(JWT_KEY . uniqid("", true));
    //$hex = uniqid('', true);

    $pack = pack('H*', $hex);

    $uid = base64_encode($pack);        // max 22 chars

    $uid = preg_replace("#(*UTF8)[^A-Za-z0-9]#", "", $uid);    // mixed case
    //$uid = ereg_replace("[^A-Z0-9]", "", strtoupper($uid));    // uppercase only

    if ($len<4)
        $len=4;
    if ($len>128)
        $len=128;                       // prevent silliness, can remove

    while (strlen($uid)<$len)
        $uid = $uid . gen_uuid(22);     // append until length achieved

    return substr($uid, 0, $len);
}
