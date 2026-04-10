<?php
// /prof/quiz_submissions.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'] ?? 0;
if ($agentId <= 0) redirect('/prof/login.php');

$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
if ($quizId <= 0) redirect('/prof/quiz_list.php');

// No cache (utile sur ce type de page)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$quiz = null;
$isRQ = false;
$rows = [];
$error = '';

// 1) Vérifier que le prof est autorisé sur ce quiz (affectation à la classe du quiz)
$sqlQuiz = "
  SELECT q.id, q.titre, q.type_quiz, q.format, q.classe_id,
         c.description AS classe_desc, cy.description AS cycle_desc
  FROM quiz q
  JOIN classe c ON c.id = q.classe_id
  LEFT JOIN cycle cy ON cy.id = c.cycle
  WHERE q.id = ?
    AND q.classe_id IN (SELECT classe_id FROM affectation_prof_classe WHERE agent_id = ?)
  LIMIT 1
";
if ($st = $con->prepare($sqlQuiz)) {
  $st->bind_param('ii', $quizId, $agentId);
  $st->execute();
  $res = $st->get_result();
  $quiz = $res ? $res->fetch_assoc() : null;
  $st->close();
}
if (!$quiz) {
  $error = "Quiz introuvable ou non autorisé.";
}

if (!$error) {
  $isRQ = ($quiz['format'] === 'RQ');

  // 2) Charger les soumissions
  $sqlSub = "
    SELECT s.id AS submission_id,
           s.note_totale, s.statut, s.date_submitted,
           e.id AS eleve_id,
           CONCAT(e.nom,' ',e.postnom,' ',e.prenom) AS eleve_nom
    FROM quiz_submission s
    JOIN eleve e ON e.id = s.eleve_id
    WHERE s.quiz_id = ?
    ORDER BY s.date_submitted DESC, s.id DESC
    LIMIT 1000
  ";
  if ($st = $con->prepare($sqlSub)) {
    $st->bind_param('i', $quizId);
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
  }

  // 3) Si note_totale est NULL, calculer via quiz_answer.points_obtenus
  if ($rows) {
    $ids = array_map(fn($r) => (int)$r['submission_id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sqlSum = "
      SELECT submission_id, COALESCE(SUM(points_obtenus),0) AS pts
      FROM quiz_answer
      WHERE submission_id IN ($placeholders)
      GROUP BY submission_id
    ";
    if ($st = $con->prepare($sqlSum)) {
      $st->bind_param($types, ...$ids);
      $st->execute();
      $res = $st->get_result();
      $sums = [];
      while ($t = $res->fetch_assoc()) {
        $sums[(int)$t['submission_id']] = (float)$t['pts'];
      }
      $st->close();

      // Merge
      foreach ($rows as &$r) {
        if ($r['note_totale'] === null) {
          $sid = (int)$r['submission_id'];
          $r['note_calc'] = isset($sums[$sid]) ? (float)$sums[$sid] : 0.0;
        } else {
          $r['note_calc'] = (float)$r['note_totale'];
        }
      }
      unset($r);
    }
  }
}

include __DIR__ . '/layout/header.php';
include __DIR__ . '/layout/navbar.php';
?>
<div class="container">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Soumissions — <?= e($quiz['titre'] ?? '') ?></h1>
    <?php if ($quiz): ?>
      <div class="text-muted small">
        Classe : <strong><?= e($quiz['classe_desc']) ?></strong>
        <?= !empty($quiz['cycle_desc']) ? ' — Cycle : <strong>'.e($quiz['cycle_desc']).'</strong>' : '' ?>
        &nbsp; | &nbsp; Format : <span class="badge text-bg-secondary"><?= e($quiz['format']) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php else: ?>
    <div class="card card-soft">
      <div class="card-body table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Élève</th>
              <th>Date de remise</th>
              <th>Statut</th>
              <th class="text-end">Note</th>
              <th style="width:1%;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6"><em>Aucune soumission.</em></td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['submission_id'] ?></td>
                <td><?= e($r['eleve_nom']) ?></td>
                <td><?= e($r['date_submitted']) ?></td>
                <td>
                  <?php $badge = ($r['statut']==='corrige') ? 'success' : 'warning'; ?>
                  <span class="badge text-bg-<?= $badge ?>"><?= e($r['statut']) ?></span>
                </td>
                <td class="text-end"><?= number_format((float)$r['note_calc'], 2, ',', ' ') ?></td>
                <td class="text-nowrap">
                  <a class="btn btn-sm btn-outline-primary" href="/prof/submission_view.php?id=<?= (int)$r['submission_id'] ?>">
                    Voir
                  </a>
                  <?php if ($isRQ): ?>
                    <a class="btn btn-sm btn-outline-success" href="/prof/grade_submission.php?id=<?= (int)$r['submission_id'] ?>">
                      Corriger
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-3">
      <a class="btn btn-outline-secondary" href="/prof/quiz_list.php">&larr; Retour aux quiz</a>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/layout/footer.php'; ?>
