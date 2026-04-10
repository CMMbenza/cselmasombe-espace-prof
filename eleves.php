<?php
// /prof/eleves.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof     = current_prof();
$classeId = get_current_classe();

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';

if (!$classeId): ?>
  <div class="container">
    <div class="alert alert-info">
      Aucune classe sélectionnée. 
      <a href="/prof/switch_classe.php">Choisir une classe</a>
    </div>
  </div>
  <?php include __DIR__.'/layout/footer.php'; exit;
endif;

$eleves = [];
$stmt = $con->prepare("
  SELECT id, nom, postnom, prenom, genre, dateDeNaissance 
  FROM eleve 
  WHERE classe = ? 
  ORDER BY nom, postnom, prenom
");
$stmt->bind_param('i', $classeId);
$stmt->execute();
$eleves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">Élèves de la classe</h1>
    <input type="search" id="search" class="form-control" style="max-width:260px" placeholder="Rechercher...">
  </div>

  <div class="card shadow-sm">
    <div class="card-body table-responsive">
      <table class="table table-sm align-middle" id="tbl">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Nom complet</th>
            <th>Genre</th>
            <th>Date de naissance</th>
            <th style="width:1%">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$eleves): ?>
            <tr><td colspan="5"><em>Aucun élève.</em></td></tr>
          <?php else: foreach($eleves as $i=>$e): 
            $fullName = trim(($e['nom']??'').' '.($e['postnom']??'').' '.($e['prenom']??''));
          ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><?= e($fullName) ?></td>
              <td><?= e($e['genre']) ?></td>
              <td><?= e($e['dateDeNaissance']) ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-success"
                   href="/prof/eleves/dossier_eleves.php?eleve_id=<?= (int)$e['id'] ?>">
                  Ouvrir dossier élève
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
document.getElementById('search')?.addEventListener('input', function(){
  const q = this.value.toLowerCase();
  document.querySelectorAll('#tbl tbody tr').forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
  });
});
</script>
<?php include __DIR__.'/layout/footer.php'; ?>
