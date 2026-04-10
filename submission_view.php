<?php
// /prof/submission_view.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)($prof['id'] ?? 0);
if ($agentId <= 0) {
    redirect('/prof/login.php');
}

$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($submissionId <= 0) {
    redirect('/prof/quiz_list.php');
}

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$error      = '';
$submission = null;
$questions  = [];      // Pour QCM/RQ
$choicesByQ = [];      // [question_id] => [choices...]
$answers    = [];      // [question_id] => ['choice_id','reponse_text','points_obtenus']
$files      = [];      // Pièces jointes

// 1) Charger la soumission + quiz + élève + classe, vérifier autorisation du prof
$sql = "
  SELECT
    s.id           AS submission_id,
    s.quiz_id,
    s.eleve_id,
    s.statut,
    s.note_totale,
    s.date_submitted,

    q.titre,
    q.format,
    q.type_quiz,

    c.id           AS classe_id,
    c.description  AS classe_desc,

    e.nom,
    e.postnom,
    e.prenom

  FROM quiz_submission s

  JOIN quiz q            ON q.id = s.quiz_id
  JOIN quiz_classe qc    ON qc.quiz_id = q.id
  JOIN classe c          ON c.id = qc.classe_id
  JOIN eleve e           ON e.id = s.eleve_id

  WHERE s.id = ?
    AND qc.classe_id IN (
        SELECT classe_id
        FROM affectation_prof_classe
        WHERE agent_id = ?
    )

  LIMIT 1
";


if ($st = $con->prepare($sql)) {
    $st->bind_param('ii', $submissionId, $agentId);
    $st->execute();
    $res = $st->get_result();
    $submission = $res ? $res->fetch_assoc() : null;
    $st->close();
}

if (!$submission) {
    $error = "Soumission introuvable ou non autorisée.";
} else {
    $format = (string)($submission['format'] ?? '');

    // 2) Pièces jointes (quel que soit le format)
    $sqlF = "
      SELECT id, original_name, mime_type, file_path, file_size, uploaded_at
      FROM quiz_submission_attachment
      WHERE submission_id = ?
      ORDER BY id
    ";
    if ($st = $con->prepare($sqlF)) {
        $st->bind_param('i', $submissionId);
        $st->execute();
        $files = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }

    // 3) Pour QCM / RQ : charger questions + réponses
    if (in_array($format, ['QCM','RQ'], true)) {
        // Questions
        $sqlQ = "
          SELECT id, TYPE, question_text, points, sort_order
          FROM quiz_question
          WHERE quiz_id = ?
          ORDER BY sort_order, id
        ";
        if ($st = $con->prepare($sqlQ)) {
            $st->bind_param('i', $submission['quiz_id']);
            $st->execute();
            $res = $st->get_result();
            $questions = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $st->close();
        }

        // Choix pour QCM
        if ($questions && $format === 'QCM') {
            $qids = array_map(fn($r) => (int)$r['id'], $questions);
            if ($qids) {
                $place = implode(',', array_fill(0, count($qids), '?'));
                $types = str_repeat('i', count($qids));
                $sqlC = "
                  SELECT id, question_id, choice_text, is_correct, sort_order
                  FROM quiz_choice
                  WHERE question_id IN ($place)
                  ORDER BY sort_order, id
                ";
                if ($st = $con->prepare($sqlC)) {
                    $st->bind_param($types, ...$qids);
                    $st->execute();
                    $res = $st->get_result();
                    while ($c = $res->fetch_assoc()) {
                        $qid = (int)$c['question_id'];
                        if (!isset($choicesByQ[$qid])) {
                            $choicesByQ[$qid] = [];
                        }
                        $choicesByQ[$qid][] = $c;
                    }
                    $st->close();
                }
            }
        }

        // Réponses de l'élève
        $sqlA = "
          SELECT question_id, choice_id, reponse_text, points_obtenus
          FROM quiz_answer
          WHERE submission_id = ?
        ";
        if ($st = $con->prepare($sqlA)) {
            $st->bind_param('i', $submissionId);
            $st->execute();
            $res = $st->get_result();
            while ($a = $res->fetch_assoc()) {
                $qid = (int)$a['question_id'];
                $answers[$qid] = [
                    'choice_id'      => $a['choice_id'] !== null ? (int)$a['choice_id'] : null,
                    'reponse_text'   => $a['reponse_text'] ?? '',
                    'points_obtenus' => $a['points_obtenus'] !== null ? (float)$a['points_obtenus'] : null,
                ];
            }
            $st->close();
        }
    }
}

