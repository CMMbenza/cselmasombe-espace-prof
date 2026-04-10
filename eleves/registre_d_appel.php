<?php
// /prof/eleves/registre_d_appel.php
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
// Gestion de la date
// =====================
$today = date('Y-m-d');
// priorité : POST > GET > aujourd'hui
$dateAppel = $_POST['date_appel'] ?? $_GET['date'] ?? $today;

// sécuriser le format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateAppel)) {
    $dateAppel = $today;
}

// =====================
// Vérifier si un appel existe pour cette date
// =====================
$stmt = $con->prepare("SELECT id FROM appel WHERE classe_id = ? AND date_appel = ? LIMIT 1");
if (!$stmt) {
    echo "<div class='container'><div class='alert alert-danger'>Erreur SQL (prepare appel): ".e($con->error)."</div></div>";
    include __DIR__.'/../layout/footer.php';
    exit;
}
$stmt->bind_param('is', $classeId, $dateAppel);
$stmt->execute();
$appelId = 0;
if ($res = $stmt->get_result()) {
    if ($row = $res->fetch_assoc()) {
        $appelId = (int)$row['id'];
    }
}
$stmt->close();

$msg = '';
$err = '';

// =====================
// Traitement POST (enregistrement)
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $states = $_POST['presence'] ?? [];

    if (!$states) {
        $err = "Aucune donnée reçue.";
    } else {
        try {
            // 1) Créer l'en-tête si besoin (created_by = NULL pour éviter le FK)
            if ($appelId === 0) {
                $sql = "
                  INSERT INTO appel (classe_id, date_appel, anneeScolaire, created_by)
                  VALUES (?, ?, ?, NULL)
                ";
                $stmt = $con->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Erreur prepare appel: ".$con->error);
                }
                // si tu n'as pas current_year(), remplace par '2024-2025' ou autre
                $annee  = function_exists('current_year') ? current_year() : date('Y');
                $stmt->bind_param('iss', $classeId, $dateAppel, $annee);
                if (!$stmt->execute()) {
                    throw new Exception("Erreur execute appel: ".$stmt->error);
                }
                $appelId = $stmt->insert_id;
                $stmt->close();
            }

            // 2) Insérer / mettre à jour les détails
            $sqlDetail = "
              INSERT INTO appel_detail (appel_id, eleve_id, statut)
              VALUES (?, ?, ?)
              ON DUPLICATE KEY UPDATE statut = ?
            ";
            $stmtDetail = $con->prepare($sqlDetail);
            if (!$stmtDetail) {
                throw new Exception("Erreur prepare appel_detail: ".$con->error);
            }

            foreach ($states as $id => $etat) {
                $eleveId = (int)$id;
                $etat = ($etat === 'absent') ? 'absent' : 'present';

                $stmtDetail->bind_param('iiss', $appelId, $eleveId, $etat, $etat);
                if (!$stmtDetail->execute()) {
                    throw new Exception("Erreur execute appel_detail: ".$stmtDetail->error);
                }
            }
            $stmtDetail->close();

            $msg = "Appel enregistré avec succès pour la date du {$dateAppel}.";
        } catch (Throwable $e) {
            $err = "Erreur lors de l'enregistrement : ".$e->getMessage();
        }
    }
}

// =====================
// Charger les élèves
// =====================
$sqlEleves = "
  SELECT id, nom, postnom, prenom
  FROM eleve
  WHERE classe = ?
  ORDER BY nom, postnom, prenom
";
$stmt = $con->prepare($sqlEleves);
if (!$stmt) {
    echo "<div class='container'><div class='alert alert-danger'>Erreur SQL (prepare élèves): ".e($con->error)."</div></div>";
    include __DIR__.'/../layout/footer.php';
    exit;
}
$stmt->bind_param('i', $classeId);
$stmt->execute();
$eleves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// =====================
// Charger les statuts existants pour cette date
// =====================
$currentPresence = [];
if ($appelId > 0) {
    $stmt = $con->prepare("SELECT eleve_id, statut FROM appel_detail WHERE appel_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $appelId);
        $stmt->execute();
        $rs = $stmt->get_result();
        foreach ($rs->fetch_all(MYSQLI_ASSOC) as $r) {
            $currentPresence[(int)$r['eleve_id']] = $r['statut'];
        }
        $stmt->close();
    }
}
?>

