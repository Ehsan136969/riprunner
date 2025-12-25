<?php
// ==============================================================
//  FireKhoy Incident Location Controller
// ==============================================================
namespace riprunner;

define('INCLUSION_PERMITTED', true);

if(defined('__RIPRUNNER_ROOT__') === false) {
    define('__RIPRUNNER_ROOT__', dirname(dirname(__FILE__)));
}

require_once __RIPRUNNER_ROOT__ . '/template.php';
require_once __RIPRUNNER_ROOT__ . '/authentication/authentication.php';
require_once __RIPRUNNER_ROOT__ . '/models/global-model.php';
require_once __RIPRUNNER_ROOT__ . '/db/db_connection.php';
require_once __RIPRUNNER_ROOT__ . '/logging.php';
require_once __RIPRUNNER_ROOT__ . '/functions.php';

$token = get_query_param('token');
$submitted = false;
$success = false;
$error_message = null;
$incident = null;
$token_row = null;

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
        $stmt = $pdo->prepare('SELECT * FROM incident_tokens WHERE token = :token LIMIT 1');
        $stmt->execute(array(':token' => $token));
        $token_row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = true;
        if ($token_row == null) {
            throw new \Exception('لینک معتبر نیست یا منقضی شده است.');
        }
        if (!empty($token_row['consumed_at'])) {
            throw new \Exception('این لینک قبلاً استفاده شده است.');
        }
        if (!empty($token_row['expires_at']) && strtotime($token_row['expires_at']) < time()) {
            throw new \Exception('این لینک منقضی شده است.');
        }
        $lat = get_query_param('lat');
        $lng = get_query_param('lng');
        $source = get_query_param('source');
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            throw new \Exception('مختصات ارسال نشده است.');
        }
        $incident_id = $token_row['incident_id'];
        $insert = $pdo->prepare('INSERT INTO incident_locations (incident_id, lat, lng, source) VALUES (:incident_id, :lat, :lng, :source)');
        $insert->execute(array(
            ':incident_id' => $incident_id,
            ':lat' => $lat,
            ':lng' => $lng,
            ':source' => ($source ? $source : 'manual'),
        ));
        $update = $pdo->prepare('UPDATE incident_tokens SET consumed_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(array(':id' => $token_row['id']));
        $update_incident = $pdo->prepare("UPDATE incidents SET status = 'location_received', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $update_incident->execute(array(':id' => $incident_id));
        $success = true;
    }

    if ($token_row != null) {
        $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id LIMIT 1');
        $stmt->execute(array(':id' => $token_row['incident_id']));
        $incident = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
    }
}
catch (\Exception $ex) {
    $error_message = $ex->getMessage();
}

$view_template_vars['token'] = $token;
$view_template_vars['incident'] = $incident;
$view_template_vars['submitted'] = $submitted;
$view_template_vars['success'] = $success;
$view_template_vars['error_message'] = $error_message;
$view_template_vars['token_row'] = $token_row;

$template = $twig->resolveTemplate(
    array('@custom/incident-location-custom.twig.html', 'incident-location.twig.html'));

echo $template->render($view_template_vars);
