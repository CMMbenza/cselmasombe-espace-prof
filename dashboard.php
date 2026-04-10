<?php
// /prof/dashboard.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';

require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];

// Anti-cache (utile après logout/login)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Classes de l'agent
$classes   = classes_of_agent($con, $agentId);
if (count($classes) === 1 && !get_current_classe()) {
    set_current_classe((int)$classes[0]['id']);
}
$classeId   = get_current_classe();
$classeMeta = $classeId ? current_classe_meta($con, $classeId) : null;

// Annonces (toujours)
$annonces = [];
if ($q = $con->query("
    SELECT id, titre, contenu, created_at 
    FROM annonces 
    WHERE dest_type IN ('tous','profs') 
    ORDER BY created_at DESC 
    LIMIT 5
")) {
    $annonces = $q->fetch_all(MYSQLI_ASSOC);
}

$modeAnnoncesSeulement = (count($classes) > 1 && !$classeId);

// KPIs / données quand une classe est choisie
$totalEleves = $nbM = $nbF = 0;
$nbQuizApprouve = $nbQuizAttente = $nbQuizARevoir = $nbQuizRejete = 0;
$nbSubmissionsRemis = 0;
$alerts48h = [];        // quiz approuvés qui expirent dans 48h
$recentSubs = [];       // si besoin plus tard

// Périodes actives pour le cycle de la classe
$activePeriodes = [];

if (!$modeAnnoncesSeulement && $classeId) {

    // Récupérer le cycle de la classe (si non déjà en meta)
    $cycleIdForPeriode = null;
    if (!empty($classeMeta['cycle_id'])) {
        $cycleIdForPeriode = (int)$classeMeta['cycle_id'];
    } else {
        $stmt = $con->prepare("SELECT cycle FROM classe WHERE id=?");
        $stmt->bind_param('i', $classeId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res) {
            $cycleIdForPeriode = (int)$res['cycle'];
        }
    }

    if ($cycleIdForPeriode) {
        // Charger la/les période(s) actives pour ce cycle
        $stmt = $con->prepare("
            SELECT id, CODE, libelle, ordre
            FROM periodes
            WHERE cycle_id = ? AND actif = 1
            ORDER BY ordre
        ");
        $stmt->bind_param('i', $cycleIdForPeriode);
        $stmt->execute();
        $activePeriodes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // Élèves
    $stmt = $con->prepare("
        SELECT COUNT(*) AS n,
               SUM(CASE WHEN genre='M' THEN 1 ELSE 0 END) AS m,
               SUM(CASE WHEN genre='F' THEN 1 ELSE 0 END) AS f
        FROM eleve 
        WHERE classe = ?
    ");
    $stmt->bind_param('i', $classeId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $totalEleves = (int)($r['n'] ?? 0);
    $nbM = (int)($r['m'] ?? 0);
    $nbF = (int)($r['f'] ?? 0);
    $stmt->close();

    // Statuts des quiz (classe courante) via quiz_classe
    $stmt = $con->prepare("
        SELECT q.statut, COUNT(*) AS n
        FROM quiz q
        INNER JOIN quiz_classe qc ON qc.quiz_id = q.id
        WHERE q.agent_id = ? AND qc.classe_id = ?
        GROUP BY q.statut
    ");
    $stmt->bind_param('ii', $agentId, $classeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $s = $row['statut'];
        $n = (int)$row['n'];
        if ($s === 'approuvé')        $nbQuizApprouve = $n;
        elseif ($s === 'en attente')  $nbQuizAttente = $n;
        elseif ($s === 'à revoir')    $nbQuizARevoir = $n;
        elseif ($s === 'rejeter')     $nbQuizRejete = $n;
    }
    $stmt->close();

    // Copies à corriger (remis) via quiz_classe
    $stmt = $con->prepare("
        SELECT COUNT(*) AS n
        FROM quiz_submission qs
        INNER JOIN quiz q ON q.id = qs.quiz_id
        INNER JOIN quiz_classe qc ON qc.quiz_id = q.id
        WHERE q.agent_id = ? 
          AND qc.classe_id = ? 
          AND qs.statut = 'remis'
    ");
    $stmt->bind_param('ii', $agentId, $classeId);
    $stmt->execute();
    $nbSubmissionsRemis = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();

    // Alertes 48h (quizzes approuvés avec date_limite proche)
    $today = date('Y-m-d');
    $in48h = date('Y-m-d', time() + 48 * 3600);
    $stmt = $con->prepare("
        SELECT q.id, q.titre, q.type_quiz, q.format, q.date_limite
        FROM quiz q
        INNER JOIN quiz_classe qc ON qc.quiz_id = q.id
        WHERE q.agent_id = ? 
          AND qc.classe_id = ? 
          AND q.statut = 'approuvé'
          AND q.date_limite IS NOT NULL
          AND q.date_limite BETWEEN ? AND ?
        ORDER BY q.date_limite ASC
    ");
    $stmt->bind_param('iiss', $agentId, $classeId, $today, $in48h);
    $stmt->execute();
    $alerts48h = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>
<div class="container">

  <?php if ($modeAnnoncesSeulement): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
      <div>
        Vous êtes affecté(e) à 
        <strong><?= count($classes) ?></strong> classes. 
        Sélectionnez d’abord une classe.
      </div>
      <a class="btn btn-primary btn-sm" href="/prof/switch_classe.php">Choisir une classe</a>
    </div>

  <?php elseif (!$classeId): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
      <div>Aucune classe sélectionnée.</div>
      <a class="btn btn-primary btn-sm" href="/prof/switch_classe.php">Choisir une classe</a>
    </div>

  <?php else: ?>

    <?php if ($alerts48h): ?>
      <div class="alert alert-warning">
        <div class="fw-semibold mb-1">Échéances dans les prochaines 48h :</div>
        <ul class="mb-0">
          <?php foreach ($alerts48h as $al): ?>
            <li>
              <a href="/prof/quiz_view.php?id=<?= (int)$al['id'] ?>">
                <?= e($al['titre']) ?>
              </a>
              — <?= e($al['type_quiz']) ?>/<?= e($al['format']) ?> 
              — <strong><?= e($al['date_limite']) ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div>
          <h5 class="mb-0">
            Classe : <span class="text-primary"><?= e($classeMeta['description'] ?? '') ?></span>
          </h5>
          <?php if (!empty($classeMeta['cycle_desc'])): ?>
            <div class="small text-muted">
              Cycle : <?= e($classeMeta['cycle_desc']) ?>
            </div>
          <?php endif; ?>

          <!-- Période active définie par la direction -->
          <div class="small mt-1">
            <?php if ($activePeriodes): ?>
              <span class="text-muted">Période active :</span>
              <?php
                $parts = [];
                foreach ($activePeriodes as $p) {
                    $parts[] = e($p['CODE'].' — '.$p['libelle']);
                }
                echo '<strong>'.implode(', ', $parts).'</strong>';
              ?>
            <?php else: ?>
              <span class="text-muted">
                Aucune période active définie par la direction pour ce cycle.
              </span>
            <?php endif; ?>
          </div>
        </div>

        <a class="btn btn-outline-primary btn-sm ms-auto" href="/prof/switch_classe.php">
          Changer de classe
        </a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between">
              <h6 class="mb-0">Élèves</h6>
              <span class="badge text-bg-primary"><?= (int)$totalEleves ?></span>
            </div>
            <div class="small text-muted mt-1">
              Garçons: <?= (int)$nbM ?> • Filles: <?= (int)$nbF ?>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <h6 class="mb-2">Mes quiz (classe)</h6>
            <div class="d-flex flex-wrap gap-2">
              <span class="badge rounded-pill text-bg-success">
                Approuvés: <?= (int)$nbQuizApprouve ?>
              </span>
              <span class="badge rounded-pill text-bg-warning text-dark">
                En attente: <?= (int)$nbQuizAttente ?>
              </span>
              <span class="badge rounded-pill text-bg-info text-dark">
                À revoir: <?= (int)$nbQuizARevoir ?>
              </span>
              <span class="badge rounded-pill text-bg-danger">
                Rejetés: <?= (int)$nbQuizRejete ?>
              </span>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
              <a class="btn btn-sm btn-outline-secondary" href="/prof/quiz_create.php">Créer un quiz</a>
              <a class="btn btn-sm btn-outline-secondary" href="/prof/quiz_list.php">Mes quiz</a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="mb-0">Copies à corriger</h6>
              <span class="badge text-bg-danger"><?= (int)$nbSubmissionsRemis ?></span>
            </div>
            <div class="small text-muted">
              Soumissions au statut <strong>remis</strong>.
            </div>
            <a class="btn btn-sm btn-outline-primary mt-3" href="/prof/quiz_list_quiz_soumis.php">
              Ouvrir
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Accès rapides -->
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="mb-3">Accès rapides</h6>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary btn-sm" href="/prof/eleves.php">Voir les élèves</a>
          <a class="btn btn-outline-secondary btn-sm" href="/prof/quiz_create.php">Créer un quiz</a>
          <a class="btn btn-outline-secondary btn-sm" href="/prof/quiz_list.php">Mes quiz</a>
          <a class="btn btn-outline-secondary btn-sm" href="/prof/quiz_submissions.php">Soumissions</a>
          <a class="btn btn-outline-secondary btn-sm" href="/prof/annonces.php">Toutes les annonces</a>
          <a class="btn btn-outline-secondary btn-sm" href="/prof/doc_peda/vusialisation_de_mes_cours.php">
            Périodes & pondérations
          </a>
        </div>
      </div>
    </div>

  <?php endif; ?>

  <!-- Annonces -->
  <div class="card">
    <div class="card-body">
      <h5 class="mb-3">Dernières annonces</h5>
      <?php if (!$annonces): ?>
        <div class="text-muted">Aucune annonce.</div>
      <?php else: foreach ($annonces as $a): ?>
        <div class="mb-3">
          <div class="fw-semibold"><?= e($a['titre']) ?></div>
          <div class="small text-muted"><?= e($a['created_at']) ?></div>
          <div><?= nl2br(e($a['contenu'])) ?></div>
        </div>
        <hr>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__.'/layout/footer.php'; ?>
