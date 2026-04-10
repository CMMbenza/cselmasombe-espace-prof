<?php
// prof/quiz_list_quiz_soumis.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 1) Récup classes affectées (pour le sélecteur)
$myClasses = classes_of_agent($con, $agentId); // [id, description, cycle_desc]

// 2) Récup TOUS les cours de ces classes (pour le sélecteur "Cours")
$courses = [];
if ($myClasses) {
    $classIds = array_map(fn($c) => (int)$c['id'], $myClasses);
    $in = implode(',', array_fill(0, count($classIds), '?'));
    $types = str_repeat('i', count($classIds));

    $stmt = $con->prepare("SELECT id, intitule, classe_id FROM cours WHERE classe_id IN ($in) ORDER BY intitule");
    $stmt->bind_param($types, ...$classIds);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 3) Récup TOUTES les soumissions des quiz du prof (limité à 1000)
$stmt = $con->prepare("
SELECT 
    qs.id, qs.quiz_id, qs.eleve_id, qs.date_submitted, qs.note_totale, qs.statut,
    e.nom, e.postnom, e.prenom,
    q.titre, q.format, q.type_quiz,
    c.id AS classe_id, c.description AS classe_desc
FROM quiz_submission qs
JOIN eleve e          ON e.id = qs.eleve_id
JOIN quiz q           ON q.id = qs.quiz_id
JOIN quiz_classe qc   ON qc.quiz_id = q.id AND qc.classe_id = e.classe
JOIN classe c         ON c.id = qc.classe_id
WHERE q.agent_id = ?
ORDER BY qs.date_submitted DESC, qs.id DESC
LIMIT 1000
");
$stmt->bind_param('i', $agentId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Compteurs initiaux
$counts = ['total'=>0, 'remis'=>0, 'corrige'=>0];
$counts['total'] = count($rows);
foreach ($rows as $r) {
    if ($r['statut'] === 'corrige') $counts['corrige']++;
    else                             $counts['remis']++;
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>
<div class="container">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">Élèves — copies soumises (récentes)</h1>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/prof/quiz_list.php">← Mes quiz</a>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Classe</label>
                    <select id="fClasse" class="form-select">
                        <option value="">Toutes</option>
                        <?php foreach ($myClasses as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['description']) ?> — <?= e($c['cycle_desc']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Cours</label>
                    <select id="fCours" class="form-select">
                        <option value="">Tous</option>
                        <?php foreach ($courses as $cr): ?>
                        <option value="<?= e($cr['intitule']) ?>" data-classe="<?= (int)$cr['classe_id'] ?>">
                            <?= e($cr['intitule']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Le titre du quiz = nom du cours.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Statut</label>
                    <select id="fStatut" class="form-select">
                        <option value="">Tous</option>
                        <option value="remis">Remis (à corriger)</option>
                        <option value="corrige">Corrigé</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Recherche élève</label>
                    <input id="fSearch" type="search" class="form-control" placeholder="Nom, postnom, prénom">
                </div>
            </div>

            <div class="row g-2 mt-2">
                <div class="col-md-3">
                    <label class="form-label">Du</label>
                    <input id="fDateFrom" type="date" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Au</label>
                    <input id="fDateTo" type="date" class="form-control">
                </div>

                <div class="col-md-6 d-flex align-items-end">
                    <div class="ms-auto d-flex gap-2">
                        <span id="badgeTotal" class="badge text-bg-secondary">Total: <?= (int)$counts['total'] ?></span>
                        <span id="badgeRemis" class="badge text-bg-warning">Remis: <?= (int)$counts['remis'] ?></span>
                        <span id="badgeCorrige" class="badge text-bg-success">Corrigé:
                            <?= (int)$counts['corrige'] ?></span>
                        <button id="btnReset" class="btn btn-outline-secondary btn-sm"
                            type="button">Réinitialiser</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle" id="subTable">
                <thead>
                    <tr>
                        <th style="width:1%;">#</th>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Devoir (Cours)</th>
                        <th>Format</th>
                        <th>Date</th>
                        <th>Statut</th>
                        <th>Note</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="9"><em>Aucune soumission trouvée.</em></td>
                    </tr>
                    <?php else: foreach ($rows as $i=>$r):
                        $nom   = trim(($r['nom']??'').' '.($r['postnom']??'').' '.($r['prenom']??''));
                        $badge = $r['statut']==='corrige' ? 'success' : 'warning';
                        $dateRaw = (string)($r['date_submitted'] ?? '');
                        $dateKey = substr($dateRaw, 0, 10); // 'YYYY-MM-DD'
                        $format = $r['format'] ?? '';
                        ?>
                    <tr data-name="<?= e(mb_strtolower($nom)) ?>" data-status="<?= e($r['statut']) ?>"
                        data-class-id="<?= (int)$r['classe_id'] ?>"
                        data-course="<?= e(mb_strtolower($r['titre'] ?? '')) ?>" data-date="<?= e($dateKey) ?>">
                        <td><?= $i+1 ?></td>
                        <td><?= e($nom) ?></td>
                        <td><?= e($r['classe_desc'] ?? '—') ?></td>
                        <td><?= e($r['titre'] ?? '—') ?></td>
                        <td><?= e($format ?: '—') ?></td>
                        <td class="small text-muted"><?= e($dateRaw) ?></td>
                        <td><span class="badge text-bg-<?= $badge ?>"><?= e($r['statut']) ?></span></td>
                        <td><?= isset($r['note_totale']) ? e((string)$r['note_totale']) : '—' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                                href="/prof/submission_view.php?id=<?= (int)$r['id'] ?>">Voir</a>
                            <?php if (in_array($format, ['RQ','PJ'], true) && $r['statut'] === 'remis'): ?>
                            <a class="btn btn-sm btn-primary"
                                href="/prof/grade_submission.php?id=<?= (int)$r['id'] ?>">Corriger</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <div id="infoCount" class="text-muted small">Total affiché : <?= (int)$counts['total'] ?> soumission(s).
            </div>
        </div>
    </div>
</div>

<script>
// --- Filtrage côté client (inchangé) ---
const fClasse = document.getElementById('fClasse');
const fCours = document.getElementById('fCours');
const fStatut = document.getElementById('fStatut');
const fSearch = document.getElementById('fSearch');
const fDateFrom = document.getElementById('fDateFrom');
const fDateTo = document.getElementById('fDateTo');
const table = document.getElementById('subTable');
const infoCount = document.getElementById('infoCount');
const badgeTotal = document.getElementById('badgeTotal');
const badgeRemis = document.getElementById('badgeRemis');
const badgeCorrige = document.getElementById('badgeCorrige');
const btnReset = document.getElementById('btnReset');

function applyFilter() {
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');

    const qName = (fSearch?.value || '').trim().toLowerCase();
    const st = (fStatut?.value || '').trim().toLowerCase();
    const cid = (fClasse?.value || '').trim();
    const course = (fCours?.value || '').trim().toLowerCase();
    const dFrom = (fDateFrom?.value || '').trim();
    const dTo = (fDateTo?.value || '').trim();

    let shown = 0,
        remis = 0,
        corrige = 0;

    rows.forEach(tr => {
        const name = (tr.getAttribute('data-name') || '').toLowerCase();
        const status = (tr.getAttribute('data-status') || '').toLowerCase();
        const classId = (tr.getAttribute('data-class-id') || '').trim();
        const cour = (tr.getAttribute('data-course') || '').toLowerCase();
        const d = (tr.getAttribute('data-date') || '').trim();

        let ok = true;
        if (qName && !name.includes(qName)) ok = false;
        if (st && status !== st) ok = false;
        if (cid && classId !== cid) ok = false;
        if (course && cour !== course) ok = false;
        if (dFrom && (!d || d < dFrom)) ok = false;
        if (dTo && (!d || d > dTo)) ok = false;

        tr.style.display = ok ? '' : 'none';
        if (ok) {
            shown++;
            if (status === 'corrige') corrige++;
            else remis++;
        }
    });

    infoCount.textContent = `Total affiché : ${shown} soumission(s).`;
    if (badgeTotal) badgeTotal.textContent = `Total: ${shown}`;
    if (badgeRemis) badgeRemis.textContent = `Remis: ${remis}`;
    if (badgeCorrige) badgeCorrige.textContent = `Corrigé: ${corrige}`;
}

// Synchroniser cours / classe
const allCoursOptions = Array.from(fCours?.querySelectorAll('option') || []);

function syncCoursWithClasse() {
    if (!fCours) return;
    const cid = (fClasse?.value || '').trim();
    const keepFirst = allCoursOptions[0];
    const filtered = allCoursOptions.filter((opt, idx) => {
        if (idx === 0) return true;
        const ocid = (opt.getAttribute('data-classe') || '').trim();
        return !cid || ocid === cid;
    });
    fCours.innerHTML = '';
    filtered.forEach(opt => fCours.appendChild(opt.cloneNode(true)));
    fCours.selectedIndex = 0;
    applyFilter();
}
[fClasse]?.forEach(el => el.addEventListener('change', syncCoursWithClasse));

// Réinitialiser
btnReset?.addEventListener('click', () => {
    if (fClasse) fClasse.value = '';
    syncCoursWithClasse();
    if (fStatut) fStatut.value = '';
    if (fSearch) fSearch.value = '';
    if (fDateFrom) fDateFrom.value = '';
    if (fDateTo) fDateTo.value = '';
    applyFilter();
});

// Init
syncCoursWithClasse();
applyFilter();
</script>

<?php include __DIR__.'/layout/footer.php'; ?>