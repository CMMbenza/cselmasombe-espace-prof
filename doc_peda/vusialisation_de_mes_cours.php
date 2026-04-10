<?php
/**
 * PROF : Visualisation de mes cours + périodes + pondérations + examens
 * Fichier : /prof/doc_peda/visualisation_de_mes_cours.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_prof(); // protège l'accès

// Prof et classe courante
$prof     = current_prof();
$classeId = get_current_classe();

// Layout
include __DIR__ . '/../layout/header.php';
include __DIR__ . '/../layout/navbar.php';

// Si aucune classe sélectionnée
if (!$classeId): ?>
  <div class="container py-4">
    <div class="alert alert-info">
      Aucune classe sélectionnée.<br>
      <a href="/prof/switch_classe.php" class="btn btn-sm btn-outline-primary mt-2">
        📚 Choisir une classe
      </a>
    </div>
  </div>
  <?php include __DIR__ . '/../layout/footer.php'; exit;
endif;

// Vérifier que l'on a bien un prof valide
if (!$prof || empty($prof['id'])): ?>
  <div class="container py-4">
    <div class="alert alert-danger">
      Impossible d'identifier le professeur connecté (ID agent manquant).
    </div>
  </div>
  <?php include __DIR__ . '/../layout/footer.php'; exit;
endif;

$profId = (int)$prof['id'];

$rows      = [];
$error     = '';
$coursData = [];

try {
    /** @var mysqli $con */

    $sql = "
        SELECT 
            c.id            AS cours_id,
            c.intitule      AS cours,
            cl.description  AS classe,
            cy.description  AS cycle,
            p.CODE          AS periode_code,
            p.libelle       AS periode_libelle,
            p.ordre         AS periode_ordre,
            cp.points       AS ponderation_points
        FROM affectation_prof_classe apc
        JOIN cours  c  ON c.id  = apc.cours_id
        JOIN classe cl ON cl.id = apc.classe_id
        LEFT JOIN cycle cy      ON cy.id = cl.cycle
        LEFT JOIN cours_ponderations cp ON cp.cours_id = c.id
        LEFT JOIN periodes p           ON p.id = cp.periode_id
        WHERE 
            apc.agent_id  = ?
            AND apc.classe_id = ?
        ORDER BY 
            cl.description,
            c.intitule,
            p.ordre
    ";

    $stmt = $con->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Erreur préparation requête : ".$con->error);
    }

    $stmt->bind_param('ii', $profId, $classeId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Regrouper par cours
    foreach ($rows as $r) {
        $cid = (int)$r['cours_id'];
        if (!isset($coursData[$cid])) {
            $coursData[$cid] = [
                'cours'    => $r['cours'],
                'classe'   => $r['classe'],
                'cycle'    => $r['cycle'] ?? '',
                'periodes' => [], // indexées par CODE (P1..P6)
            ];
        }

        $code = $r['periode_code'];
        if ($code !== null && $code !== '') {
            $coursData[$cid]['periodes'][$code] = [
                'code'    => $code,
                'libelle' => $r['periode_libelle'],
                'points'  => $r['ponderation_points'] !== null 
                               ? (float)$r['ponderation_points'] 
                               : null,
            ];
        }
    }

} catch (Throwable $e) {
    $error = "Impossible de charger vos cours et le résumé des périodes / pondérations.";
}

/**
 * Détecte si le cycle est de type primaire/maternelle
 */
