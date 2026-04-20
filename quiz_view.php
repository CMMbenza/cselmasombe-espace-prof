<?php
// prof/quiz_view.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];
$quizId  = (int)($_GET['id'] ?? 0);

// No cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if ($quizId <= 0) {
  redirect('/prof/quiz_list.php');
}

// Charger entête quiz + classes/cycles
$sql = "SELECT 
          q.id, q.agent_id, q.type_quiz, q.format, q.titre, q.description, q.statut, q.date_limite, q.created_at,
          c.id AS classe_id, c.description AS classe_desc, cy.id AS cycle_id, cy.description AS cycle_desc
        FROM quiz q
        JOIN quiz_classe qc ON qc.quiz_id = q.id
        JOIN classe c ON c.id = qc.classe_id
        JOIN cycle cy ON cy.id = c.cycle
        WHERE q.id = ?
        LIMIT 1";
$stmt = $con->prepare($sql);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();

if (!$quiz || (int)$quiz['agent_id'] !== $agentId) {
  redirect('/prof/quiz_list.php');
}

// Charger questions
$qQuestions = $con->prepare("
    SELECT id, TYPE, question_text, points, sort_order, expected_answer, similarity_min
    FROM quiz_question WHERE quiz_id=? ORDER BY sort_order, id
");
$qQuestions->bind_param('i', $quizId);
$qQuestions->execute();
$questions = $qQuestions->get_result()->fetch_all(MYSQLI_ASSOC);

// Charger mots-clés
$keywordsByQ = [];
if ($questions) {
    $ids = array_column($questions, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmtK = $con->prepare("SELECT question_id, keyword, poids FROM quiz_question_keyword WHERE question_id IN ($in)");
    $stmtK->bind_param($types, ...$ids);
    $stmtK->execute();
    $resK = $stmtK->get_result();
    while ($k = $resK->fetch_assoc()) {
        $keywordsByQ[(int)$k['question_id']][] = $k;
    }
}

// Charger choix (pour QCM)
$choicesByQ = [];
if ($questions) {
    $ids = array_column($questions, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $stmtC = $con->prepare("SELECT id, question_id, choice_text, is_correct, sort_order
                            FROM quiz_choice WHERE question_id IN ($in) ORDER BY question_id, sort_order, id");
    $stmtC->bind_param($types, ...$ids);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) {
        $choicesByQ[(int)$c['question_id']][] = $c;
    }
}

// Charger pièces jointes
$stmtA = $con->prepare("SELECT id, file_path, original_name, mime_type, file_size, uploaded_at
                        FROM quiz_attachment WHERE quiz_id=? ORDER BY id");
$stmtA->bind_param('i', $quizId);
$stmtA->execute();
$attachments = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC);

// Compter soumissions
$stmtS = $con->prepare("SELECT COUNT(*) AS n FROM quiz_submission WHERE quiz_id=?");
$stmtS->bind_param('i', $quizId);
$stmtS->execute();
$nbSub = (int)($stmtS->get_result()->fetch_assoc()['n'] ?? 0);

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>
<div class="container">
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-start gap-3">
            <div>
                <h2 class="h5 mb-1"><?= e($quiz['titre']) ?></h2>
                <div class="small text-muted">
                    Classe: <strong><?= e($quiz['classe_desc']) ?></strong> — Cycle:
                    <strong><?= e($quiz['cycle_desc']) ?></strong><br>
                    Type: <?= e($quiz['type_quiz']) ?> • Format: <?= e($quiz['format']) ?> • Créé:
                    <?= e($quiz['created_at']) ?>
                    <?php if (!empty($quiz['date_limite'])): ?> • Date limite:
                    <?= e($quiz['date_limite']) ?><?php endif; ?>
                </div>
            </div>
            <?php
            $badge = match($quiz['statut']) {
                'approuvé' => 'success',
                'en attente' => 'warning',
                'rejeter' => 'danger',
                'à revoir' => 'info',
                default => 'secondary'
            };
            ?>
            <span class="badge text-bg-<?= $badge ?> ms-auto"><?= e($quiz['statut']) ?></span>
        </div>
        <?php if (!empty($quiz['description'])): ?>
        <div class="card-body border-top">
            <p class="mb-0"><?= nl2br(e($quiz['description'])) ?></p>
        </div>
        <?php endif; ?>
        <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center">

            <!-- Modifier -->
            <a href="/prof/quiz_update.php?id=<?= (int)$quiz['id'] ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil"></i> Modifier
            </a>
            <?php if ($quiz['statut'] === 'brouillon' || $quiz['statut'] === 'Brouillon'): ?>

            <form action="quiz_store.php" method="post">
                <input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>">
                <input type="hidden" name="statut" value="en attente">

                <button class="btn btn-sm btn-warning">
                    <i class="bi bi-send"></i> Mettre en attente
                </button>
            </form>
            <?php endif; ?>

            <!-- Supprimer -->
            <form action="quiz_store.php" method="post"
                onsubmit="return confirm('⚠️ Supprimer ce quiz définitivement ?');">
                <input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>">
                <button class="btn btn-sm btn-danger">
                    <i class="bi bi-trash"></i> Supprimer
                </button>
            </form>

            <!-- Retour -->
            <a href="quiz_list.php" class="btn btn-sm btn-outline-secondary ms-auto">
                ← Retour
            </a>

        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">Questions</h5>
                    <?php if (!$questions): ?>
                    <div class="text-muted">Aucune question.</div>
                    <?php else: ?>
                    <ol class="ps-3">
                        <?php foreach ($questions as $i=>$q): ?>
                        <li class="mb-3">
                            <div class="fw-semibold"><?= nl2br(e($q['question_text'])) ?></div>
                            <div class="small text-muted mb-2">
                                Type: <?= e($q['TYPE']) ?> • Points: <?= e((string)$q['points']) ?>
                            </div>

                            <?php if ($q['TYPE'] === 'QCM'): ?>
                            <?php $chs = $choicesByQ[(int)$q['id']] ?? []; ?>
                            <?php if ($chs): ?>
                            <ul class="list-unstyled ms-1">
                                <?php foreach ($chs as $ch): ?>
                                <li class="mb-1">
                                    <span
                                        class="badge text-bg-<?= (int)$ch['is_correct'] ? 'success' : 'secondary' ?> me-1">
                                        <?= (int)$ch['is_correct'] ? '✓' : '•' ?>
                                    </span>
                                    <?= e($ch['choice_text']) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?>
                            <div class="text-muted small">Aucun choix défini.</div>
                            <?php endif; ?>
                            <?php else: ?>
                            <?php if (!empty($q['expected_answer'])): ?>
                            <div class="mt-2">
                                <span class="badge text-bg-primary">Réponse attendue</span>
                                <div class="mt-1"><?= nl2br(e($q['expected_answer'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($q['similarity_min'])): ?>
                            <div class="small text-muted mt-1">
                                Seuil de similarité : <strong><?= e($q['similarity_min']) ?>%</strong>
                            </div>
                            <?php endif; ?>
                            <?php $kws = $keywordsByQ[(int)$q['id']] ?? []; ?>
                            <?php if ($kws): ?>
                            <div class="small text-muted mt-1">
                                Mots-clés :
                                <?= implode(', ', array_map(fn($k)=>e($k['keyword']), $kws)) ?>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="mb-2">Pièces jointes</h6>
                    <?php if (!$attachments): ?>
                    <div class="text-muted">Aucune pièce jointe.</div>
                    <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($attachments as $a): ?>
                        <li class="mb-2">
                            <a href="<?= e($a['file_path']) ?>" target="_blank"><?= e($a['original_name']) ?></a>
                            <div class="small text-muted"><?= e($a['mime_type']) ?> • <?= (int)$a['file_size'] ?> o
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">Soumissions</h6>
                    <div class="display-6"><?= (int)$nbSub ?></div>
                    <div class="small text-muted">Nombre total de remises par les élèves.</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__.'/layout/footer.php'; ?>