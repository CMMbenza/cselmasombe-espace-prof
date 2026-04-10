<?php
// /prof/grade_submission.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)($prof['id'] ?? 0);
if ($agentId <= 0) redirect('/prof/login.php');

$submissionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($submissionId <= 0) redirect('/prof/quiz_list.php');

// No cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$error      = '';
$ok         = '';
$submission = null;
$questions  = []; // RQ uniquement
$answers    = []; // [question_id] => ['reponse_text'=>..., 'points_obtenus'=>...]
$qids       = [];
$files      = []; // pièces jointes pour PJ ou autres formats

// 1) Charger la soumission + quiz + élève, et vérifier autorisation du prof
$sql = "
  SELECT
    s.id AS submission_id, s.quiz_id, s.eleve_id, s.statut, s.note_totale, s.date_submitted,
    q.titre, q.format, q.type_quiz, q.classe_id,
    e.nom, e.postnom, e.prenom
  FROM quiz_submission s
  JOIN quiz q ON q.id = s.quiz_id
  JOIN eleve e ON e.id = s.eleve_id
  WHERE s.id = ?
    AND q.classe_id IN (
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
    if ($format !== 'RQ' && $format !== 'PJ') {
        // On limite cette page à la correction des RQ et PJ
        $error = "Ce type de quiz n'est pas corrigible via cette page.";
    }
}