function is_cycle_primaire(string $label): bool {
    $l = mb_strtolower($label, 'UTF-8');
    if ($l === '') return false;
    return (strpos($l, 'prim') !== false) || (strpos($l, 'mater') !== false);
}
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h5 mb-1">📚 Résumé pédagogique de mes cours</h1>
      <div class="text-muted small">
        Périodes, pondérations et examens (EX) définis par la direction.
      </div>
    </div>
    <a href="/prof/eleves.php" class="btn btn-sm btn-outline-secondary">
      👥 Voir les élèves de la classe
    </a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$coursData && !$error): ?>
    <div class="alert alert-warning">
      Aucun cours trouvé pour cette classe, ou aucune affectation n’a été définie pour vous.<br>
      <span class="small text-muted">
        Vérifiez que la direction a bien :
        <ul class="mb-0">
          <li>affecté vos cours à cette classe,</li>
          <li>défini les périodes et pondérations pour chaque cours.</li>
        </ul>
      </span>
    </div>
  <?php endif; ?>

  <?php if ($coursData): ?>
    <?php foreach ($coursData as $cid => $c): 
        $cycleLabel = (string)($c['cycle'] ?? '');
        $isPrimaire = is_cycle_primaire($cycleLabel);

        // Liste théorique des périodes selon le cycle
        if ($isPrimaire) {
            // Primaire / Maternelle : P1..P6 => EX1, EX2, EX3, TOT
            $wantedCodes = ['P1','P2','P3','P4','P5','P6'];
        } else {
            // Secondaire / Humanités (ou autre) : P1..P4 => EX1, EX2, TOT
            $wantedCodes = ['P1','P2','P3','P4'];
        }

        // Récupérer les points par code, 0 si non défini (pour les calculs)
        $val = [];
        foreach ($wantedCodes as $code) {
            $val[$code] = 0.0;
            if (isset($c['periodes'][$code]) && $c['periodes'][$code]['points'] !== null) {
                $val[$code] = (float)$c['periodes'][$code]['points'];
            }
        }

        if ($isPrimaire) {
            $EX1 = $val['P1'] + $val['P2'];
            $EX2 = $val['P3'] + $val['P4'];
            $EX3 = $val['P5'] + $val['P6'];
            $TOT = $EX1 + $EX2 + $EX3;
        } else {
            $EX1 = $val['P1'] + $val['P2'];
            $EX2 = $val['P3'] + $val['P4'];
            $EX3 = 0.0;
            $TOT = $EX1 + $EX2;
        }
    ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
              <h2 class="h6 mb-1"><?= e($c['cours']) ?></h2>
              <div class="text-muted small">
                Classe : <strong><?= e($c['classe']) ?></strong>
                <?php if ($cycleLabel): ?>
                  — Cycle : <strong><?= e($cycleLabel) ?></strong>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="mb-2 text-muted small">
            <?php if ($isPrimaire): ?>
              Schéma Primaire/Maternelle : 
              <strong>P1 + P2 = EX1</strong>, 
              <strong>P3 + P4 = EX2</strong>, 
              <strong>P5 + P6 = EX3</strong> → 
              <strong>TOTAL = EX1 + EX2 + EX3</strong>
            <?php else: ?>
              Schéma Secondaire/Humanités : 
              <strong>P1 + P2 = EX1</strong>, 
              <strong>P3 + P4 = EX2</strong> → 
              <strong>TOTAL = EX1 + EX2</strong>
            <?php endif; ?>
          </div>

          <?php if ($isPrimaire): ?>
            <!-- Tableau type Primaire / Maternelle -->
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th rowspan="2" style="min-width:120px;">Résumé</th>
                    <th class="text-center" colspan="3">Bloc 1</th>
                    <th class="text-center" colspan="3">Bloc 2</th>
                    <th class="text-center" colspan="3">Bloc 3</th>
                    <th class="text-center" rowspan="2">TOTAL GÉNÉRAL</th>
                  </tr>
                  <tr>
                    <th class="text-center">P1</th>
                    <th class="text-center">P2</th>
                    <th class="text-center">EX1</th>

                    <th class="text-center">P3</th>
                    <th class="text-center">P4</th>
                    <th class="text-center">EX2</th>

                    <th class="text-center">P5</th>
                    <th class="text-center">P6</th>
                    <th class="text-center">EX3</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="fw-semibold">Pondérations (points)</span></td>

                    <td class="text-center"><?= $val['P1'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center><?= $val['P2'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $EX1 ?></span>
                    </td>

                    <td class="text-center"><?= $val['P3'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><?= $val['P4'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $EX2 ?></span>
                    </td>

                    <td class="text-center"><?= $val['P5'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><?= $val['P6'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $EX3 ?></span>
                    </td>

                    <td class="text-center">
                      <span class="badge bg-primary"><?= $TOT ?></span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <!-- Tableau type Secondaire / Humanités -->
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th rowspan="2" style="min-width:120px;">Résumé</th>
                    <th class="text-center" colspan="3">Bloc 1</th>
                    <th class="text-center" colspan="3">Bloc 2</th>
                    <th class="text-center" rowspan="2">TOTAL GÉNÉRAL</th>
                  </tr>
                  <tr>
                    <th class="text-center">P1</th>
                    <th class="text-center">P2</th>
                    <th class="text-center">EX1</th>

                    <th class="text-center">P3</th>
                    <th class="text-center">P4</th>
                    <th class="text-center">EX2</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="fw-semibold">Pondérations (points)</span></td>

                    <td class="text-center"><?= $val['P1'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><?= $val['P2'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $EX1 ?></span>
                    </td>

                    <td class="text-center"><?= $val['P3'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center"><?= $val['P4'] ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="text-center">
                      <span class="badge bg-light text-dark border"><?= $EX2 ?></span>
                    </td>

                    <td class="text-center">
                      <span class="badge bg-primary"><?= $TOT ?></span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php
          // Petit détail texte pour les périodes réellement configurées
          $codesDefinis = array_keys($c['periodes']);
          if ($codesDefinis): ?>
            <div class="mt-2 small text-muted">
              Périodes configurées : 
              <?php foreach ($codesDefinis as $i => $code): 
                $lib = $c['periodes'][$code]['libelle'] ?? '';
              ?>
                <span class="badge bg-secondary-subtle border text-dark me-1 mb-1">
                  <?= e($code) ?><?= $lib ? ' — '.e($lib) : '' ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="mt-2 small text-danger">
              Aucune pondération n’est encore définie pour ce cours.
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../layout/footer.php'; ?>
