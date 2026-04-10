<?php
// prof/quiz_list.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];

// No cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ----------------------
// Filtres
// ----------------------
$status = $_GET['statut'] ?? '';
$allowedStatus = ['','brouillon','en attente','approuvé','rejeter','à revoir'];
if (!in_array($status, $allowedStatus, true)) {
    $status = '';
}

$classeFilter  = isset($_GET['classe_id'])   ? (int)$_GET['classe_id']   : 0;
$periodeFilter = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

// ----------------------
// Classes
// ----------------------
$myClasses = classes_of_agent($con, $agentId);

// ----------------------
// Périodes
// ----------------------
$periodes = [];
$stmtP = $con->prepare("
    SELECT DISTINCT periode_id
    FROM quiz
    WHERE agent_id = ?
      AND periode_id IS NOT NULL
    ORDER BY periode_id
");
if ($stmtP) {
    $stmtP->bind_param('i', $agentId);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($resP) {
        $periodes = $resP->fetch_all(MYSQLI_ASSOC);
    }
    $stmtP->close();
}

// ----------------------
// Requête
// ----------------------
$wheres = ["q.agent_id = ?"];
$params = [$agentId];
$types  = 'i';

if ($status !== '') {
    $wheres[] = "q.statut = ?";
    $params[] = $status;
    $types   .= 's';
}
if ($classeFilter > 0) {
    $wheres[] = "qc.classe_id = ?";
    $params[] = $classeFilter;
    $types   .= 'i';
}
if ($periodeFilter > 0) {
    $wheres[] = "q.periode_id = ?";
    $params[] = $periodeFilter;
    $types   .= 'i';
}
if (!empty($dateFrom)) {
    $wheres[] = "DATE(q.created_at) >= ?";
    $params[] = $dateFrom;
    $types   .= 's';
}
if (!empty($dateTo)) {
    $wheres[] = "DATE(q.created_at) <= ?";
    $params[] = $dateTo;
    $types   .= 's';
}

$sql = "
    SELECT 
    q.id,
    q.type_quiz,
    q.format,
    q.titre,
    q.description,
    q.statut,
    q.date_limite,
    q.created_at,
    q.periode_id,

    GROUP_CONCAT(
    DISTINCT CONCAT(c.description, ' (', cy.description, ')')
    SEPARATOR ', '
    ) AS classes_cycles,

    (SELECT COUNT(*) FROM quiz_question qq WHERE qq.quiz_id = q.id) AS nb_questions,
    (SELECT COUNT(*) FROM quiz_attachment qa WHERE qa.quiz_id = q.id) AS nb_pj,
    (SELECT COUNT(*) FROM quiz_submission qs WHERE qs.quiz_id = q.id) AS nb_submissions

FROM quiz q
LEFT JOIN quiz_classe qc ON qc.quiz_id = q.id
LEFT JOIN classe c ON c.id = qc.classe_id
LEFT JOIN cycle cy ON cy.id = c.cycle
WHERE ".implode(' AND ', $wheres)."
GROUP BY q.id
ORDER BY q.created_at DESC
";

$stmt = $con->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res  = $stmt->get_result();
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>

<style>
.quiz-card {
    transition: all 0.2s ease;
    border-radius: 12px;
}

.quiz-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}
</style>

<div class="container py-3">

    <!-- HEADER -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body d-flex align-items-center">
            <h2 class="h5 mb-0 fw-bold">
                <i class="bi bi-ui-checks"></i> Mes quiz
            </h2>
            <a class="btn btn-primary btn-sm ms-auto px-3" href="/prof/quiz_create.php">
                <i class="bi bi-plus-circle"></i> Créer un quiz
            </a>
        </div>
    </div>

    <!-- FILTRES -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form class="row g-3">

                <div class="col-md-2">
                    <label class="form-label small">Statut</label>
                    <select name="statut" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <option value="brouillon">Brouillon</option>
                        <option value="en attente">En attente</option>
                        <option value="approuvé">Approuvé</option>
                        <option value="rejeter">Rejeté</option>
                        <option value="à revoir">À revoir</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small">Classe</label>
                    <select name="classe_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Toutes</option>
                        <?php foreach ($myClasses as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">
                            <?= e($c['description']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small">Période</label>
                    <select name="periode_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">Toutes</option>
                        <?php foreach ($periodes as $p): ?>
                        <option value="<?= (int)$p['periode_id'] ?>">
                            Période <?= (int)$p['periode_id'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small">Du</label>
                    <input type="date" name="date_from" value="<?= e($dateFrom) ?>"
                        class="form-control form-control-sm">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-dark btn-sm w-100 me-2">
                        <i class="bi bi-funnel"></i> Filtrer
                    </button>
                    <a href="quiz_list.php" class="btn btn-danger btn-sm w-100">
                        <i class="bi bi-funnel"></i> Reinitialiser
                    </a>
                </div>

            </form>
        </div>
    </div>

    <?php if (!$rows): ?>
    <div class="alert alert-info shadow-sm">Aucun quiz trouvé.</div>
    <?php else: ?>

    <div class="row g-4">
        <?php foreach ($rows as $q): ?>

        <?php
        $badgeClass = match($q['statut']) {
            'brouillon'   => 'secondary',
            'en attente'  => 'warning',
            'approuvé'    => 'success',
            'rejeter'     => 'danger',
            'à revoir'    => 'info',
            default       => 'dark'
        };

        $badgeIcon = match($q['statut']) {
            'brouillon'   => 'bi-pencil',
            'en attente'  => 'bi-hourglass-split',
            'approuvé'    => 'bi-check-circle',
            'rejeter'     => 'bi-x-circle',
            'à revoir'    => 'bi-arrow-repeat',
            default       => 'bi-question-circle'
        };
        ?>

        <div class="col-lg-3 col-md-6 col-sm-12">
            <div class="card h-100 border-0 shadow-sm quiz-card">

                <div class="card-body d-flex flex-column">

                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="h6 fw-semibold mb-0 text-truncate" style="max-width: 70%;">
                            <?= e($q['titre']) ?>
                        </h5>

                        <span class="badge bg-<?= $badgeClass ?> px-2 py-1 d-inline-flex align-items-center gap-1">
                            <i class="bi <?= $badgeIcon ?>"></i>
                            <small><?= ucfirst(e($q['statut'])) ?></small>
                        </span>
                    </div>

                    <div class="small text-muted mb-3">
                        <div>
                            <strong>Classes :</strong>
                            <?= e($q['classes_cycles'] ?? '— Aucune classe') ?>
                        </div>
                        <div>Type/Format : <?= e($q['type_quiz']) ?> — <?= e($q['format']) ?></div>
                        <div class="text-danger"> Crée le : 📅 <?= e($q['created_at']) ?></div>
                    </div>

                    <div class="d-flex justify-content-between text-center small mb-3">
                        <div>
                            <div class="fw-bold"><?= (int)$q['nb_questions'] ?></div>
                            <div class="text-muted">Questions</div>
                        </div>
                        <div>
                            <div class="fw-bold"><?= (int)$q['nb_pj'] ?></div>
                            <div class="text-muted">Fichiers (PJ)</div>
                        </div>
                        <div>
                            <div class="fw-bold"><?= (int)$q['nb_submissions'] ?></div>
                            <div class="text-muted">Réponses</div>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <a class="btn btn-outline-primary btn-sm w-100"
                            href="/prof/quiz_view.php?id=<?= (int)$q['id'] ?>">
                            <i class="bi bi-eye"></i> Voir
                        </a>
                    </div>

                </div>

            </div>
        </div>

        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</div>

<?php include __DIR__.'/layout/footer.php'; ?>