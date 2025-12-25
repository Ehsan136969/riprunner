<?php
// ==============================================================
//  FireKhoy Admin Settings Controller
// ==============================================================
namespace riprunner;

define('INCLUSION_PERMITTED', true);

if(defined('__RIPRUNNER_ROOT__') === false) {
    define('__RIPRUNNER_ROOT__', dirname(dirname(__FILE__)));
}

require_once __RIPRUNNER_ROOT__ . '/template.php';
require_once __RIPRUNNER_ROOT__ . '/authentication/authentication.php';
require_once __RIPRUNNER_ROOT__ . '/models/global-model.php';
require_once __RIPRUNNER_ROOT__ . '/models/live-callout-warning-model.php';
require_once __RIPRUNNER_ROOT__ . '/logging.php';

\riprunner\Authentication::setJWTCookie();
\riprunner\Authentication::sec_session_start();
new LiveCalloutWarningViewModel($global_vm, $view_template_vars);
if ($global_vm->auth->isAdmin != true) {
    header('Location: '.$global_vm->RR_DOC_ROOT.'/controllers/login-controller.php');
    exit;
}

$template = $twig->resolveTemplate(
    array('@custom/admin-settings-custom.twig.html', 'admin-settings.twig.html'));

echo $template->render($view_template_vars);
