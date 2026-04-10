<?php
// /prof/eleves/historique_appels.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_prof();

$classeId = get_current_classe();

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';

if (!$classeId): ?>
<div class="container">
  <div class="alert alert-info">
    Aucune classe sélectionnée. 
    <a href="/prof/switch_classe.php">Choisir une classe</a>
  </div>
</div>
<?php include __DIR__.'/../layout/footer.php'; exit; endif;

// =====================
// Petite fonction utilitaire pour date FR
// =====================
function format_date_fr(string $dateSql): string {
    // attend un format YYYY-MM-DD
    if (!$dateSql) return '';
    $dt = DateTime::createFromFormat('Y-m-d', substr($dateSql, 0, 10));
    if (!$dt) return $dateSql; // fallback brut si format inattendu
    return $dt->format('d/m/Y'); // ex: 21/11/2025
}

// =====================
// Filtres de dates
// =====================
$dateMin = trim($_GET['date_min'] ?? '');
$dateMax = trim($_GET['date_max'] ?? '');

// normalisation simple
if ($dateMin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMin)) $dateMin = '';
if ($dateMax !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateMax)) $dateMax = '';

$wheres = ["a.classe_id = ?"];
$params = [$classeId];
$types  = 'i';

if ($dateMin !== '') {
    $wheres[] = "a.date_appel >= ?";
    $params[] = $dateMin;
    $types   .= 's';
}
if ($dateMax !== '') {
    $wheres[] = "a.date_appel <= ?";
    $params[] = $dateMax;
    $types   .= 's';
}

$whereSql = 'WHERE '.implode(' AND ', $wheres);

// =====================
// Récupérer historique
// =====================
$sql = "
  SELECT 
    a.id,
    a.date_appel,
    a.anneeScolaire,
    COUNT(ad.id) AS total,
    SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS presents,
    SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absents
  FROM appel a
  LEFT JOIN appel_detail ad ON ad.appel_id = a.id
  $whereSql
  GROUP BY a.id, a.date_appel, a.anneeScolaire
  ORDER BY a.date_appel DESC
  LIMIT 100
";

$stmt = $con->prepare($sql);
if (!$stmt) {
    // debug basique si besoin
    echo "<div class='container'><div class='alert alert-danger'>Erreur SQL: ".e($con->error)."</div></div>";
    include __DIR__.'/../layout/footer.php';
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h5 mb-1">Historique des appels</h1>
      <div class="text-muted small">Classe actuelle — derniers enregistrements</div>
    </div>
    <a href="/prof/eleves/registre_d_appel.php" class="btn btn-sm btn-outline-primary">
      ➕ Nouvel appel / Modifier
    </a>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Date min</label>
          <input type="date" name="date_min" value="<?= e($dateMin) ?>"
                 class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Date max</label>
          <input type="date" name="date_max" value="<?= e($dateMax) ?>"
                 class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-4 d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary mt-3">Filtrer</button>
          <?php if ($dateMin !== '' || $dateMax !== ''): ?>
            <a href="/prof/eleves/historique_appels.php" class="btn btn-sm btn-outline-dark mt-3">Réinitialiser</a>
          <?php endif; ?>
        </div>
      </form>
      <div class="form-text mt-1 small">
        Affichage limité aux 100 derniers résultats après filtre.
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Année scolaire</th>
            <th class="text-center">Total marqués</th>
            <th class="text-center">Présents</th>
            <th class="text-center">Absents</th>
            <th class="text-center" style="width:1%">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="6"><em>Aucun appel trouvé pour cette classe (selon le filtre).</em></td>
          </tr>
        <?php else: foreach ($rows as $r):
          $total   = (int)($r['total'] ?? 0);
          $present = (int)($r['presents'] ?? 0);
          $absent  = (int)($r['absents'] ?? 0);
          $dateFr  = format_date_fr($r['date_appel']);
        ?>
          <tr>
            <td><?= e($dateFr) ?></td>
            <td><?= e($r['anneeScolaire'] ?? '—') ?></td>
            <td class="text-center"><?= $total ?></td>
            <td class="text-center text-success"><?= $present ?></td>
            <td class="text-center text-danger"><?= $absent ?></td>
            <td class="text-center">
              <a href="/prof/eleves/registre_d_appel.php?date=<?= e($r['date_appel']) ?>"
                 class="btn btn-sm btn-outline-primary">
                Ouvrir / Modifier
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__.'/../layout/footer.php'; ?>
