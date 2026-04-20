<?php
// /prof/quiz_store.php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode invalide']);
    exit;
}

$prof    = current_prof();
$agentId = (int)$prof['id'];
$quizId  = (int)($_POST['quiz_id'] ?? 0);

if ($quizId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Quiz invalide']);
    exit;
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
    echo json_encode(['success' => false, 'error' => 'Accès refusé']);
    exit;
}

/* ===========================
   CAS 1 : DELETE
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

        // Suppression DB
        $con->query("DELETE FROM quiz_question_keyword WHERE question_id IN (SELECT id FROM quiz_question WHERE quiz_id=$quizId)");
        $con->query("DELETE FROM quiz_choice WHERE question_id IN (SELECT id FROM quiz_question WHERE quiz_id=$quizId)");
        $con->query("DELETE FROM quiz_question WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz_attachment WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz_classe WHERE quiz_id=$quizId");
        $con->query("DELETE FROM quiz WHERE id=$quizId");

        $con->commit();

        header('Location: quiz_view.php');
        // echo json_encode(['success' => true, 'deleted' => true]);
        exit;

    } catch (Throwable $e) {
        $con->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/* ===========================
   CAS 2 : UPDATE STATUT
=========================== */

$currentStatus = $quiz['statut'];
$newStatus     = $_POST['statut'] ?? '';

if ($newStatus === 'en attente') {

    try {

        $stmt = $con->prepare("UPDATE quiz SET statut='en attente' WHERE id=? AND agent_id=?");
        $stmt->bind_param('ii', $quizId, $agentId);
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'success' => true,
            'quiz_id' => $quizId,
            'statut'  => 'en attente'
        ]);
        header("Location: quiz_view.php?id=$quizId");
        exit;

    } catch (Throwable $e) {

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

/* ===========================
   CAS PAR DEFAUT
=========================== */

echo json_encode([
    'success' => false,
    'error' => 'Action non reconnue'
]);
exit;