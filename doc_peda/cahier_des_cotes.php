<?php
// /prof/doc_peda/cahier_des_cotes.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];

$classeId = get_current_classe();
if (!$classeId) {
    include __DIR__.'/../layout/header.php';
    include __DIR__.'/../layout/navbar.php';
    echo '<div class="container mt-3"><div class="alert alert-info">
            Aucune classe sélectionnée. <a href="/prof/switch_classe.php">Choisir une classe</a>
          </div></div>';
    include __DIR__.'/../layout/footer.php';
    exit;
}

// Infos classe
$classeMeta = current_classe_meta($con, $classeId);
$cycleId    = (int)($classeMeta['cycle_id'] ?? 0);

// 1) Périodes du cycle
$periodes = [];
if ($cycleId > 0) {
    $stmt = $con->prepare("
        SELECT id, CODE, libelle, actif 
        FROM periodes 
        WHERE cycle_id = ? 
        ORDER BY ordre, id
    ");
    $stmt->bind_param('i', $cycleId);
    $stmt->execute();
    $periodes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 2) Cours enseignés dans cette classe par ce prof
$coursList = [];
$stmt = $con->prepare("
    SELECT co.id, co.intitule
    FROM cours co
    INNER JOIN affectation_prof_classe apc
      ON apc.cours_id = co.id
     AND apc.agent_id = ?
    WHERE co.classe_id = ?
    ORDER BY co.intitule
");
$stmt->bind_param('ii', $agentId, $classeId);
$stmt->execute();
$coursList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3) Élèves de la classe
$eleves = [];
$stmt = $con->prepare("
    SELECT id, nom, postnom, prenom 
    FROM eleve 
    WHERE classe = ? 
    ORDER BY nom, postnom, prenom
");
$stmt->bind_param('i', $classeId);
$stmt->execute();
$eleves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ------ Filtres sélectionnés ------
$periodeId = (int)($_REQUEST['periode_id'] ?? 0);
$coursId   = (int)($_REQUEST['cours_id'] ?? 0);
$eleveId   = (int)($_REQUEST['eleve_id'] ?? 0);
$typeApp   = trim((string)($_REQUEST['type_app'] ?? ''));

// Valeurs par défaut
if (!$periodeId && $periodes)  $periodeId = (int)$periodes[0]['id'];
if (!$coursId && $coursList)   $coursId   = (int)$coursList[0]['id'];
// pour l'élève, on laisse 0 = aucun élève sélectionné

$msg = '';
$err = '';

// =================== ENREGISTREMENT D'UNE NOUVELLE APPRÉCIATION ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $periodeId = (int)($_POST['periode_id'] ?? 0);
    $coursId   = (int)($_POST['cours_id'] ?? 0);
    $eleveId   = (int)($_POST['eleve_id'] ?? 0);
    $typeApp   = trim((string)($_POST['type_app'] ?? ''));
    $points    = trim((string)($_POST['points'] ?? ''));
    $remarque  = trim((string)($_POST['remarque'] ?? ''));

    if ($eleveId <= 0 || $coursId <= 0 || $periodeId <= 0) {
        $err = "Données manquantes (période, cours ou élève).";
    } elseif ($points === '' && $remarque === '' && $typeApp === '') {
        $msg = "Aucune donnée significative saisie.";
    } else {
        $pVal = ($points !== '') ? (float)$points : null;

        // Insertion simple : chaque appréciation = une ligne
        $sql = "
            INSERT INTO cahier_cotes (
                eleve_id, classe_id, cours_id, periode_id,
                type_app, points, remarque, created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $con->prepare($sql);
        $stmt->bind_param(
            'iiiisds',
            $eleveId,
            $classeId,
            $coursId,
            $periodeId,
            $typeApp,
            $pVal,
            $remarque
        );

        if ($stmt->execute()) {
            $msg = "Appréciation ajoutée avec succès.";
        } else {
            $err = "Erreur SQL : ".$stmt->error;
        }
        $stmt->close();
    }
}

// =================== RÉCAP DE LA CLASSE (tableau global) ===================
$classCotes = [];
if ($coursId > 0 && $periodeId > 0) {
    $wheres = [
        "cc.classe_id = ?",
        "cc.cours_id  = ?",
        "cc.periode_id = ?"
    ];
    $params = [$classeId, $coursId, $periodeId];
    $types  = 'iii';

    if ($typeApp !== '') {
        $wheres[] = "cc.type_app = ?";
        $params[] = $typeApp;
        $types   .= 's';
    }

    $sql = "
        SELECT 
            cc.eleve_id,
            e.nom, e.postnom, e.prenom,
            COUNT(*) AS nb_app,
            SUM(CASE WHEN cc.points IS NULL THEN 0 ELSE cc.points END) AS total_points,
            AVG(cc.points) AS moyenne_points
        FROM cahier_cotes cc
        INNER JOIN eleve e ON e.id = cc.eleve_id
        WHERE ".implode(' AND ', $wheres)."
        GROUP BY cc.eleve_id, e.nom, e.postnom, e.prenom
        ORDER BY e.nom, e.postnom, e.prenom
    ";

    $stmt = $con->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $classCotes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// =================== HISTORIQUE DÉTAILLÉ POUR L’ÉLÈVE SÉLECTIONNÉ ===================
$cotesEleve = [];
if ($eleveId > 0 && $coursId > 0 && $periodeId > 0) {
    $wheres = [
        "eleve_id  = ?",
        "classe_id = ?",
        "cours_id  = ?",
        "periode_id = ?"
    ];
    $params = [$eleveId, $classeId, $coursId, $periodeId];
    $types  = 'iiii';

    if ($typeApp !== '') {
        $wheres[] = "type_app = ?";
        $params[] = $typeApp;
        $types   .= 's';
    }

    $sql = "
        SELECT type_app, points, remarque, created_at
        FROM cahier_cotes
        WHERE ".implode(' AND ', $wheres)."
        ORDER BY created_at DESC
    ";
    $stmt = $con->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $cotesEleve = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';
?>
<div class="container mt-3">
    <h1 class="h5 mb-3">Cahier des cotes</h1>

    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

    <!-- Filtres -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Période</label>
                    <select name="periode_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($periodes as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $p['id']===$periodeId?'selected':'' ?>>
                            <?= e($p['libelle']) ?> (<?= e($p['CODE']) ?>)
                            <?= $p['actif'] ? ' — actif' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Cours</label>
                    <select name="cours_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($coursList as $co): ?>
                        <option value="<?= (int)$co['id'] ?>" <?= $co['id']===$coursId?'selected':'' ?>>
                            <?= e($co['intitule']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Élève (détails)</label>
                    <select name="eleve_id" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= $eleveId===0?'selected':'' ?>>— Tous (classe) —</option>
                        <?php foreach ($eleves as $e): ?>
                        <?php $nom = trim($e['nom'].' '.$e['postnom'].' '.$e['prenom']); ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $eleveId===(int)$e['id']?'selected':'' ?>>
                            <?= e($nom) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Type d’appréciation</label>
                    <input type="text"
                           name="type_app"
                           class="form-control"
                           value="<?= e($typeApp) ?>"
                           placeholder="Ex: Cahier, Application, Comportement…"
                           onblur="this.form.submit()">
                    <div class="form-text small">Laisser vide pour tous les types.</div>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLEAU GLOBAL POUR TOUTE LA CLASSE -->
    <div class="card mb-3">
        <div class="card-header">
            Récapitulatif de la classe — Période & cours sélectionnés
            <?php if ($typeApp !== ''): ?>
                <span class="text-muted"> (Type : <?= e($typeApp) ?>)</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (!$classCotes): ?>
                <div class="p-3 text-muted">
                    Aucune appréciation enregistrée pour cette période / ce cours (et type, le cas échéant).
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Élève</th>
                                <th class="text-center">Nombre d’appréciations</th>
                                <th class="text-center">Total des points</th>
                                <th class="text-center">Moyenne</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($classCotes as $row): ?>
                            <?php
                              $nom = trim($row['nom'].' '.$row['postnom'].' '.$row['prenom']);
                              $nb  = (int)$row['nb_app'];
                              $tot = (float)$row['total_points'];
                              $moy = $row['moyenne_points'] !== null ? (float)$row['moyenne_points'] : null;
                            ?>
                            <tr>
                                <td><?= e($nom) ?></td>
                                <td class="text-center"><?= $nb ?></td>
                                <td class="text-center"><?= number_format($tot, 2, ',', ' ') ?></td>
                                <td class="text-center">
                                  <?= $moy !== null ? number_format($moy, 2, ',', ' ') : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FORMULAIRE : AJOUTER UNE NOUVELLE APPRÉCIATION -->
    <form method="post" class="card mb-3">
        <div class="card-header">Nouvelle appréciation pour l’élève sélectionné</div>

        <input type="hidden" name="periode_id" value="<?= (int)$periodeId ?>">
        <input type="hidden" name="cours_id" value="<?= (int)$coursId ?>">
        <input type="hidden" name="eleve_id" value="<?= (int)$eleveId ?>">
        <input type="hidden" name="type_app" value="<?= e($typeApp) ?>">

        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label">Élève</label>
                <select class="form-select" disabled>
                    <?php if ($eleveId === 0): ?>
                        <option>— Sélectionnez un élève dans les filtres ci-dessus —</option>
                    <?php else: ?>
                        <?php
                        $currentEleveName = '';
                        foreach ($eleves as $e) {
                            if ((int)$e['id'] === $eleveId) {
                                $currentEleveName = trim($e['nom'].' '.$e['postnom'].' '.$e['prenom']);
                                break;
                            }
                        }
                        ?>
                        <option><?= e($currentEleveName) ?></option>
                    <?php endif; ?>
                </select>
                <div class="form-text small">Changez d’élève via les filtres du haut.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Points (ex: 18 sur 20)</label>
                <input type="text" name="points"
                       class="form-control" <?= $eleveId===0?'disabled':'' ?>>
            </div>

            <div class="col-md-6">
                <label class="form-label">Remarque (appréciation)</label>
                <input type="text" name="remarque" class="form-control"
                       placeholder="Ex: Cahier bien tenu, bon suivi des consignes…"
                       <?= $eleveId===0?'disabled':'' ?>>
                <div class="form-text small">
                    Le type d’appréciation général utilisé : 
                    <strong><?= $typeApp !== '' ? e($typeApp) : 'non spécifié (tous)' ?></strong>
                </div>
            </div>
        </div>

        <div class="card-footer text-end">
            <button class="btn btn-primary" <?= $eleveId===0?'disabled':'' ?>>
                💾 Enregistrer
            </button>
            <?php if ($eleveId === 0): ?>
                <span class="text-muted ms-2 small">Sélectionnez un élève d’abord.</span>
            <?php endif; ?>
        </div>
    </form>

    <!-- TABLEAU DÉTAILLÉ POUR L'ÉLÈVE SÉLECTIONNÉ -->
    <div class="card mb-5">
        <div class="card-header">
            Détails des appréciations de l’élève sélectionné
            <?php if ($eleveId > 0): ?>
                <?php
                $nomEleve = '';
                foreach ($eleves as $e) {
                    if ((int)$e['id'] === $eleveId) {
                        $nomEleve = trim($e['nom'].' '.$e['postnom'].' '.$e['prenom']);
                        break;
                    }
                }
                ?>
                — <strong><?= e($nomEleve) ?></strong>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if ($eleveId === 0): ?>
                <div class="p-3 text-muted">Sélectionnez un élève dans les filtres pour voir l’historique détaillé.</div>
            <?php elseif (!$cotesEleve): ?>
                <div class="p-3 text-muted">Aucune appréciation enregistrée pour cet élève (avec les filtres actuels).</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Points</th>
                                <th>Remarque</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cotesEleve as $ct): ?>
                            <tr>
                                <td><?= e($ct['type_app']) ?></td>
                                <td><?= $ct['points'] !== null ? e($ct['points']) : '—' ?></td>
                                <td><?= nl2br(e($ct['remarque'])) ?></td>
                                <td><?= e($ct['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__.'/../layout/footer.php'; ?>
