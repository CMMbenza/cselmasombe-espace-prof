<?php
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/auth.php';
require_prof();

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data['id'] ?? 0);

$stmt = $con->prepare("SELECT file_path FROM quiz_attachment WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if ($res) {

    $file = dirname(__DIR__) . $res['file_path'];

    if (file_exists($file)) {
        unlink($file);
    }

    $del = $con->prepare("DELETE FROM quiz_attachment WHERE id=?");
    $del->bind_param("i", $id);
    $del->execute();
}

echo json_encode(['success'=>true]);