<div class="container">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h5 mb-1">Registre d’appel</h1>
      <div class="text-muted small">Classe actuelle — appel par date</div>
    </div>
    <div class="text-end">
      <a href="/prof/eleves/historique_appels.php" class="btn btn-sm btn-outline-secondary">
        📅 Historique des appels
      </a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
  <?php endif; ?>

  <?php if ($msg): ?>
    <div class="alert alert-success py-2"><?= e($msg) ?></div>
  <?php endif; ?>

  <?php if (!$eleves): ?>
    <div class="alert alert-warning">Aucun élève trouvé dans cette classe.</div>
  <?php else: ?>

  <form method="post" class="card shadow-sm">

    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <label for="date_appel" class="form-label mb-0 small text-muted">Date d’appel :</label>
        <input type="date"
               class="form-control form-control-sm"
               id="date_appel"
               name="date_appel"
               value="<?= e($dateAppel) ?>">
      </div>

      <div class="d-flex gap-2">
        <button type="button" id="btnAllPresent" class="btn btn-success btn-sm">✔️ Tout présent</button>
        <button type="button" id="btnAllAbsent"  class="btn btn-danger btn-sm">❌ Tout absent</button>
      </div>
    </div>

    <div class="card-body table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Élève</th>
            <th class="text-center">Statut</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($eleves as $i => $e):
          $id   = (int)$e['id'];
          $full = e(trim($e['nom'].' '.$e['postnom'].' '.$e['prenom']));
          $etat = $currentPresence[$id] ?? 'present';
        ?>
          <tr data-id="<?= $id ?>">
            <td><?= $i+1 ?></td>
            <td><?= $full ?></td>
            <td class="text-center">
              <input type="hidden" name="presence[<?= $id ?>]" value="<?= $etat ?>">
              <div class="btn-group">
                <button type="button"
                        class="btn btn-sm btn-present <?= $etat==='present' ? 'btn-success' : 'btn-outline-success' ?>">
                  Présent
                </button>
                <button type="button"
                        class="btn btn-sm btn-absent <?= $etat==='absent' ? 'btn-danger' : 'btn-outline-danger' ?>">
                  Absent
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card-footer d-flex justify-content-between">
      <div>
        <?= $appelId>0
          ? '<span class="badge bg-warning text-dark">Modification pour la date '.e($dateAppel).'</span>'
          : '<span class="badge bg-success">Nouvel appel pour la date '.e($dateAppel).'</span>' ?>
      </div>
      <button class="btn btn-primary btn-sm">Enregistrer l’appel</button>
    </div>

  </form>

  <?php endif; ?>
</div>

<script>
// Toggle individuel Présent/Absent
document.querySelectorAll('tr[data-id]').forEach(row => {
  const id     = row.dataset.id;
  const hidden = row.querySelector(`input[name="presence[${id}]"]`);
  const btnP   = row.querySelector('.btn-present');
  const btnA   = row.querySelector('.btn-absent');

  btnP.addEventListener('click', () => {
    hidden.value = 'present';
    btnP.classList.add('btn-success');
    btnP.classList.remove('btn-outline-success');
    btnA.classList.add('btn-outline-danger');
    btnA.classList.remove('btn-danger');
  });

  btnA.addEventListener('click', () => {
    hidden.value = 'absent';
    btnA.classList.add('btn-danger');
    btnA.classList.remove('btn-outline-danger');
    btnP.classList.add('btn-outline-success');
    btnP.classList.remove('btn-success');
  });
});

// Tout présent
document.getElementById('btnAllPresent')?.addEventListener('click', () => {
  document.querySelectorAll('tr[data-id]').forEach(row => {
    const hidden = row.querySelector('input[name^="presence"]');
    const btnP   = row.querySelector('.btn-present');
    const btnA   = row.querySelector('.btn-absent');
    hidden.value = 'present';
    btnP.classList.add('btn-success');
    btnP.classList.remove('btn-outline-success');
    btnA.classList.add('btn-outline-danger');
    btnA.classList.remove('btn-danger');
  });
});

// Tout absent
document.getElementById('btnAllAbsent')?.addEventListener('click', () => {
  document.querySelectorAll('tr[data-id]').forEach(row => {
    const hidden = row.querySelector('input[name^="presence"]');
    const btnP   = row.querySelector('.btn-present');
    const btnA   = row.querySelector('.btn-absent');
    hidden.value = 'absent';
    btnA.classList.add('btn-danger');
    btnA.classList.remove('btn-outline-danger');
    btnP.classList.add('btn-outline-success');
    btnP.classList.remove('btn-success');
  });
});
</script>

<?php include __DIR__.'/../layout/footer.php'; ?>
