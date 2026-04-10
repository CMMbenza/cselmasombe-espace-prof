<?php
// /prof/doc_peda/fiches_des_eleves.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';

// ✅ Assure la connexion $con (mysqli) si helpers/auth ne l'a pas fait
if (!isset($con) || !($con instanceof mysqli)) {
  $candidates = [
    __DIR__.'/../includes/db_connect.php',
    __DIR__.'/../includes/db.php',
    __DIR__.'/../includes/connexion.php',
    __DIR__.'/../includes/conn.php',
    __DIR__.'/../config/db.php',
  ];
  foreach ($candidates as $f) {
    if (is_file($f)) { require_once $f; break; }
  }
}

require_once __DIR__.'/../includes/helpers.php';
require_prof();

if (!isset($con) || !($con instanceof mysqli)) {
  throw new RuntimeException("Connexion DB requise (\$con). Vérifie le fichier de connexion inclus.");
}

$prof     = current_prof();
$agentId  = (int)($prof['id'] ?? 0);
$classeId = (int)get_current_classe();

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (!$classeId): ?>
<div class="container">
  <div class="alert alert-info">
    Aucune classe sélectionnée.
    <a href="/prof/switch_classe.php">Choisir une classe</a>
  </div>
</div>
<?php include __DIR__.'/../layout/footer.php'; exit; endif;

/* ======================================================
   1) Infos Classe + Cycle + Périodes
====================================================== */
$cycleId = 0;
$classeDesc = '';

$stmt = $con->prepare("SELECT description, cycle FROM classe WHERE id=? LIMIT 1");
$stmt->bind_param('i', $classeId);
$stmt->execute();
if ($r = $stmt->get_result()->fetch_assoc()) {
  $classeDesc = (string)$r['description'];
  $cycleId    = (int)$r['cycle'];
}
$stmt->close();

