<?php
// /prof/quiz_autosave.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

header('Content-Type: application/json');

$prof = current_prof();
$agentId = (int)$prof['id'];

try {

    $quizId = (int)($_POST['quiz_id'] ?? 0);

    $coursId    = (int)($_POST['cours_id'] ?? 0);
    $periodeId  = (int)($_POST['periode_id'] ?? 0);
    $typeQuiz   = trim($_POST['type_quiz'] ?? '');
    $format     = trim($_POST['format'] ?? '');
    $dateLimite = $_POST['date_limite'] ?? null;
    $description= $_POST['description'] ?? '';

    if (!$coursId && !$description) {
        echo json_encode(['status' => 'empty']);
        exit;
    }

    // titre cours
    $titre = null;
    if ($coursId) {
        $stmt = $con->prepare("SELECT intitule FROM cours WHERE id=?");
        $stmt->bind_param("i", $coursId);
        $stmt->execute();
        $res = $stmt->get_result();
        $titre = $res->fetch_assoc()['intitule'] ?? null;
        $stmt->close();
    }

    // ================= INSERT =================
    if ($quizId === 0) {

        $stmt = $con->prepare("
            INSERT INTO quiz
            (agent_id,cours_id,periode_id,type_quiz,format,titre,description,date_limite,statut)
            VALUES (?,?,?,?,?,?,?,?,'brouillon')
        ");

        $stmt->bind_param(
            "iiisssss",
            $agentId,
            $coursId,
            $periodeId,
            $typeQuiz,
            $format,
            $titre,
            $description,
            $dateLimite
        );

        $stmt->execute();
        $quizId = $stmt->insert_id;
        $stmt->close();

        echo json_encode(['status'=>'inserted','quiz_id'=>$quizId]);
        exit;
    }

    // ================= UPDATE =================
    $stmt = $con->prepare("
        UPDATE quiz SET
        cours_id=?, periode_id=?, type_quiz=?, format=?,
        titre=?, description=?, date_limite=?
        WHERE id=? AND agent_id=?
    ");

    $stmt->bind_param(
        "iisssssii",
        $coursId,
        $periodeId,
        $typeQuiz,
        $format,
        $titre,
        $description,
        $dateLimite,
        $quizId,
        $agentId
    );

    $stmt->execute();
    $stmt->close();

    echo json_encode(['status'=>'updated','quiz_id'=>$quizId]);

$classeIds = $_POST['classe_ids'] ?? [];
$classeIds = array_map('intval', $classeIds);

// 🔥 reset relations classes (TRÈS IMPORTANT)
$con->query("DELETE FROM quiz_classe WHERE quiz_id = $quizId");

// 🔥 réinsertion propre
if (!empty($classeIds)) {

    $stmtQC = $con->prepare("
        INSERT INTO quiz_classe (quiz_id, classe_id)
        VALUES (?, ?)
    ");

    foreach ($classeIds as $cid) {
        $stmtQC->bind_param("ii", $quizId, $cid);
        $stmtQC->execute();
    }

    $stmtQC->close();
}

} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}