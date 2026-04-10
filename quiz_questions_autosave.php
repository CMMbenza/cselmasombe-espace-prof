<?php
// /prof/quiz_questions_autosave.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_prof();

header('Content-Type: application/json');

$quizId = (int)($_POST['quiz_id'] ?? 0);
$questions = $_POST['q'] ?? [];

if (!$quizId) {
    echo json_encode(['status'=>'no_quiz']);
    exit;
}

$con->begin_transaction();

try {

    // 🔥 delete old (simple sync strategy)
    $con->query("DELETE FROM quiz_question WHERE quiz_id=$quizId");

    $stmtQ = $con->prepare("
        INSERT INTO quiz_question
        (quiz_id, `TYPE`, question_text, points, sort_order, expected_answer, similarity_min)
        VALUES (?,?,?,?,?,?,?)
    ");

    $stmtC = $con->prepare("
        INSERT INTO quiz_choice
        (question_id, choice_text, is_correct, sort_order)
        VALUES (?,?,?,?)
    ");

    $stmtK = $con->prepare("
        INSERT INTO quiz_question_keyword
        (question_id, keyword, poids)
        VALUES (?,?,?)
    ");

    $order = 1;

    foreach ($questions as $q) {

        $text = trim($q['text'] ?? '');
        if ($text === '') continue;

        $type = $q['type'] === 'RQ' ? 'RQ' : 'QCM';
        $points = (float)($q['points'] ?? 1);

        $expected = $q['expected'] ?? null;
        $sim = (float)($q['similarity_min'] ?? 100);

        $stmtQ->bind_param(
            "issdsss",
            $quizId,
            $type,
            $text,
            $points,
            $order,
            $expected,
            $sim
        );

        $stmtQ->execute();
        $qid = $stmtQ->insert_id;
        $order++;

        // keywords RQ
        if ($type === 'RQ' && !empty($q['keywords'])) {
            foreach (explode(',', $q['keywords']) as $kw) {
                $kw = trim($kw);
                if ($kw === '') continue;

                $p = 1;
                $stmtK->bind_param("isd", $qid, $kw, $p);
                $stmtK->execute();
            }
        }

        // QCM choices
        if ($type === 'QCM') {

            $choices = $q['choice'] ?? [];
            $correct = $q['correct'] ?? [];

            $i = 0;
            foreach ($choices as $c) {
                $c = trim($c);
                if ($c === '') continue;

                $isCorrect = in_array((string)$i, $correct, true) ? 1 : 0;

                $stmtC->bind_param("isii", $qid, $c, $isCorrect, $i);
                $stmtC->execute();

                $i++;
            }
        }
    }

    $con->commit();

    echo json_encode(['status'=>'ok']);

} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}