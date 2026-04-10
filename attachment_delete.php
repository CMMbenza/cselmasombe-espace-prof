<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_prof();

$id = (int)($_GET['id'] ?? 0);
if ($id) {
  $stmt = $con->prepare("SELECT file_path FROM quiz_attachment WHERE id=?");
  $stmt->bind_param('i',$id);
  $stmt->execute();
  $att = $stmt->get_result()->fetch_assoc();
  if ($att) {
    @unlink(__DIR__.'/'.$att['file_path']);
    $stmt = $con->prepare("DELETE FROM quiz_attachment WHERE id=?");
    $stmt->bind_param('i',$id);
    $stmt->execute();
  }
}
redirect('/prof/dashboard.php');
