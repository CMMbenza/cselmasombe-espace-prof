<?php
// prof/switch_classe.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';

require_prof();
$prof = current_prof();

// Récupère les classes affectées (avec cycle)
$classes = classes_of_agent($con, (int)$prof['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $cid = (int)($_POST['classe_id'] ?? 0);
  $allowed = array_map('intval', array_column($classes, 'id'));
  if ($cid && in_array($cid, $allowed, true)) {
    set_current_classe($cid);
    redirect('/prof/dashboard.php');
  }
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>
<div class="container">
  <div class="card card-soft">
    <div class="card-body">
      <h2 class="h5 mb-3">Changer de classe</h2>
      <?php if (!$classes): ?>
        <div class="alert alert-warning">Aucune classe ne vous est affectée.</div>
      <?php else: ?>
        <form method="post" class="row g-3 mt-2">
          <div class="col-md-6">
            <label class="form-label">Classe</label>
            <select name="classe_id" class="form-select" required>
              <option value="">— Sélectionnez —</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (get_current_classe()===(int)$c['id'])?'selected':'' ?>>
                  <?= e($c['description']) ?> — Cycle: <?= e($c['cycle_desc']) ?> (ID: <?= (int)$c['id'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <button class="btn btn-primary">Valider</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__.'/layout/footer.php'; ?>
