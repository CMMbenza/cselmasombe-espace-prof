<?php
// /prof/layout/navbar.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_prof();

$prof      = current_prof();
$agentId   = (int)$prof['id'];
$classeId  = get_current_classe();
$classeMeta= $classeId ? current_classe_meta($con, $classeId) : null;

// Récup classes pour switch
$classesForNav = classes_of_agent($con, $agentId);

// URL courante
$uriPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

function is_active(array $needles, string $haystack): string {
    foreach ($needles as $n) {
        if ($n !== '' && strpos($haystack, $n) !== false) return 'active';
    }
    return '';
}

// Compteur “Soumissions (remis)”
// ⚠️ Mis à jour pour quiz_classe
if ($classeId) {
    $stmt = $con->prepare("
        SELECT COUNT(*) AS n
        FROM quiz_submission qs
        INNER JOIN quiz q ON q.id = qs.quiz_id
        INNER JOIN quiz_classe qc ON qc.quiz_id = q.id
        WHERE q.agent_id=? AND qc.classe_id=? AND qs.statut='remis'
    ");
    $stmt->bind_param('ii', $agentId, $classeId);
} else {
    // pas de classe en session → toutes tes classes
    $stmt = $con->prepare("
        SELECT COUNT(*) AS n
        FROM quiz_submission qs
        INNER JOIN quiz q ON q.id = qs.quiz_id
        WHERE q.agent_id=? AND qs.statut='remis'
    ");
    $stmt->bind_param('i', $agentId);
}
$stmt->execute();
$pendingCount = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
$stmt->close();
?>

<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom mb-3">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="/prof/dashboard.php">Professeur</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navProf" aria-controls="navProf" aria-expanded="false" aria-label="Basculer la navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div id="navProf" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <li class="nav-item">
          <a class="nav-link <?= is_active(['/prof/dashboard.php'], $uriPath) ?>" href="/prof/dashboard.php">Dashboard</a>
        </li>
        
        <li class="nav-item dropdown">
          <?php
            $quizActive = is_active([
              '/prof/quiz_create.php','/prof/quiz_list.php','/prof/quiz_view.php',
              '/prof/quiz_submissions.php','/prof/submission_view.php',
              '/prof/eleves/notes.php','/prof/eleves/notes_view.php'
            ], $uriPath);
          ?>
          <a class="nav-link dropdown-toggle <?= $quizActive ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Gest. élèves
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= is_active(['/prof/eleves.php'], $uriPath) ?>" href="/prof/eleves.php">Mes élèves</a></li> 
            <li><a class="dropdown-item <?= is_active(['/prof/eleves/registre_d_appel.php'], $uriPath) ?>" href="/prof/eleves/registre_d_appel.php">Registre d'appel</a></li> 
          </ul>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= is_active(['/prof/annonces.php'], $uriPath) ?>" href="/prof/annonces.php">Annonces/Communiqués</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $quizActive ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Quiz
          </a>
          <ul class="dropdown-menu">
            <!--<li><a class="dropdown-item <?= is_active(['/prof/devoir_journalier.php'], $uriPath) ?>" href="/prof/devoir_journalier.php">Créer un devoir journalier</a></li>-->
            <li><a class="dropdown-item <?= is_active(['/prof/quiz_create.php'], $uriPath) ?>" href="/prof/quiz_create.php">Créer une évaluation</a></li>
            <li><a class="dropdown-item <?= is_active(['/prof/quiz_list.php'], $uriPath) ?>" href="/prof/quiz_list.php">Mes évaluations/Devoirs journaliers</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item <?= is_active(['/prof/quiz_list_quiz_soumis.php','/prof/submission_view.php'], $uriPath) ?>" href="/prof/quiz_list_quiz_soumis.php">
                Voir Soumissions
                <?php if ($pendingCount>0): ?>
                  <span class="badge rounded-pill bg-danger ms-1"><?= $pendingCount ?></span>
                <?php endif; ?>
              </a>
            </li>
          </ul>
        </li>
        
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $quizActive ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Fiche pédagogique
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= is_active(['/prof/doc_peda/vusialisation_de_mes_cours.php'], $uriPath) ?>" href="/prof/doc_peda/vusialisation_de_mes_cours.php">Statistique de mes cours</a></li>
            <li><a class="dropdown-item <?= is_active(['/prof/doc_peda/fiches_des_eleves.php'], $uriPath) ?>" href="/prof/doc_peda/fiches_des_eleves.php">Fiche des élèves</a></li>
            <li><a class="dropdown-item <?= is_active(['/prof/doc_peda/cahier_des_cotes.php'], $uriPath) ?>" href="/prof/doc_peda/cahier_des_cotes.php">Cahier des côtes</a></li>
          </ul>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-3">
        <?php if ($classesForNav && count($classesForNav) > 1): ?>
          <form method="post" action="/prof/switch_classe.php" class="d-flex align-items-center">
            <label class="me-2 small text-muted d-none d-md-inline">Classe</label>
            <select name="classe_id" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">— Sélectionner —</option>
              <?php foreach ($classesForNav as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($classeId===(int)$c['id'])?'selected':'' ?>>
                  <?= e($c['description']) ?><?= !empty($c['cycle_desc']) ? ' — '.e($c['cycle_desc']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php elseif ($classeMeta): ?>
          <span class="badge rounded-pill text-bg-primary">
            <?= e($classeMeta['description']) ?><?= !empty($classeMeta['cycle_desc']) ? ' — Cycle: '.e($classeMeta['cycle_desc']) : '' ?>
          </span>
        <?php endif; ?>

        <div class="text-end">
          <div class="fw-semibold small">
            <?= e(trim(($prof['nom']??'').' '.($prof['postnom']??'').' '.($prof['prenom']??''))) ?>
          </div>
          <div class="d-none text-muted small">ID: <?= (int)$prof['id'] ?></div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="/prof/logout.php">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>