$periodes = [];
if ($cycleId > 0) {
  $stmt = $con->prepare("
    SELECT id, CODE, libelle, actif
    FROM periodes
    WHERE cycle_id=?
    ORDER BY ordre
  ");
  $stmt->bind_param('i', $cycleId);
  $stmt->execute();
  $periodes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ======================================================
   2) Liste des années scolaires
====================================================== */
$annees = [];
$res = $con->query("SELECT annee_scolaire FROM annee_scolaire ORDER BY dateDebut DESC");
if ($res) {
  while ($row = $res->fetch_assoc()) $annees[] = (string)$row['annee_scolaire'];
}
$anneeFilter = trim((string)($_GET['annee'] ?? '')); // "" => auto

/* ======================================================
   3) Élèves de la classe
====================================================== */
$eleves = [];
$stmt = $con->prepare("
  SELECT id, nom, postnom, prenom, anneeScolaire
  FROM eleve
  WHERE classe=?
  ORDER BY nom, postnom, prenom
");
$stmt->bind_param('i', $classeId);
$stmt->execute();
$eleves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ======================================================
   4) Cours du prof dans la classe
====================================================== */
$coursList = [];
$stmt = $con->prepare("
  SELECT DISTINCT co.id, co.intitule
  FROM affectation_prof_classe apc
  JOIN cours co ON co.id = apc.cours_id
  WHERE apc.agent_id = ? AND apc.classe_id = ?
  ORDER BY co.intitule
");
$stmt->bind_param('ii', $agentId, $classeId);
$stmt->execute();
$coursList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$coursId = isset($_GET['cours_id']) ? (int)$_GET['cours_id'] : 0;

// Sécuriser coursId (doit appartenir à coursList)
if ($coursId > 0) {
  $ok = false;
  foreach ($coursList as $c) {
    if ((int)$c['id'] === $coursId) { $ok = true; break; }
  }
  if (!$ok) $coursId = 0;
}

/* ======================================================
   2bis) Année à charger (AUTO si vide)
====================================================== */
$anneeToLoad = $anneeFilter;
if ($anneeToLoad === '' && $eleves) {
  $anneeToLoad = (string)($eleves[0]['anneeScolaire'] ?? '');
}
if ($anneeToLoad === '') $anneeToLoad = '—';

/* ======================================================
   4.b) Pondérations du cours sélectionné (par période)
====================================================== */
$pondByPeriode = []; // [periode_id] => points

if ($coursId > 0 && $periodes) {
  $periodeIds = array_map(fn($p)=>(int)$p['id'], $periodes);
  $placeP = implode(',', array_fill(0, count($periodeIds), '?'));

  $sqlP = "
    SELECT periode_id, points
    FROM cours_ponderations
    WHERE cours_id = ?
      AND periode_id IN ($placeP)
  ";

  $typesP  = 'i' . str_repeat('i', count($periodeIds));
  $paramsP = array_merge([$coursId], $periodeIds);

  $stmtP = $con->prepare($sqlP);
  $refsP = [];
  foreach ($paramsP as $k => $v) $refsP[$k] = &$paramsP[$k];
  $stmtP->bind_param($typesP, ...$refsP);

  $stmtP->execute();
  $resP = $stmtP->get_result();
  while ($row = $resP->fetch_assoc()) {
    $pondByPeriode[(int)$row['periode_id']] = (int)$row['points'];
  }
  $stmtP->close();
}

/* ======================================================
   5) Charger les points (périodes) depuis cours_points_eleves
====================================================== */
$pointsParEleve = []; // [eleve_id][periode_id] => points_total

if ($coursId > 0 && $eleves && $periodes) {

  $eleveIds = array_map(fn($e)=>(int)$e['id'], $eleves);
  $place = implode(',', array_fill(0, count($eleveIds), '?'));

  $sql = "
    SELECT eleve_id, periode_id, points_total
    FROM cours_points_eleves
    WHERE classe_id = ?
      AND cours_id  = ?
      AND anneeScolaire = ?
      AND eleve_id IN ($place)
  ";

  $types = 'iis' . str_repeat('i', count($eleveIds));
  $params = array_merge([$classeId, $coursId, $anneeToLoad], $eleveIds);

  $stmt = $con->prepare($sql);
  $refs = [];
  foreach ($params as $k => $v) $refs[$k] = &$params[$k];
  $stmt->bind_param($types, ...$refs);

  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $eid = (int)$row['eleve_id'];
    $pid = (int)$row['periode_id'];
    $pointsParEleve[$eid][$pid] = $row['points_total'] !== null ? (float)$row['points_total'] : null;
  }
  $stmt->close();
}

/* ======================================================
   6) Regroupement des périodes 2 par 2 => Examen (EX_T1/EX_T2/EX_T3)
====================================================== */
$groups = []; // chaque groupe: ['periodes'=>[p1,p2], 'label'=>'Examen 1', 'pond_exam'=>int|null, 'exam_key'=>'EX_T1']
if ($periodes) {
  $chunks = array_chunk($periodes, 2);
  $i = 1;
  foreach ($chunks as $chunk) {
    if (count($chunk) === 2) {
      $p1 = $chunk[0]; $p2 = $chunk[1];
      $pid1 = (int)$p1['id']; $pid2 = (int)$p2['id'];
      $pond1 = $pondByPeriode[$pid1] ?? null;
      $pond2 = $pondByPeriode[$pid2] ?? null;
      $pondExam = null;
      if ($pond1 !== null || $pond2 !== null) {
        $pondExam = (int)($pond1 ?? 0) + (int)($pond2 ?? 0);
      }

      $examKey = 'EX_T'.$i; // ✅ clé examen cohérente avec dossier_eleves.php

      $groups[] = [
        'periodes'  => [$p1, $p2],
        'label'     => 'Examen '.$i,
        'pond_exam' => $pondExam,
        'exam_key'  => $examKey,
      ];
      $i++;
    } else {
      // cas bizarre: période seule (on l'affiche sans examen)
      $groups[] = [
        'periodes'  => [$chunk[0]],
        'label'     => null,
        'pond_exam' => null,
        'exam_key'  => null,
      ];
    }
  }
}

/* ======================================================
   7) Charger les notes d'examen depuis cours_points_examens
      => prioritaire sur la somme (P1+P2)
====================================================== */
$examParEleve = []; // [eleve_id][exam_key] => points_total (override)
if ($coursId > 0 && $eleves && $groups) {

  // extraire les exam keys existants
  $examKeys = [];
  foreach ($groups as $g) {
    if (!empty($g['exam_key'])) $examKeys[] = (string)$g['exam_key'];
  }
  $examKeys = array_values(array_unique($examKeys));

  if ($examKeys) {
    $eleveIds = array_map(fn($e)=>(int)$e['id'], $eleves);

    $placeE = implode(',', array_fill(0, count($eleveIds), '?'));
    $placeK = implode(',', array_fill(0, count($examKeys), '?'));

    $sqlEx = "
      SELECT eleve_id, examen_key, points_total
      FROM cours_points_examens
      WHERE classe_id = ?
        AND cours_id  = ?
        AND anneeScolaire = ?
        AND eleve_id IN ($placeE)
        AND examen_key IN ($placeK)
    ";

    $typesEx  = 'iis' . str_repeat('i', count($eleveIds)) . str_repeat('s', count($examKeys));
    $paramsEx = array_merge([$classeId, $coursId, $anneeToLoad], $eleveIds, $examKeys);

    $stmtEx = $con->prepare($sqlEx);
    $refsEx = [];
    foreach ($paramsEx as $k => $v) $refsEx[$k] = &$paramsEx[$k];
    $stmtEx->bind_param($typesEx, ...$refsEx);

    $stmtEx->execute();
    $resEx = $stmtEx->get_result();
    while ($row = $resEx->fetch_assoc()) {
      $eid = (int)$row['eleve_id'];
      $k   = (string)$row['examen_key'];
      $examParEleve[$eid][$k] = ($row['points_total'] !== null) ? (float)$row['points_total'] : null;
    }
    $stmtEx->close();
  }
}

?>
<div class="container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h5 mb-0">Fiches des élèves — Classe <?= e($classeDesc) ?></h1>
    <a href="/prof/eleves.php" class="btn btn-sm btn-primary">
      + Saisie des points
    </a>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <?php if (!$coursList): ?>
        <div class="alert alert-warning mb-0">Aucun cours ne vous est affecté dans cette classe.</div>
      <?php else: ?>
        <form method="get" class="row g-2 align-items-end">
          <div class="col-md-5">
            <label class="form-label">Cours</label>
            <select name="cours_id" class="form-select" onchange="this.form.submit()">
              <option value="0">— Choisir —</option>
              <?php foreach ($coursList as $co): ?>
                <option value="<?= (int)$co['id'] ?>" <?= $coursId===(int)$co['id']?'selected':'' ?>>
                  <?= e($co['intitule']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Année scolaire</label>
            <select name="annee" class="form-select" onchange="this.form.submit()">
              <option value="">Auto</option>
              <?php foreach ($annees as $an): ?>
                <option value="<?= e($an) ?>" <?= $anneeFilter === $an ? 'selected' : '' ?>>
                  <?= e($an) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-none col-md-3 text-end">
            <?php if ($coursId > 0): ?>
              <a class="btn btn-sm btn-outline-secondary"
                 href="/prof/doc_peda/saisie_points_eleves.php?cours_id=<?= (int)$coursId ?>&annee=<?= urlencode($anneeFilter) ?>">
                Encoder maintenant
              </a>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($coursId <= 0): ?>
    <div class="alert alert-info">Veuillez sélectionner un cours.</div>

  <?php elseif (!$eleves): ?>
    <div class="alert alert-info">Aucun élève trouvé.</div>

  <?php elseif (!$periodes): ?>
    <div class="alert alert-info">Aucune période trouvée pour le cycle de cette classe.</div>

  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body table-responsive">
        <h2 class="h6 mb-3">Récapitulatif des points (Périodes + Examens)</h2>

        <table class="table table-bordered table-sm align-middle">
          <thead class="table-light">
            <!-- Ligne 1: groupes -->
            <tr>
              <th rowspan="2">Élève</th>
              <?php foreach ($groups as $g): ?>
                <?php if (count($g['periodes']) === 2): ?>
                  <th class="text-center" colspan="3">Bloc <?= e((string)$g['periodes'][0]['CODE']) ?> - <?= e((string)$g['periodes'][1]['CODE']) ?></th>
                <?php else: ?>
                  <th class="text-center" colspan="1"><?= e((string)$g['periodes'][0]['CODE']) ?></th>
                <?php endif; ?>
              <?php endforeach; ?>
              <th class="text-center" rowspan="2">Total</th>
            </tr>

            <!-- Ligne 2: colonnes détaillées -->
            <tr>
              <?php foreach ($groups as $g): ?>
                <?php
                  $ps = $g['periodes'];
                  if (count($ps) === 2) {
                    foreach ($ps as $p) {
                      $pid  = (int)$p['id'];
                      $code = (string)($p['CODE'] ?? 'P?');
                      $pond = $pondByPeriode[$pid] ?? null;
                      ?>
                      <th class="text-center">
                        <div class="fw-semibold"><?= e($code) ?></div>
                        <div class="small text-muted"><?= $pond !== null ? e((string)$pond).' pts' : '—' ?></div>
                      </th>
                      <?php
                    }
                    $pondExam = $g['pond_exam'];
                    ?>
                    <th class="text-center">
                      <div class="fw-semibold"><?= e((string)$g['label']) ?></div>
                      <div class="small text-muted"><?= $pondExam !== null ? e((string)$pondExam).' pts' : '—' ?></div>
                      <div class="small text-muted"><?= e((string)($g['exam_key'] ?? '')) ?></div>
                    </th>
                    <?php
                  } else {
                    // période seule
                    $p = $ps[0];
                    $pid  = (int)$p['id'];
                    $code = (string)($p['CODE'] ?? 'P?');
                    $pond = $pondByPeriode[$pid] ?? null;
                    ?>
                    <th class="text-center">
                      <div class="fw-semibold"><?= e($code) ?></div>
                      <div class="small text-muted"><?= $pond !== null ? e((string)$pond).' pts' : '—' ?></div>
                    </th>
                    <?php
                  }
                ?>
              <?php endforeach; ?>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($eleves as $el):
              $eid  = (int)$el['id'];
              $name = trim(($el['nom'] ?? '').' '.($el['postnom'] ?? '').' '.($el['prenom'] ?? ''));
              $total = 0.0;
            ?>
              <tr>
                <td><?= e($name) ?></td>

                <?php foreach ($groups as $g): ?>
                  <?php
                    $ps = $g['periodes'];

                    if (count($ps) === 2) {
                      $p1 = $ps[0]; $p2 = $ps[1];
                      $pid1 = (int)$p1['id']; $pid2 = (int)$p2['id'];

                      $v1 = $pointsParEleve[$eid][$pid1] ?? null;
                      $v2 = $pointsParEleve[$eid][$pid2] ?? null;

                      if ($v1 !== null) $total += (float)$v1;
                      if ($v2 !== null) $total += (float)$v2;

                      // ✅ Examen: priorité à la table cours_points_examens, sinon somme P1+P2
                      $examKey = (string)($g['exam_key'] ?? '');
                      $examOverride = ($examKey !== '') ? ($examParEleve[$eid][$examKey] ?? null) : null;

                      $examAuto = null;
                      if ($v1 !== null || $v2 !== null) $examAuto = (float)($v1 ?? 0) + (float)($v2 ?? 0);

                      $examVal = ($examOverride !== null) ? (float)$examOverride : $examAuto;

                      $examBadge = '';
                      if ($examOverride !== null) {
                        $examBadge = ' <span class="d-none badge bg-primary-subtle text-primary border border-primary-subtle">modifié</span>';
                      }
                      ?>
                      <td class="text-center"><?= $v1 !== null ? number_format((float)$v1,2,',',' ') : '<span class="text-muted">—</span>' ?></td>
                      <td class="text-center"><?= $v2 !== null ? number_format((float)$v2,2,',',' ') : '<span class="text-muted">—</span>' ?></td>
                      <td class="text-center fw-semibold">
                        <?= $examVal !== null ? number_format((float)$examVal,2,',',' ') : '<span class="text-muted">—</span>' ?>
                        <?= $examBadge ?>
                        <?php if ($examOverride !== null && $examAuto !== null): ?>
                          <div class="d-none small text-muted">Auto: <?= number_format((float)$examAuto,2,',',' ') ?></div>
                        <?php endif; ?>
                      </td>
                      <?php
                    } else {
                      $p = $ps[0];
                      $pid = (int)$p['id'];
                      $v = $pointsParEleve[$eid][$pid] ?? null;
                      if ($v !== null) $total += (float)$v;
                      ?>
                      <td class="text-center"><?= $v !== null ? number_format((float)$v,2,',',' ') : '<span class="text-muted">—</span>' ?></td>
                      <?php
                    }
                  ?>
                <?php endforeach; ?>

                <td class="text-center fw-bold">
                  <?= $total > 0 ? number_format($total,2,',',' ') : '<span class="text-muted">—</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="small text-muted mt-2">
          <div>• Les colonnes <strong>Examen</strong> affichent par défaut la somme de deux périodes (ex: P1 + P2).</div>
          <div>• Si une note existe dans <code>cours_points_examens</code>, elle remplace la somme et une étiquette <strong>modifié</strong> apparaît.</div>
          <div>• La pondération “Examen” = pond(P1) + pond(P2) depuis <code>cours_ponderations</code>.</div>
          <div>• Le <strong>Total</strong> = somme des périodes (donc indépendant des overrides d’examen).</div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/../layout/footer.php'; ?>
