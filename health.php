<?php
/**
 * health.php - lightweight "is this app server alive?" check for a load
 * balancer (nginx, HAProxy, AWS ELB, ...). Returns 200 + {"status":"ok"}
 * when PHP and the database both respond, otherwise 503. Deliberately does
 * NOT start a session, require a login, or reveal any detail - so it is
 * cheap to call every few seconds and safe to leave public.
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
    );
    $pdo->query('SELECT 1');
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable']);
}