if (!$error) {

    $format = (string)$submission['format'];

    // 2) Récupérer les fichiers remis (utile surtout pour PJ)
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

    // 3) Si format RQ, charger les questions & réponses
    if ($format === 'RQ') {
        // Questions RQ du quiz
        $sqlQ = "
          SELECT id, question_text, points, sort_order
          FROM quiz_question
          WHERE quiz_id = ? AND TYPE = 'RQ'
          ORDER BY sort_order, id
        ";
        if ($st = $con->prepare($sqlQ)) {
            $st->bind_param('i', $submission['quiz_id']);
            $st->execute();
            $res = $st->get_result();
            $questions = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $st->close();
        }
        $qids = array_map(fn($r)=> (int)$r['id'], $questions);

        // Réponses de l'élève
        if ($questions) {
            $placeholders = implode(',', array_fill(0, count($qids), '?'));
            $types = 'i' . str_repeat('i', count($qids)); // submission_id + questions
            $sqlA = "
              SELECT question_id, reponse_text, points_obtenus
              FROM quiz_answer
              WHERE submission_id = ? AND question_id IN ($placeholders)
            ";
            if ($st = $con->prepare($sqlA)) {
                $params = array_merge([$submissionId], $qids);
                $st->bind_param($types, ...$params);
                $st->execute();
                $res = $st->get_result();
                while ($a = $res->fetch_assoc()) {
                    $answers[(int)$a['question_id']] = $a;
                }
                $st->close();
            }
        }
    }

    // 4) Enregistrement POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if ($format === 'RQ') {
            // ---------- Correction détaillée RQ (par question) ----------
            $con->begin_transaction();
            try {
                $total = 0.0;

                foreach ($questions as $q) {
                    $qid   = (int)$q['id'];
                    $max   = (float)$q['points'];
                    $key   = 'pts_'.$qid;

                    $raw   = isset($_POST[$key]) ? (string)$_POST[$key] : '0';
                    $raw   = str_replace(',', '.', $raw);
                    $pts   = (float)$raw;
                    if ($pts < 0)    $pts = 0.0;
                    if ($pts > $max) $pts = $max;

                    if (isset($answers[$qid])) {
                        // update
                        $sqlU = "UPDATE quiz_answer SET points_obtenus = ? WHERE submission_id = ? AND question_id = ?";
                        if ($st = $con->prepare($sqlU)) {
                            $st->bind_param('dii', $pts, $submissionId, $qid);
                            $st->execute();
                            $st->close();
                        }
                    } else {
                        // insert (au cas où la ligne n'existerait pas)
                        $blank = '';
                        $sqlI = "INSERT INTO quiz_answer (submission_id, question_id, reponse_text, points_obtenus)
                                 VALUES (?, ?, ?, ?)";
                        if ($st = $con->prepare($sqlI)) {
                            $st->bind_param('iisd', $submissionId, $qid, $blank, $pts);
                            $st->execute();
                            $st->close();
                        }
                    }

                    $total += $pts;
                }

                // Mettre à jour la soumission (note_totale + statut corrigé)
                $sqlS = "UPDATE quiz_submission SET note_totale = ?, statut = 'corrige' WHERE id = ?";
                if ($st = $con->prepare($sqlS)) {
                    $st->bind_param('di', $total, $submissionId);
                    $st->execute();
                    $st->close();
                }

                $con->commit();
                $ok = "Corrections enregistrées. Note totale = " . number_format($total, 2, ',', ' ');

                // Recharger les réponses après enregistrement
                $answers = [];
                if ($questions) {
                    $placeholders = implode(',', array_fill(0, count($qids), '?'));
                    $types = 'i' . str_repeat('i', count($qids));
                    $sqlA = "
                      SELECT question_id, reponse_text, points_obtenus
                      FROM quiz_answer
                      WHERE submission_id = ? AND question_id IN ($placeholders)
                    ";
                    if ($st = $con->prepare($sqlA)) {
                        $params = array_merge([$submissionId], $qids);
                        $st->bind_param($types, ...$params);
                        $st->execute();
                        $res = $st->get_result();
                        while ($a = $res->fetch_assoc()) {
                            $answers[(int)$a['question_id']] = $a;
                        }
                        $st->close();
                    }
                }
                $submission['note_totale'] = $total;
                $submission['statut']      = 'corrige';

            } catch (Throwable $e) {
                $con->rollback();
                $error = "Échec de l'enregistrement : " . $e->getMessage();
            }

        } elseif ($format === 'PJ') {
            // ---------- Correction PJ : note globale ----------
            try {
                $raw = isset($_POST['note_totale']) ? (string)$_POST['note_totale'] : '0';
                $raw = str_replace(',', '.', $raw);
                $note = (float)$raw;
                if ($note < 0) $note = 0.0;

                $sqlS = "UPDATE quiz_submission SET note_totale = ?, statut = 'corrige' WHERE id = ?";
                if ($st = $con->prepare($sqlS)) {
                    $st->bind_param('di', $note, $submissionId);
                    $st->execute();
                    $st->close();
                }

                $submission['note_totale'] = $note;
                $submission['statut']      = 'corrige';
                $ok = "Note enregistrée avec succès : " . number_format($note, 2, ',', ' ');

            } catch (Throwable $e) {
                $error = "Échec de l'enregistrement : " . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/layout/header.php';
include __DIR__ . '/layout/navbar.php';
?>
<div class="container">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Correction — <?= isset($submission['format']) ? e($submission['format']) : '' ?></h1>
    <?php if ($submission): ?>
      <div>
        <a class="btn btn-outline-secondary btn-sm"
           href="/prof/quiz_submissions.php?quiz_id=<?= (int)$submission['quiz_id'] ?>">
          &larr; Retour aux soumissions
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <?php if ($ok):    ?><div class="alert alert-success"><?= e($ok) ?></div><?php endif; ?>

  <?php if ($submission && !$error): ?>
    <?php
      $nomComplet = trim(
        ($submission['nom'] ?? '') . ' ' .
        ($submission['postnom'] ?? '') . ' ' .
        ($submission['prenom'] ?? '')
      );
    ?>
    <div class="card card-soft mb-3">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-4">
            <div class="small text-muted">Élève</div>
            <div class="fw-semibold"><?= e($nomComplet) ?></div>
          </div>
          <div class="col-md-4">
            <div class="small text-muted">Quiz</div>
            <div class="fw-semibold"><?= e($submission['titre'] ?? '') ?></div>
          </div>
          <div class="col-md-2">
            <div class="small text-muted">Statut</div>
            <div class="fw-semibold"><?= e($submission['statut'] ?? '') ?></div>
          </div>
          <div class="col-md-2">
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
          <div class="small text-muted mb-1">Pièces jointes :</div>
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

    <form method="post" class="card card-soft">
      <div class="card-body">
        <?php if ($submission['format'] === 'RQ'): ?>

          <?php if (!$questions): ?>
            <div class="alert alert-warning mb-0">Aucune question RQ trouvée pour ce quiz.</div>
          <?php else: ?>
            <ol class="mb-0">
              <?php foreach ($questions as $q):
                $qid = (int)$q['id'];
                $rep = $answers[$qid]['reponse_text']   ?? '';
                $pts = $answers[$qid]['points_obtenus'] ?? '';
              ?>
                <li class="mb-3">
                  <div class="mb-1">
                    <strong>Question :</strong> <?= nl2br(e($q['question_text'])) ?>
                    <span class="text-muted small"> — Max : <?= e((string)$q['points']) ?> pts</span>
                  </div>
                  <div class="mb-2">
                    <div class="small text-muted">Réponse élève :</div>
                    <div class="border rounded p-2 bg-light" style="white-space:pre-wrap;">
                      <?= $rep !== '' ? nl2br(e($rep)) : '<em>Aucune réponse saisie.</em>' ?>
                    </div>
                  </div>
                  <div class="mb-2">
                    <label class="form-label">Points obtenus</label>
                    <input
                      type="number"
                      step="0.01"
                      min="0"
                      max="<?= e((string)$q['points']) ?>"
                      name="pts_<?= $qid ?>"
                      class="form-control form-control-sm"
                      value="<?= e((string)$pts) ?>"
                    >
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

        <?php elseif ($submission['format'] === 'PJ'): ?>
          <div class="mb-3">
            <p class="mb-1">
              Ce devoir est au format <strong>Pièce jointe (PJ)</strong>.
              Vous pouvez attribuer une <strong>note globale</strong> à partir de l'analyse des fichiers remis.
            </p>
          </div>
          <div class="mb-2">
            <label class="form-label">Note totale (sur 10, 20 ou votre barème)</label>
            <input
              type="number"
              step="0.01"
              min="0"
              name="note_totale"
              class="form-control"
              value="<?= $submission['note_totale'] !== null ? e((string)$submission['note_totale']) : '' ?>"
            >
            <div class="form-text">
              Vous pouvez décider du barème (ex: sur 10, sur 20) selon votre planification.
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="card-footer d-flex gap-2">
        <button class="btn btn-success">Enregistrer la correction</button>
        <a class="btn btn-outline-secondary"
           href="/prof/quiz_submissions.php?quiz_id=<?= (int)$submission['quiz_id'] ?>">
          Annuler
        </a>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>
