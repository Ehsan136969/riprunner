<?php
// ==============================================================
//  FireKhoy Mission View Controller
// ==============================================================
namespace riprunner;

define('INCLUSION_PERMITTED', true);

if(defined('__RIPRUNNER_ROOT__') === false) {
    define('__RIPRUNNER_ROOT__', dirname(dirname(__FILE__)));
}

require_once __RIPRUNNER_ROOT__ . '/template.php';
require_once __RIPRUNNER_ROOT__ . '/models/global-model.php';
require_once __RIPRUNNER_ROOT__ . '/db/db_connection.php';
require_once __RIPRUNNER_ROOT__ . '/logging.php';
require_once __RIPRUNNER_ROOT__ . '/functions.php';

$token = get_query_param('token');
$error_message = null;
$mission = null;

try {
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if ($firehall == null) {
        throw new \Exception('پیکربندی ایستگاه یافت نشد.');
    }

    $db = new DbConnection($firehall);
    $pdo = $db->getConnection();
    if ($pdo == null) {
        throw new \Exception('اتصال پایگاه داده در دسترس نیست.');
    }

    if ($token !== null && $token !== '') {
        $stmt = $pdo->prepare('SELECT * FROM incident_dispatch_tokens WHERE token = :token LIMIT 1');
        $stmt->execute(array(':token' => $token));
        $token_row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($token_row) {
            if (!empty($token_row['expires_at']) && strtotime($token_row['expires_at']) < time()) {
                throw new \Exception('این لینک منقضی شده است.');
            }
            $incident_stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id LIMIT 1');
            $incident_stmt->execute(array(':id' => $token_row['incident_id']));
            $incident = $incident_stmt->fetch(\PDO::FETCH_ASSOC);
            $incident_stmt->closeCursor();

            $location_stmt = $pdo->prepare(
                'SELECT lat, lng FROM incident_locations WHERE incident_id = :incident_id ORDER BY created_at DESC, id DESC LIMIT 1'
            );
            $location_stmt->execute(array(':incident_id' => $token_row['incident_id']));
            $location = $location_stmt->fetch(\PDO::FETCH_ASSOC);
            $location_stmt->closeCursor();

            $station_stmt = $pdo->prepare('SELECT id, name FROM stations WHERE id = :id LIMIT 1');
            $station_stmt->execute(array(':id' => $incident['dispatched_station_id']));
            $station = $station_stmt->fetch(\PDO::FETCH_ASSOC);
            $station_stmt->closeCursor();

            $mission = array(
                'incident' => $incident,
                'location' => $location,
                'station' => $station,
                'token' => $token,
            );
        }
        else {
            throw new \Exception('لینک معتبر نیست یا منقضی شده است.');
        }
    }
    else {
        throw new \Exception('لینک معتبر نیست یا منقضی شده است.');
    }
}
catch (\Exception $ex) {
    $error_message = $ex->getMessage();
}

$view_template_vars['mission'] = $mission;
$view_template_vars['error_message'] = $error_message;

$template = $twig->resolveTemplate(
    array('@custom/mission-view-custom.twig.html', 'mission-view.twig.html'));

echo $template->render($view_template_vars);
