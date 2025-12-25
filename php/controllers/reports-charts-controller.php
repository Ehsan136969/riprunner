<?php 
// ==============================================================
//	Copyright (C) 2014 Mark Vejvoda
//	Under GNU GPL v3.0
// ==============================================================
namespace riprunner;
 
define( 'INCLUSION_PERMITTED', true );

if(defined('__RIPRUNNER_ROOT__') === false) {
    define('__RIPRUNNER_ROOT__', dirname(dirname(__FILE__)));
}

require_once __RIPRUNNER_ROOT__ . '/template.php';
require_once __RIPRUNNER_ROOT__ . '/authentication/authentication.php';
require_once __RIPRUNNER_ROOT__ . '/models/global-model.php';
require_once __RIPRUNNER_ROOT__ . '/models/live-callout-warning-model.php';
require_once __RIPRUNNER_ROOT__ . '/models/reports-charts-model.php';

// Register our view and variables for the template
\riprunner\Authentication::setJWTCookie();
\riprunner\Authentication::sec_session_start();
new LiveCalloutWarningViewModel($global_vm, $view_template_vars);
if ($global_vm->auth->isAdmin != true) {
	header('Location: '.$global_vm->RR_DOC_ROOT.'/controllers/callout-history-controller.php?'.$global_vm->RR_JWT_TOKEN_PARAM);
	exit;
}
new ReportsChartsViewModel($global_vm, $view_template_vars);
// Load out template
$template = $twig->resolveTemplate(
		array('@custom/reports-charts-custom.twig.html',
			  'reports-charts.twig.html'));

// Output our template
echo $template->render($view_template_vars);
