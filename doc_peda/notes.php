<?php
// /prof/eleves/notes.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_prof();

$prof     = current_prof();
$agentId  = (int)$prof['id'];
$classeId = get_current_classe();

$eleveId = (int)($_GET['eleve_id'] ?? 0);
if ($eleveId <= 0) redirect('/prof/eleves.php');

// Filtre quiz
$quizId = (int)($_GET['quiz_id'] ?? 0);

// Vérifier que l'élève est bien dans la classe courante
$stmt = $con->prepare("SELECT id, nom, postnom, prenom, classe FROM eleve WHERE id = ?");
$stmt->bind_param('i', $eleveId);
$stmt->execute();
$eleve = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$eleve || (int)$eleve['classe'] !== (int)$classeId) {
    redirect('/prof/eleves.php');
}

// Récupérer la liste des quiz de ce prof pour cette classe (pour le filtre)
$stmt = $con->prepare("
  SELECT id, titre, type_quiz, format, date_limite
  FROM quiz
  WHERE agent_id = ? AND classe_id = ?
  ORDER BY created_at DESC
");
$stmt->bind_param('ii', $agentId, $classeId);
$stmt->execute();
$quizList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Trouver le quiz sélectionné (pour affichage distinct)
$selectedQuiz = null;
if ($quizId > 0 && $quizList) {
    foreach ($quizList as $q) {
        if ((int)$q['id'] === $quizId) {
            $selectedQuiz = $q;
            break;
        }
    }
}

// Récup soumissions de cet élève sur MES quiz de cette classe (+ pondération totale)
$sqlSubs = "
  SELECT 
    qs.id,
    qs.date_submitted,
    qs.statut,
    qs.note_totale,
    q.id AS quiz_id,
    q.titre,
    q.type_quiz,
    q.format,
    q.date_limite,
    (
      SELECT SUM(qq.points) 
      FROM quiz_question qq 
      WHERE qq.quiz_id = q.id
    ) AS total_points
  FROM quiz_submission qs
  INNER JOIN quiz q ON q.id = qs.quiz_id
  WHERE qs.eleve_id = ?
    AND q.agent_id = ?
    AND q.classe_id = ?
";

if ($quizId > 0) {
    $sqlSubs .= " AND q.id = ?";
}
$sqlSubs .= " ORDER BY qs.date_submitted DESC";

if ($quizId > 0) {
    $stmt = $con->prepare($sqlSubs);
    $stmt->bind_param('iiii', $eleveId, $agentId, $classeId, $quizId);
} else {
    $stmt = $con->prepare($sqlSubs);
    $stmt->bind_param('iii', $eleveId, $agentId, $classeId);
}
$stmt->execute();
$subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Moyenne (éventuellement filtrée par quiz)
$sqlAvg = "
  SELECT AVG(qs.note_totale) AS avg_note
  FROM quiz_submission qs
  INNER JOIN quiz q ON q.id = qs.quiz_id
  WHERE qs.eleve_id = ?
    AND q.agent_id = ?
    AND q.classe_id = ?
    AND qs.note_totale IS NOT NULL
";
if ($quizId > 0) {
    $sqlAvg .= " AND q.id = ?";
}

if ($quizId > 0) {
    $stmt = $con->prepare($sqlAvg);
    $stmt->bind_param('iiii', $eleveId, $agentId, $classeId, $quizId);
} else {
    $stmt = $con->prepare($sqlAvg);
    $stmt->bind_param('iii', $eleveId, $agentId, $classeId);
}
$stmt->execute();
$rowAvg  = $stmt->get_result()->fetch_assoc();
$stmt->close();

$avgNote = isset($rowAvg['avg_note']) ? (float)$rowAvg['avg_note'] : 0.0;

// 👉 Calcul de la PONDÉRATION globale (somme des "sur val" = total_points)
$totalMax = 0.0;
if ($subs) {
    foreach ($subs as $s) {
        if (!is_null($s['total_points'])) {
            $totalMax += (float)$s['total_points'];
        }
    }
}

// Résumé par type de quiz (sur tous les quiz de cet élève avec ce prof dans cette classe)
$stmt = $con->prepare("
  SELECT 
    q.type_quiz,
    COUNT(*) AS nb_evals,
    AVG(qs.note_totale) AS avg_note_type,
    MAX(qs.note_totale) AS max_note_type
  FROM quiz_submission qs
  INNER JOIN quiz q ON q.id = qs.quiz_id
  WHERE qs.eleve_id = ?
    AND q.agent_id = ?
    AND q.classe_id = ?
    AND qs.note_totale IS NOT NULL
  GROUP BY q.type_quiz
");
$stmt->bind_param('iii', $eleveId, $agentId, $classeId);
$stmt->execute();
$statsType = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Petite fonction pour formater type
function label_type_quiz(string $t): string {
    switch ($t) {
        case 'Exercice':      return 'Exercice';
        case 'Devoir':        return 'Devoir';
        case 'Interrogation': return 'Interrogation';
        case 'Examen':        return 'Examen';
        default:              return $t;
    }
}

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';

$nomComplet = e(trim(($eleve['nom'] ?? '').' '.($eleve['postnom'] ?? '').' '.($eleve['prenom'] ?? '')));
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">Notes — <?= $nomComplet ?></h1>
    <a class="btn btn-sm btn-primary" href="/prof/eleves/bulletin_pdf.php?eleve_id=<?= (int)$eleveId ?>" target="_blank">
      Bulletin PDF
    </a>
  </div>

  <!-- Filtres (Quiz) - zone bien distincte -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
          <h2 class="h6 mb-1">Filtre des quiz</h2>
          <p class="text-muted small mb-2">
            Sélectionnez un quiz pour voir uniquement la note de ce travail,
            ou laissez « Tous les quiz » pour la vue globale.
          </p>

          <?php if ($selectedQuiz): ?>
            <div class="border rounded p-2 bg-light small">
              <div><strong>Quiz sélectionné :</strong> <?= e($selectedQuiz['titre']) ?></div>
              <div>Type : <?= e($selectedQuiz['type_quiz']) ?> / Format : <?= e($selectedQuiz['format']) ?></div>
              <?php if (!empty($selectedQuiz['date_limite'])): ?>
                <div>Échéance : <?= e($selectedQuiz['date_limite']) ?></div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="small text-muted">
              Aucun quiz particulier sélectionné (vue globale).
            </div>
          <?php endif; ?>
        </div>

        <form method="get" class="row g-2 align-items-end flex-grow-1" style="max-width:420px;">
          <input type="hidden" name="eleve_id" value="<?= (int)$eleveId ?>">

          <div class="col-12">
            <label class="form-label">Choisir un quiz</label>
            <select name="quiz_id" class="form-select form-select-sm">
              <option value="0">Tous les quiz</option>
              <?php foreach ($quizList as $q): ?>
                <option value="<?= (int)$q['id'] ?>" <?= $quizId === (int)$q['id'] ? 'selected' : '' ?>>
                  <?= e($q['titre']) ?> (<?= e($q['type_quiz']) ?>/<?= e($q['format']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 d-flex gap-2 justify-content-end">
            <button class="btn btn-sm btn-outline-secondary mt-auto">Appliquer</button>
            <a href="/prof/eleves/notes.php?eleve_id=<?= (int)$eleveId ?>" class="btn btn-sm btn-outline-secondary mt-auto">
              Réinitialiser
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Moyenne globale (avec filtre éventuel) + pondération (sur val) -->
  <div class="card mb-3">
    <div class="card-body">
      <div>
        Moyenne générale (sur tes quiz<?= $quizId > 0 ? ' — quiz sélectionné' : '' ?>) :
        <span class="fw-semibold">
          <?php if ($avgNote && $totalMax > 0): ?>
            <?= e((string)round($avgNote, 2)) ?> sur <?= e((string)round($totalMax, 2)) ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Résumé par type de quiz -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <h2 class="h6 mb-3">Résumé par type de quiz (tous les quiz avec toi dans cette classe)</h2>

      <?php if (!$statsType): ?>
        <p class="mb-0 text-muted small"><em>Aucune note disponible pour l’instant.</em></p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Type</th>
                <th class="text-center">Nombre d’évaluations</th>
                <th class="text-center">Moyenne</th>
                <th class="text-center">Meilleure note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($statsType as $st): ?>
                <tr>
                  <td><?= e(label_type_quiz((string)$st['type_quiz'])) ?></td>
                  <td class="text-center"><?= (int)$st['nb_evals'] ?></td>
                  <td class="text-center">
                    <?= $st['avg_note_type'] !== null ? e((string)round((float)$st['avg_note_type'], 2)) : '—' ?>
                  </td>
                  <td class="text-center">
                    <?= $st['max_note_type'] !== null ? e((string)$st['max_note_type']) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Détail des soumissions -->
  <div class="card">
    <div class="card-body table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Date</th>
            <th>Quiz</th>
            <th>Type/Format</th>
            <th>Statut</th>
            <th>Note</th>
            <th>Échéance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$subs): ?>
            <tr><td colspan="6"><em>Aucune soumission.</em></td></tr>
          <?php else: foreach ($subs as $s): ?>
            <?php
              $note = $s['note_totale'];
              $max  = $s['total_points'];

              if (is_null($note)) {
                  $noteAffiche = '—';
              } elseif (!is_null($max) && (float)$max > 0) {
                  // Exemple : 5 sur 10
                  $noteAffiche = e((string)$note).' sur '.e((string)$max);
              } else {
                  $noteAffiche = e((string)$note);
              }

              $badgeClass = ($s['statut'] === 'corrige') ? 'success' : 'warning';
            ?>
            <tr>
              <td class="small text-muted"><?= e($s['date_submitted']) ?></td>
              <td><?= e($s['titre']) ?></td>
              <td><?= e($s['type_quiz']) ?> / <?= e($s['format']) ?></td>
              <td>
                <span class="badge text-bg-<?= $badgeClass ?>">
                  <?= e($s['statut']) ?>
                </span>
              </td>
              <td><?= $noteAffiche ?></td>
              <td class="small text-muted"><?= e($s['date_limite'] ?? '') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__.'/../layout/footer.php'; ?>
