<?php
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
    echo '<div class="container mt-3"><div class="alert alert-info">Aucune classe sélectionnée.</div></div>';
    include __DIR__.'/../layout/footer.php';
    exit;
}

/* ================= ÉLÈVES ================= */
$eleves = [];
$stmt = $con->prepare("
    SELECT id, nom, postnom, prenom, genre
    FROM eleve
    WHERE classe = ?
    ORDER BY nom, postnom, prenom
");
$stmt->bind_param('i', $classeId);
$stmt->execute();
$eleves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$trimestres = ['T1', 'T2', 'T3'];

$msg = '';
$err = '';

if (isset($_GET['success'])) {
    $msg = "Enregistré avec succès.";
}

/* ================= EDIT ================= */

$editData = null;

if (isset($_GET['edit'])) {

    $editId = (int)$_GET['edit'];

    $stmt = $con->prepare("
        SELECT *
        FROM palmares_trimestre
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $editId);

    $stmt->execute();

    $editData = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/* ================= SAVE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $eleveId   = (int)($_POST['eleve_id'] ?? 0);
    $trimestre = trim($_POST['trimestre'] ?? '');

    $lang = (float)($_POST['lang'] ?? 0);
    $math = (float)($_POST['math'] ?? 0);
    $cult = (float)($_POST['cult'] ?? 0);

    /* MAX */
    $maxLang = (float)($_POST['max_lang'] ?? 20);
    $maxMath = (float)($_POST['max_math'] ?? 20);
    $maxCult = (float)($_POST['max_cult'] ?? 20);

    $maxTotal   = (float)($_POST['max_total'] ?? 60);
    $maxPercent = (float)($_POST['max_percent'] ?? 100);

    $obs = trim($_POST['obs'] ?? '');

    $autorise = (int)($_POST['autorise'] ?? 1);

    if ($eleveId <= 0 || $trimestre === '') {

        $err = "Données invalides.";

    } else {

        /* TOTAL */
        $total = $lang + $math + $cult;

        /* POURCENTAGE */
        $percent = $maxTotal > 0
            ? ($total * $maxPercent) / $maxTotal
            : 0;

        $editId = (int)($_POST['edit_id'] ?? 0);

    if ($editId > 0) {

    $stmt = $con->prepare("
        UPDATE palmares_trimestre SET

            eleve_id=?,
            classe_id=?,
            trimestre=?,

            lang=?,
            math=?,
            cult=?,

            max_lang=?,
            max_math=?,
            max_cult=?,

            max_total=?,
            max_percent=?,

            total=?,
            percent=?,

            obs=?,
            autorise=?

        WHERE id=?
    ");

    $stmt->bind_param(

        'iisddddddddddsii',

        $eleveId,
        $classeId,
        $trimestre,

        $lang,
        $math,
        $cult,

        $maxLang,
        $maxMath,
        $maxCult,

        $maxTotal,
        $maxPercent,

        $total,
        $percent,

        $obs,
        $autorise,

        $editId
    );

} else {

    $stmt = $con->prepare("
        INSERT INTO palmares_trimestre (

            eleve_id,
            classe_id,
            trimestre,

            lang,
            math,
            cult,

            max_lang,
            max_math,
            max_cult,

            max_total,
            max_percent,

            total,
            percent,

            obs,
            autorise,

            created_at

        )
        VALUES (

            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            NOW()

        )
    ");

    $stmt->bind_param(

        'iisddddddddddsi',

        $eleveId,
        $classeId,
        $trimestre,

        $lang,
        $math,
        $cult,

        $maxLang,
        $maxMath,
        $maxCult,

        $maxTotal,
        $maxPercent,

        $total,
        $percent,

        $obs,
        $autorise
    );
}

            if ($stmt->execute()) {

        header("Location: palmares_trimestre.php?success=1");
        exit;

    } else {

        $err = $stmt->error;
    }

        $stmt->close();
    }
}

/* ================= DELETE ================= */
if (isset($_GET['delete'])) {

    $deleteId = (int)$_GET['delete'];

    $stmt = $con->prepare("
        DELETE FROM palmares_trimestre
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $deleteId);

    if ($stmt->execute()) {

        header("Location: palmares_trimestre.php?deleted=1");
        exit;

    }

    $stmt->close();
}

/* ================= HISTORIQUE ================= */
$search = trim($_GET['search'] ?? '');
$filterTrim = trim($_GET['filter_trim'] ?? '');

/* ================= HISTORIQUE ================= */

$rows = [];

$where = ["p.classe_id = ?"];
$params = [$classeId];
$types = 'i';

/* ===== Recherche élève ===== */
if ($search !== '') {

    $where[] = "
        (
            e.nom LIKE ?
            OR e.postnom LIKE ?
            OR e.prenom LIKE ?
        )
    ";

    $like = "%{$search}%";

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'sss';
}

/* ===== Filtre trimestre ===== */
if ($filterTrim !== '') {

    $where[] = "p.trimestre = ?";

    $params[] = $filterTrim;

    $types .= 's';
}

/* ===== SQL ===== */
$sql = "
SELECT
    p.*,
    e.nom,
    e.postnom,
    e.prenom
FROM palmares_trimestre p
INNER JOIN eleve e
    ON e.id = p.eleve_id
WHERE ".implode(' AND ', $where)."
ORDER BY
    p.percent DESC,
    p.created_at DESC
";

$stmt = $con->prepare($sql);

if ($stmt) {

    $stmt->bind_param($types, ...$params);

    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();
}

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';

function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>

<div class="container mt-3">

    <h5>Palmarès trimestre</h5>

    <?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

    <!-- FORM -->
    <div class="card mb-3">
        <div class="card-header">
            Saisi des données (Palmarès)
        </div>
        <div class="card-body">

            <form method="post">

                <!-- ID UPDATE -->
                <input type="hidden" name="edit_id" value="<?= (int)($editData['id'] ?? 0) ?>">

                <!-- AUTORISE -->
                <input type="hidden" name="autorise" value="0">

                <!-- ===================== -->
                <!-- ELEVE + TRIMESTRE -->
                <!-- ===================== -->
                <div class="row mb-3">

                    <!-- ELEVE -->
                    <div class="col-md-6">

                        <label class="form-label">
                            Élève
                        </label>

                        <select name="eleve_id" class="form-select" required>

                            <option value="">
                                --
                            </option>

                            <?php foreach ($eleves as $el):

                    $nom = trim(
                        $el['nom'].' '.
                        $el['postnom'].' '.
                        $el['prenom']
                    );

                    $selected =
                        ((int)($editData['eleve_id'] ?? 0) === (int)$el['id'])
                        ? 'selected'
                        : '';
                ?>

                            <option value="<?= (int)$el['id'] ?>" <?= $selected ?>>
                                <?= e($nom) ?>
                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- TRIMESTRE -->
                    <div class="col-md-6">

                        <label class="form-label">
                            Trimestre
                        </label>

                        <select name="trimestre" class="form-select" required>

                            <?php foreach ($trimestres as $t): ?>

                            <option value="<?= e($t) ?>"
                                <?= ($editData['trimestre'] ?? 'T1') === $t ? 'selected' : '' ?>>

                                <?= e($t) ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <!-- ===================== -->
                <!-- MAXIMA -->
                <!-- ===================== -->
                <div class="row mb-3">

                    <div class="col-md-2">

                        <label class="form-label">
                            MAX. LANG
                        </label>

                        <input type="number" step="0.01" name="max_lang" value="<?= e($editData['max_lang'] ?? 20) ?>"
                            class="form-control" <?= $editData ? 'readonly' : '' ?>>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label">
                            MAX. MATH
                        </label>

                        <input type="number" step="0.01" name="max_math" value="<?= e($editData['max_math'] ?? 20) ?>"
                            class="form-control" <?= $editData ? 'readonly' : '' ?>>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label">
                            MAX. CULT
                        </label>

                        <input type="number" step="0.01" name="max_cult" value="<?= e($editData['max_cult'] ?? 20) ?>"
                            class="form-control" <?= $editData ? 'readonly' : '' ?>>

                    </div>

                    <div class="col-md-2">

                        <label class="form-label">
                            MAX. TOTAL
                        </label>

                        <input type="number" step="0.01" name="max_total" id="max_total"
                            value="<?= e($editData['max_total'] ?? 60) ?>" readonly
                            class="form-control bg-light fw-bold">

                    </div>

                    <div class="col-md-2">

                        <label class="form-label">
                            MAX. %
                        </label>

                        <input type="number" step="0.01" name="max_percent"
                            value="<?= e($editData['max_percent'] ?? 100) ?>" readonly
                            class="form-control bg-light fw-bold">

                    </div>

                </div>

                <!-- ===================== -->
                <!-- NOTES -->
                <!-- ===================== -->
                <div class="row g-2">

                    <!-- LANG -->
                    <div class="col-md-2">

                        <label class="form-label">
                            LANG
                        </label>

                        <input type="number" step="0.01" name="lang" value="<?= e($editData['lang'] ?? '') ?>"
                            class="form-control">

                    </div>

                    <!-- MATH -->
                    <div class="col-md-2">

                        <label class="form-label">
                            MATH
                        </label>

                        <input type="number" step="0.01" name="math" value="<?= e($editData['math'] ?? '') ?>"
                            class="form-control">

                    </div>

                    <!-- CULT -->
                    <div class="col-md-2">

                        <label class="form-label">
                            CULT
                        </label>

                        <input type="number" step="0.01" name="cult" value="<?= e($editData['cult'] ?? '') ?>"
                            class="form-control">

                    </div>

                    <!-- TOTAL -->
                    <div class="col-md-2">

                        <label class="form-label">
                            TOTAL
                        </label>

                        <input type="text" id="total" value="<?= e($editData['total'] ?? '') ?>" readonly
                            class="form-control bg-light fw-bold">

                    </div>

                    <!-- POURCENTAGE -->
                    <div class="col-md-2">

                        <label class="form-label">
                            Pourcentage %
                        </label>

                        <input type="text" id="pourcentage" value="<?= e($editData['percent'] ?? '') ?>" readonly
                            class="form-control bg-light fw-bold">

                    </div>

                </div>

                <!-- ===================== -->
                <!-- OBS -->
                <!-- ===================== -->
                <div class="row mt-3">

                    <div class="col-md-12">

                        <label class="form-label">
                            OBS :
                        </label>

                        <textarea name="obs" class="form-control" cols="30" rows="3"
                            placeholder="Laissez un message(observation) sur l'application de l'enfant."><?= e($editData['obs'] ?? '') ?></textarea>

                    </div>

                </div>

                <!-- ===================== -->
                <!-- BUTTONS -->
                <!-- ===================== -->
                <div class="text-end mt-4">

                    <?php if ($editData): ?>

                    <button class="btn btn-warning">

                        Modifier

                    </button>

                    <a href="palmares_trimestre.php" class="btn btn-secondary">

                        Annuler

                    </a>

                    <?php else: ?>

                    <button class="btn btn-primary">

                        Valider la côte

                    </button>

                    <?php endif; ?>

                </div>

            </form>

        </div>
    </div>

    <!-- TABLE -->
    <div class="card mb-3">

        <div class="card-header">
            Filtres & Export
        </div>

        <div class="card-body">

            <form method="get" class="row g-2">

                <!-- RECHERCHE -->
                <div class="col-md-4">
                    <label class="form-label">
                        Recherche élève
                    </label>

                    <input type="text" name="search" value="<?= e($search) ?>" class="form-control"
                        <?= $editData ? 'readonly' : '' ?> placeholder="Nom élève...">
                </div>

                <!-- TRIMESTRE -->
                <div class="col-md-3">
                    <label class="form-label">
                        Trimestre
                    </label>

                    <select name="filter_trim" class="form-select">

                        <option value="">
                            Tous
                        </option>

                        <?php foreach ($trimestres as $t): ?>

                        <option value="<?= e($t) ?>" <?= $filterTrim === $t ? 'selected' : '' ?>>
                            <?= e($t) ?>
                        </option>

                        <?php endforeach; ?>

                    </select>
                </div>

                <!-- BTN FILTRE -->
                <div class="col-md-2 d-flex align-items-end">

                    <button class="btn btn-dark w-100">
                        Filtrer
                    </button>

                </div>

                <!-- BTN EXPORT -->
                <div class="col-md-3 d-flex align-items-end">

                    <a href="export_palmares.php?search=<?= urlencode($search) ?>&filter_trim=<?= urlencode($filterTrim) ?>"
                        class="btn btn-success w-100">
                        Export Excel
                    </a>

                </div>

            </form>

        </div>
    </div>

    <!-- ================= HISTORIQUE ================= -->
    <div class="card">

        <div class="card-header d-flex justify-content-between align-items-center">

            <span>
                Historique des palmarès
            </span>

            <span class="badge bg-primary">
                <?= count($rows) ?> résultat(s)
            </span>

        </div>

        <div class="card-body table-responsive">

            <table class="table table-bordered table-hover align-middle text-center">

                <thead class="table-light">

                    <tr>

                        <th>#</th>
                        <th>Élève</th>
                        <th>Trim.</th>

                        <th>LANG</th>
                        <th>MATH</th>
                        <th>CULT</th>

                        <th>Total</th>
                        <th>%</th>

                        <th>OBS</th>

                        <th></th>

                    </tr>

                </thead>

                <tbody>

                    <tr class="table-warning fw-bold">

                        <td>#</td>

                        <td class="text-start">
                            Pondération
                        </td>

                        <td>—</td>

                        <td>
                            /<?= e($rows[0]['max_lang'] ?? 0) ?>
                        </td>

                        <td>
                            /<?= e($rows[0]['max_math'] ?? 0) ?>
                        </td>

                        <td>
                            /<?= e($rows[0]['max_cult'] ?? 0) ?>
                        </td>

                        <td>
                            /<?= e($rows[0]['max_total'] ?? 0) ?>
                        </td>

                        <td>
                            /<?= e($rows[0]['max_percent'] ?? 100) ?>
                        </td>

                        <td colspan="2">
                            Maxima
                        </td>

                    </tr>

                    <?php if (!$rows): ?>

                    <tr>
                        <td colspan="10" class="text-muted py-4">
                            Aucun résultat trouvé.
                        </td>
                    </tr>

                    <?php else: ?>

                    <?php
                $rang = 1;

                foreach ($rows as $r):

                    $nom = trim(
                        $r['nom'].' '.
                        $r['postnom'].' '.
                        $r['prenom']
                    );
                ?>

                    <tr>

                        <!-- RANG -->
                        <td class="fw-bold">
                            <?= $rang++ ?>
                        </td>

                        <!-- NOM -->
                        <td class="text-start">
                            <?= e($nom) ?>
                        </td>

                        <!-- TRIM -->
                        <td>
                            <span class="badge bg-dark">
                                <?= e($r['trimestre']) ?>
                            </span>
                        </td>

                        <!-- NOTES -->
                        <td><?= number_format((float)$r['lang'], 2) ?></td>

                        <td><?= number_format((float)$r['math'], 2) ?></td>

                        <td><?= number_format((float)$r['cult'], 2) ?></td>

                        <!-- TOTAL -->
                        <td class="fw-bold text-primary">
                            <?= number_format((float)$r['total'], 2) ?>
                        </td>

                        <!-- % -->
                        <td>

                            <?php
                        $percent = (float)$r['percent'];

                        $badge = 'bg-danger';

                        if ($percent >= 80) {
                            $badge = 'bg-success';
                        }
                        elseif ($percent >= 60) {
                            $badge = 'bg-primary';
                        }
                        elseif ($percent >= 50) {
                            $badge = 'bg-warning text-dark';
                        }
                        ?>

                            <span class="badge <?= $badge ?>">
                                <?= number_format($percent, 2) ?> %
                            </span>

                        </td>

                        <!-- OBS -->
                        <td>
                            <?= e($r['obs']) ?>
                        </td>

                        <td>

                            <!-- UPDATE -->
                            <a href="palmares_trimestre.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                Modifier
                            </a>

                            <!-- DELETE -->
                            <a href="palmares_trimestre.php?delete=<?= (int)$r['id'] ?>" class="btn btn-sm btn-danger"
                                onclick="return confirm('Supprimer cet enregistrement ?')">
                                Supprimer
                            </a>

                        </td>

                    </tr>

                    <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>
</div>

</div>
<script>
function calculer() {

    /* NOTES */
    let lang = parseFloat(document.querySelector('[name="lang"]').value) || 0;
    let math = parseFloat(document.querySelector('[name="math"]').value) || 0;
    let cult = parseFloat(document.querySelector('[name="cult"]').value) || 0;

    /* MAXIMA */
    let maxLang = parseFloat(document.querySelector('[name="max_lang"]').value) || 0;
    let maxMath = parseFloat(document.querySelector('[name="max_math"]').value) || 0;
    let maxCult = parseFloat(document.querySelector('[name="max_cult"]').value) || 0;

    let maxPercent = parseFloat(document.querySelector('[name="max_percent"]').value) || 100;

    /* =========================
       CALCUL MAX TOTAL
    ========================= */
    let maxTotal =
        maxLang +
        maxMath +
        maxCult;

    /* =========================
       CALCUL TOTAL ELEVE
    ========================= */
    let total =
        lang +
        math +
        cult;

    /* =========================
       CALCUL POURCENTAGE
    ========================= */
    let percent = 0;

    if (maxTotal > 0) {

        percent =
            (total * maxPercent) / maxTotal;
    }

    /* =========================
       AFFICHAGE
    ========================= */

    document.getElementById('max_total').value =
        maxTotal.toFixed(2);

    document.getElementById('total').value =
        total.toFixed(2);

    document.getElementById('pourcentage').value =
        percent.toFixed(2);
}

/* EVENTS */
document.querySelectorAll('input').forEach(el => {

    el.addEventListener('input', calculer);

});

/* AUTO LOAD */
calculer();
</script>

<?php include __DIR__.'/../layout/footer.php'; ?>