<?php
// Seed script for FireKhoy stations, firefighters, and shift rules.
namespace riprunner;

define('INCLUSION_PERMITTED', true);

require_once __DIR__ . '/config_constants.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db/db_connection.php';
require_once __DIR__ . '/logging.php';

function ensure_tables($pdo, $driver) {
    $table_sql = array();
    if ($driver === 'pgsql') {
        $table_sql[] = "CREATE TABLE IF NOT EXISTS stations (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."name VARCHAR(255) NOT NULL,\n"
            ."lat DOUBLE PRECISION NOT NULL,\n"
            ."lng DOUBLE PRECISION NOT NULL,\n"
            ."is_active BOOLEAN NOT NULL DEFAULT TRUE\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS firefighters (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."station_id INTEGER NOT NULL,\n"
            ."name VARCHAR(255) NOT NULL,\n"
            ."phone VARCHAR(32) NOT NULL,\n"
            ."role_in_station VARCHAR(255) NULL,\n"
            ."shift_group VARCHAR(5) NULL,\n"
            ."is_active BOOLEAN NOT NULL DEFAULT TRUE\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incidents (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_code VARCHAR(50) NOT NULL,\n"
            ."caller_phone VARCHAR(32) NULL,\n"
            ."description TEXT NULL,\n"
            ."status VARCHAR(50) NOT NULL DEFAULT 'new',\n"
            ."suggested_station_id INTEGER NULL,\n"
            ."dispatched_station_id INTEGER NULL,\n"
            ."created_by INTEGER NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            ."updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_tokens (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_id INTEGER NOT NULL,\n"
            ."token VARCHAR(255) NOT NULL,\n"
            ."expires_at TIMESTAMP NULL,\n"
            ."consumed_at TIMESTAMP NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_dispatch_tokens (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_id INTEGER NOT NULL,\n"
            ."token VARCHAR(255) NOT NULL,\n"
            ."expires_at TIMESTAMP NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_locations (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_id INTEGER NOT NULL,\n"
            ."lat DOUBLE PRECISION NOT NULL,\n"
            ."lng DOUBLE PRECISION NOT NULL,\n"
            ."source VARCHAR(20) NOT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS dispatches (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_id INTEGER NOT NULL,\n"
            ."station_id INTEGER NOT NULL,\n"
            ."dispatched_by INTEGER NULL,\n"
            ."dispatched_at TIMESTAMP NULL,\n"
            ."status VARCHAR(50) NOT NULL DEFAULT 'pending'\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS notifications (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."incident_id INTEGER NULL,\n"
            ."channel VARCHAR(20) NOT NULL,\n"
            ."recipient VARCHAR(64) NOT NULL,\n"
            ."message TEXT NOT NULL,\n"
            ."status VARCHAR(20) NOT NULL,\n"
            ."provider_response TEXT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .")";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS shift_rules (\n"
            ."id SERIAL PRIMARY KEY,\n"
            ."station_id INTEGER NOT NULL,\n"
            ."json_rule JSONB NOT NULL,\n"
            ."rotation_start DATE NOT NULL,\n"
            ."change_time TIME NOT NULL,\n"
            ."timezone VARCHAR(64) NOT NULL\n"
            .")";
    }
    else {
        $table_sql[] = "CREATE TABLE IF NOT EXISTS stations (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."name VARCHAR(255) NOT NULL,\n"
            ."lat DOUBLE NOT NULL,\n"
            ."lng DOUBLE NOT NULL,\n"
            ."is_active TINYINT(1) NOT NULL DEFAULT 1\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS firefighters (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."station_id INT(11) NOT NULL,\n"
            ."name VARCHAR(255) NOT NULL,\n"
            ."phone VARCHAR(32) NOT NULL,\n"
            ."role_in_station VARCHAR(255) NULL,\n"
            ."shift_group VARCHAR(5) NULL,\n"
            ."is_active TINYINT(1) NOT NULL DEFAULT 1\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incidents (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_code VARCHAR(50) NOT NULL,\n"
            ."caller_phone VARCHAR(32) NULL,\n"
            ."description TEXT NULL,\n"
            ."status VARCHAR(50) NOT NULL DEFAULT 'new',\n"
            ."suggested_station_id INT(11) NULL,\n"
            ."dispatched_station_id INT(11) NULL,\n"
            ."created_by INT(11) NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            ."updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_tokens (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_id INT(11) NOT NULL,\n"
            ."token VARCHAR(255) NOT NULL,\n"
            ."expires_at TIMESTAMP NULL DEFAULT NULL,\n"
            ."consumed_at TIMESTAMP NULL DEFAULT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_dispatch_tokens (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_id INT(11) NOT NULL,\n"
            ."token VARCHAR(255) NOT NULL,\n"
            ."expires_at TIMESTAMP NULL DEFAULT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS incident_locations (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_id INT(11) NOT NULL,\n"
            ."lat DOUBLE NOT NULL,\n"
            ."lng DOUBLE NOT NULL,\n"
            ."source VARCHAR(20) NOT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS dispatches (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_id INT(11) NOT NULL,\n"
            ."station_id INT(11) NOT NULL,\n"
            ."dispatched_by INT(11) NULL,\n"
            ."dispatched_at TIMESTAMP NULL DEFAULT NULL,\n"
            ."status VARCHAR(50) NOT NULL DEFAULT 'pending'\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS notifications (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."incident_id INT(11) NULL,\n"
            ."channel VARCHAR(20) NOT NULL,\n"
            ."recipient VARCHAR(64) NOT NULL,\n"
            ."message TEXT NOT NULL,\n"
            ."status VARCHAR(20) NOT NULL,\n"
            ."provider_response TEXT NULL,\n"
            ."created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $table_sql[] = "CREATE TABLE IF NOT EXISTS shift_rules (\n"
            ."id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n"
            ."station_id INT(11) NOT NULL,\n"
            ."json_rule TEXT NOT NULL,\n"
            ."rotation_start DATE NOT NULL,\n"
            ."change_time TIME NOT NULL,\n"
            ."timezone VARCHAR(64) NOT NULL\n"
            .") ENGINE=InnoDB DEFAULT CHARSET=utf8";
    }

    foreach ($table_sql as $sql) {
        $pdo->exec($sql);
    }
}

function seed_stations($pdo) {
    $stations = array(
        array('ایستگاه ۱ – شهید قانع', 38.561452, 44.978115),
        array('ایستگاه ۲ – رسالت', 38.541856, 44.940089),
        array('ایستگاه ۳ – شهرک ولیعصر', 38.484478, 44.969013),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 38.555480, 44.919830),
        array('ایستگاه ۵ – شهدای اقتدار', 38.559435, 44.958569),
    );
    $stmt = $pdo->prepare('SELECT id FROM stations WHERE name = :name LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO stations (name, lat, lng, is_active) VALUES (:name, :lat, :lng, :active)');
    foreach ($stations as $station) {
        $stmt->execute(array(':name' => $station[0]));
        $existing = $stmt->fetch(
            \PDO::FETCH_ASSOC
        );
        if ($existing) {
            continue;
        }
        $insert->execute(array(
            ':name' => $station[0],
            ':lat' => $station[1],
            ':lng' => $station[2],
            ':active' => 1,
        ));
    }
}

function fetch_station_ids($pdo) {
    $rows = $pdo->query('SELECT id, name FROM stations')->fetchAll(\PDO::FETCH_ASSOC);
    $map = array();
    foreach ($rows as $row) {
        $map[$row['name']] = $row['id'];
    }
    return $map;
}

function seed_firefighters($pdo, $station_ids) {
    $people = array(
        array('ایستگاه ۱ – شهید قانع', 'مهدی برودکی', 'فرمانده شیفت', '+989145190262', 'A'),
        array('ایستگاه ۱ – شهید قانع', 'فرزاد عظیم زاده', 'راننده', '+989149451101', 'A'),
        array('ایستگاه ۱ – شهید قانع', 'ناصر حسینی', 'راننده', '+989149654716', 'A'),
        array('ایستگاه ۱ – شهید قانع', 'علی جعفرلو', 'آتش‌نشان', '+989906829756', 'A'),
        array('ایستگاه ۱ – شهید قانع', 'باقر ایمانی اقدم', 'آتش‌نشان', '+989144626039', 'A'),
        array('ایستگاه ۱ – شهید قانع', 'سعید صبوری', 'فرمانده شیفت', '+989148701780', 'B'),
        array('ایستگاه ۱ – شهید قانع', 'امیر نعمتی', 'راننده', '+989144614868', 'B'),
        array('ایستگاه ۱ – شهید قانع', 'منصور شهبازی', 'راننده', '+989149602595', 'B'),
        array('ایستگاه ۱ – شهید قانع', 'حسین ابراهیمی', 'آتش‌نشان', '+989185894498', 'B'),
        array('ایستگاه ۱ – شهید قانع', 'جعفر معصومی', 'آتش‌نشان', '+989011328310', 'B'),
        array('ایستگاه ۱ – شهید قانع', 'محبوب غم پرور', 'فرمانده شیفت', '+989149602745', 'C'),
        array('ایستگاه ۱ – شهید قانع', 'معصوم خلیلی', 'راننده', '+989149765913', 'C'),
        array('ایستگاه ۱ – شهید قانع', 'حسن احمدی نسب', 'آتش‌نشان', '+989026798362', 'C'),
        array('ایستگاه ۱ – شهید قانع', 'علی چاوشقلی', 'آتش‌نشان', '+989149770288', 'C'),
        array('ایستگاه ۱ – شهید قانع', 'اصغر حاجی حسینلو', 'آتش‌نشان', '+989149618103', 'C'),
        array('ایستگاه ۲ – رسالت', 'عبدالله نقی لو', 'راننده', '+989149604522', 'A'),
        array('ایستگاه ۲ – رسالت', 'وحید قنبرلو', 'آتش‌نشان', '+989145485724', 'A'),
        array('ایستگاه ۲ – رسالت', 'هادی عبدالعلی پور', 'فرمانده شیفت', '+989148780472', 'A'),
        array('ایستگاه ۲ – رسالت', 'مالک حسن پور', 'فرمانده شیفت', '+989142017276', 'B'),
        array('ایستگاه ۲ – رسالت', 'جواد محمدلو', 'آتش‌نشان', '+989333075233', 'B'),
        array('ایستگاه ۲ – رسالت', 'مهدی پرسه', 'آتش‌نشان', '+989393390617', 'B'),
        array('ایستگاه ۲ – رسالت', 'سعید تیماچی', 'فرمانده شیفت', '+989356025450', 'C'),
        array('ایستگاه ۲ – رسالت', 'عارف مزرعه لی', 'آتش‌نشان', '+989146236421', 'C'),
        array('ایستگاه ۲ – رسالت', 'سید هاشم طباطبایی', 'راننده', '+989144439800', 'C'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'رامین معماری', 'فرمانده شیفت', '+989017498640', 'A'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'حسین اسماعیل لو', 'راننده', '+989033565935', 'A'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'حسین حاجی قاسملو', 'آتش‌نشان', '+989149617270', 'A'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'مجتبی حسنعلیلو', 'فرمانده شیفت', '+989148626576', 'B'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'مهدی شرفی', 'آتش‌نشان', '+989141639349', 'B'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'اسماعیل فرج زاده', 'آتش‌نشان', '+989146391730', 'B'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'بهنام مقدم', 'فرمانده شیفت', '+989386239911', 'C'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'علیرضا ایمان قلی لو', 'راننده', '+989141608599', 'C'),
        array('ایستگاه ۳ – شهرک ولیعصر', 'ناصر عبداللهی', 'آتش‌نشان', '+989149602861', 'C'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'مجید لک', 'فرمانده شیفت', '+989144615921', 'A'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'محمد شفایی', 'آتش‌نشان', '+989144636930', 'A'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'سید هادی اکبری', 'آتش‌نشان', '+989367067707', 'A'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'مرتضی پور شریف', 'فرمانده شیفت', '+989144629105', 'B'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'اکبر حاجی حسینلو', 'آتش‌نشان', '+989369944412', 'B'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'میر هادی سید سالاری', 'آتش‌نشان', '+989141640756', 'B'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'امیر سلیمانی', 'فرمانده شیفت', '+989141607517', 'C'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'مسعود باقر زاده', 'راننده', '+989145190236', 'C'),
        array('ایستگاه ۴ – حاجی قلی‌زاده', 'مرتضی کریم خواه', 'آتش‌نشان', '+989140491930', 'C'),
        array('ایستگاه ۵ – شهدای اقتدار', 'بهروز پنیرچی', 'فرمانده شیفت', '+989143617033', 'A'),
        array('ایستگاه ۵ – شهدای اقتدار', 'روح الله شاه حسین زاده', 'آتش‌نشان', '+989143613447', 'A'),
        array('ایستگاه ۵ – شهدای اقتدار', 'یوسف دولت شناس', 'فرمانده شیفت', '+989141600786', 'B'),
        array('ایستگاه ۵ – شهدای اقتدار', 'محمد علی محمدی', 'آتش‌نشان', '+989141600361', 'B'),
    );

    $stmt = $pdo->prepare('SELECT id FROM firefighters WHERE station_id = :station_id AND name = :name LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO firefighters (station_id, name, phone, role_in_station, shift_group, is_active) VALUES (:station_id, :name, :phone, :role, :shift_group, :active)');

    foreach ($people as $person) {
        $station_name = $person[0];
        if (!isset($station_ids[$station_name])) {
            continue;
        }
        $station_id = $station_ids[$station_name];
        $stmt->execute(array(':station_id' => $station_id, ':name' => $person[1]));
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            continue;
        }
        $insert->execute(array(
            ':station_id' => $station_id,
            ':name' => $person[1],
            ':phone' => $person[3],
            ':role' => $person[2],
            ':shift_group' => $person[4],
            ':active' => 1,
        ));
    }
}

