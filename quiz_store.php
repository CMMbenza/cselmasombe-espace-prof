<?php
// /prof/quiz_store.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quiz_list.php');
    exit;
}

$prof    = current_prof();
$agentId = (int)$prof['id'];
$quizId  = (int)($_POST['quiz_id'] ?? 0);

if ($quizId <= 0) {
    redirect('quiz_list.php');
}

/* ===========================
   Vérifier propriétaire
=========================== */
$stmt = $con->prepare("SELECT id, statut FROM quiz WHERE id=? AND agent_id=?");
$stmt->bind_param('ii', $quizId, $agentId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) {
    redirect('quiz_list.php');
}

/* ===========================
   CAS 1 : DELETE (si pas statut envoyé)
=========================== */
if (!isset($_POST['statut'])) {

    $con->begin_transaction();

    try {

        // Supprimer fichiers physiques
        $stmt = $con->prepare("SELECT file_path FROM quiz_attachment WHERE quiz_id=?");
        $stmt->bind_param('i', $quizId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($f = $res->fetch_assoc()) {
            $path = dirname(__DIR__) . $f['file_path'];
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $stmt->close();

        // Suppression DB (ordre important)
        $con->query("DELETE FROM quiz_question_keyword WHERE question_id IN (SELECT id FROM quiz_question WHERE quiz_id=$quizId)");
        $con->query("DELETE FROM quiz_choice WHERE question_id IN (SELECT id FROM quiz_question WHERE quiz_id=$quizId)");
        $con->query("DELETE FROM quiz_question WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz_attachment WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz_classe WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz WHERE id=$quizId");

        $con->commit();

        header('Location: quiz_list.php?deleted=1');
        exit;

    } catch (Throwable $e) {
        $con->rollback();
        die("Erreur suppression : ".$e->getMessage());
    }
}

/* ===========================
   CAS 2 : UPDATE STATUT
   (SEULEMENT brouillon → en attente)
=========================== */

$currentStatus = $quiz['statut'];
$newStatus     = $_POST['statut'] ?? '';

if ($currentStatus === 'brouillon' && $newStatus === 'en attente') {

    $stmt = $con->prepare("UPDATE quiz SET statut='en attente' WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $quizId, $agentId);
    $stmt->execute();
    $stmt->close();

    header('Location: quiz_view.php?id='.$quizId.'&updated=1');
    exit;
}

if (!empty($_POST['statut']) && $_POST['statut'] === 'en attente') {

    $quizId = (int)$_POST['quiz_id'];

    $stmt = $con->prepare("UPDATE quiz SET statut='en attente' WHERE id=? AND agent_id=?");
    $stmt->bind_param('ii', $quizId, $agentId);
    $stmt->execute();

    echo json_encode(['success' => true]);
    exit;
}

/* ===========================
   AUTRES CAS : REFUS
=========================== */
header('Location: quiz_view.php?id='.$quizId);
exit;