include __DIR__ . '/layout/header.php';
include __DIR__ . '/layout/navbar.php';
?>
<div class="container">

  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Détail de la copie d'élève</h1>

    <?php if ($submission): ?>
      <div class="d-flex flex-wrap gap-2">
        <a class="d-none btn btn-sm btn-outline-secondary" href="/prof/quiz_soumi_eleve.php">
          &larr; Retour à la liste des copies
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="/prof/quiz_list_quiz_soumis.php">
          &larr; Retour à la liste des copies
        </a>

        <?php
          // Bouton Corriger pour RQ ET PJ quand la copie n'est pas encore corrigée
          if (
              in_array(($submission['format'] ?? ''), ['RQ','PJ'], true)
              && ($submission['statut'] ?? '') === 'remis'
          ): ?>
          <a class="btn btn-sm btn-primary"
             href="/prof/grade_submission.php?id=<?= (int)$submission['submission_id'] ?>">
            Corriger
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php elseif ($submission): ?>

    <?php
      $nomComplet = trim(
        ($submission['nom'] ?? '') . ' ' .
        ($submission['postnom'] ?? '') . ' ' .
        ($submission['prenom'] ?? '')
      );
    ?>

    <!-- Résumé -->
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="small text-muted">Élève</div>
            <div class="fw-semibold"><?= e($nomComplet) ?></div>
          </div>
          <div class="col-md-4">
            <div class="small text-muted">Classe</div>
            <div class="fw-semibold"><?= e($submission['classe_desc'] ?? '—') ?></div>
          </div>
          <div class="col-md-4">
            <div class="small text-muted">Devoir (quiz)</div>
            <div class="fw-semibold"><?= e($submission['titre'] ?? '') ?></div>
          </div>
          <div class="col-md-3 mt-2">
            <div class="small text-muted">Type / Format</div>
            <div class="fw-semibold">
              <?= e($submission['type_quiz'] ?? '—') ?> / <?= e($submission['format'] ?? '—') ?>
            </div>
          </div>
          <div class="col-md-3 mt-2">
            <div class="small text-muted">Date de remise</div>
            <div class="fw-semibold">
              <?= e($submission['date_submitted'] ?? '—') ?>
            </div>
          </div>
          <div class="col-md-3 mt-2">
            <div class="small text-muted">Statut</div>
            <div class="fw-semibold">
              <?= e($submission['statut'] ?? '—') ?>
            </div>
          </div>
          <div class="col-md-3 mt-2">
            <div class="small text-muted">Note totale</div>
            <div class="fw-semibold">
              <?= $submission['note_totale'] !== null
                    ? e(number_format((float)$submission['note_totale'], 2, ',', ' '))
                    : '—' ?>
            </div>
          </div>
        </div>

        <?php if ($files): ?>
          <hr>
          <div class="small text-muted mb-1">Pièces jointes remises :</div>
          <ul class="mb-0">
            <?php foreach ($files as $f): ?>
              <li>
                <a href="<?= e($f['file_path']) ?>" target="_blank" rel="noopener">
                  <?= e($f['original_name']) ?>
                </a>
                <span class="text-muted small">
                  (<?= e($f['mime_type']) ?>, <?= (int)$f['file_size'] ?> o)
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <!-- Détail selon le format -->
    <div class="card card-soft mb-4">
      <div class="card-body">
        <?php if (in_array(($submission['format'] ?? ''), ['QCM','RQ'], true)): ?>

          <?php if (!$questions): ?>
            <div class="alert alert-warning mb-0">
              Aucune question trouvée pour ce quiz.
            </div>
          <?php else: ?>
            <ol class="mb-0">
              <?php foreach ($questions as $q):
                $qid = (int)$q['id'];
                $typeQ = (string)$q['TYPE'];
                $ptsMax = (float)$q['points'];
                $rep    = $answers[$qid]['reponse_text']   ?? '';
                $ptsObt = $answers[$qid]['points_obtenus'] ?? null;
                $choiceId = $answers[$qid]['choice_id']    ?? null;
              ?>
                <li class="mb-3">
                  <div class="mb-1">
                    <strong>(<?= e($typeQ) ?>)</strong>
                    <?= nl2br(e($q['question_text'])) ?>
                    <span class="text-muted small"> — Max : <?= e((string)$ptsMax) ?> pts</span>
                  </div>

                  <?php if ($typeQ === 'QCM'): ?>
                    <?php $opts = $choicesByQ[$qid] ?? []; ?>
                    <?php if (!$opts): ?>
                      <div class="text-muted small mb-2">Aucun choix enregistré.</div>
                    <?php else: ?>
                      <div class="mb-2">
                        <?php foreach ($opts as $c):
                          $isCorrect = ((int)$c['is_correct'] === 1);
                          $isChosen  = ($choiceId !== null && $choiceId === (int)$c['id']);
                        ?>
                          <div class="small">
                            <?php if ($isChosen): ?>
                              <span class="badge text-bg-primary me-1">Choix élève</span>
                            <?php endif; ?>
                            <?php if ($isCorrect): ?>
                              <span class="badge text-bg-success me-1">Bonne réponse</span>
                            <?php endif; ?>
                            <?= nl2br(e($c['choice_text'])) ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                  <?php else: // RQ ?>
                    <div class="mb-2">
                      <div class="small text-muted">Réponse de l’élève :</div>
                      <div class="border rounded p-2 bg-light" style="white-space:pre-wrap;">
                        <?= $rep !== '' ? nl2br(e($rep)) : '<em>Aucune réponse saisie.</em>' ?>
                      </div>
                    </div>
                  <?php endif; ?>

                  <div class="small text-muted">
                    Points obtenus :
                    <strong>
                      <?= $ptsObt !== null ? e(number_format((float)$ptsObt, 2, ',', ' ')) : '—' ?>
                    </strong>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

        <?php elseif (($submission['format'] ?? '') === 'PJ'): ?>
          <p class="mb-0">
            Ce devoir est au format <strong>Pièce jointe (PJ)</strong>.
            Consultez les fichiers remis ci-dessus.  
            La note globale est visible dans le résumé.  
            Pour modifier la note, utilisez le bouton <strong>Corriger</strong.
          </p>
        <?php else: ?>
          <p class="mb-0 text-muted">
            Aucun détail supplémentaire à afficher pour ce format.
          </p>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>