function seed_shift_rules($pdo, $station_ids) {
    $rotation_start = '2025-12-12';
    $change_time = '08:00:00';
    $timezone = 'Asia/Tehran';

    $rules = array(
        array('ایستگاه ۱ – شهید قانع', array('sequence' => array('A','B','C'), 'startShift' => 'B', 'rotationDays' => 1)),
        array('ایستگاه ۲ – رسالت', array('sequence' => array('A','B','C'), 'startShift' => 'B', 'rotationDays' => 1)),
        array('ایستگاه ۳ – شهرک ولیعصر', array('sequence' => array('A','B','C'), 'startShift' => 'B', 'rotationDays' => 1)),
        array('ایستگاه ۴ – حاجی قلی‌زاده', array('sequence' => array('A','B','C'), 'startShift' => 'B', 'rotationDays' => 1)),
        array('ایستگاه ۵ – شهدای اقتدار', array('sequence' => array('A','B'), 'startShift' => 'A', 'rotationDays' => 1)),
    );

    $stmt = $pdo->prepare('SELECT id FROM shift_rules WHERE station_id = :station_id LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO shift_rules (station_id, json_rule, rotation_start, change_time, timezone) VALUES (:station_id, :json_rule, :rotation_start, :change_time, :timezone)');

    foreach ($rules as $rule) {
        $station_name = $rule[0];
        if (!isset($station_ids[$station_name])) {
            continue;
        }
        $station_id = $station_ids[$station_name];
        $stmt->execute(array(':station_id' => $station_id));
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            continue;
        }
        $insert->execute(array(
            ':station_id' => $station_id,
            ':json_rule' => json_encode($rule[1], JSON_UNESCAPED_UNICODE),
            ':rotation_start' => $rotation_start,
            ':change_time' => $change_time,
            ':timezone' => $timezone,
        ));
    }
}

try {
    $firehall = getFirstActiveFireHallConfig($FIREHALLS);
    if ($firehall === null) {
        throw new \Exception('No active firehall config found.');
    }
    $db = new DbConnection($firehall);
    $pdo = $db->getConnection();
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

    ensure_tables($pdo, $driver);
    seed_stations($pdo);
    $station_ids = fetch_station_ids($pdo);
    seed_firefighters($pdo, $station_ids);
    seed_shift_rules($pdo, $station_ids);

    echo "Seed completed successfully.\n";
}
catch (\Exception $ex) {
    echo 'Seed failed: ' . $ex->getMessage() . "\n";
    exit(1);
}
