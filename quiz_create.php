<?php
// /prof/quiz_create.php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];

// Classe courante éventuellement choisie (pour pré-sélection)
$classeId = get_current_classe();

// 1) Récupère les classes affectées au prof
$classes = classes_of_agent($con, $agentId); // id, description, cycle_desc...

$coursByClasse   = [];
$periodeByClasse = [];

if ($classes) {
    $classIds = array_map(fn($c) => (int)$c['id'], $classes);
    if ($classIds) {
        $in  = implode(',', array_fill(0, count($classIds), '?'));
        $typ = str_repeat('i', count($classIds));

        // 2) Précharger les cours enseignés par ce prof dans ces classes
        $sqlCours = "
          SELECT co.id, co.intitule, co.classe_id
          FROM cours co
          INNER JOIN affectation_prof_classe apc
            ON apc.cours_id = co.id
           AND apc.agent_id = ?
          WHERE co.classe_id IN ($in)
          ORDER BY co.intitule
        ";
        $stmt = $con->prepare($sqlCours);
        $bindTypes = 'i' . $typ;
        $params = array_merge([$agentId], $classIds);
        $stmt->bind_param($bindTypes, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $cid = (int)$row['classe_id'];
            $coursByClasse[$cid][] = [
                'id'       => (int)$row['id'],
                'intitule' => (string)$row['intitule'],
            ];
        }
        $stmt->close();

        // 3) Précharger la période ACTIVE par classe (via le cycle)
        $sqlPer = "
          SELECT 
            c.id       AS classe_id,
            p.id       AS periode_id,
            p.CODE     AS code,
            p.libelle  AS libelle
          FROM classe c
          JOIN periodes p 
            ON p.cycle_id = c.cycle
           AND p.actif = 1
          WHERE c.id IN ($in)
        ";
        $stmt = $con->prepare($sqlPer);
        $stmt->bind_param($typ, ...$classIds);
        $stmt->execute();
        $resP = $stmt->get_result();
        while ($row = $resP->fetch_assoc()) {
            $cid = (int)$row['classe_id'];
            $periodeByClasse[$cid] = [
                'id'      => (int)$row['periode_id'],
                'code'    => (string)$row['code'],
                'libelle' => (string)$row['libelle'],
            ];
        }
        $stmt->close();
    }
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>
<style>
.autosave {
    font-weight: 500;
    animation: blink 1s infinite;
}

@keyframes blink {
    0% {
        opacity: 1;
    }

    50% {
        opacity: 0.3;
    }

    100% {
        opacity: 1;
    }
}
</style>
<script>
// en cours
autosaveStatus.innerHTML = "⏳ Sauvegarde automatique...";
autosaveStatus.className = "autosave text-danger";

// succès
autosaveStatus.innerHTML = "✔ Sauvegardé";
autosaveStatus.className = "text-success";

// erreur
autosaveStatus.innerHTML = "❌ Erreur de sauvegarde";
autosaveStatus.className = "text-danger";
</script>
<div class="container">
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Créer un quiz</h2>

                <small id="autosaveStatus" class="autosave text-danger">
                    ⏳ Sauvegarde automatique...
                </small>
            </div>

            <?php if (!$classes): ?>
            <div class="alert alert-warning">Aucune classe affectée.</div>
            <?php else: ?>
            <form action="/prof/quiz_store.php" method="post" enctype="multipart/form-data" id="formQuiz">
                <div class="row g-3">
                    <input type="hidden" name="quiz_id" id="quiz_id" value="">
                    <!-- CLASSE -->
                    <div class="col-md-4">
                        <label class="form-label">Classe</label>
                        <select name="classe_ids[]" id="classe_ids" class="form-select" multiple required size="5">
                            <option value="">—</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>">
                                <?= e($c['description']) ?>
                                <?= !empty($c['cycle_desc']) ? ' — '.e($c['cycle_desc']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Maintenez Ctrl (Windows) ou Cmd (Mac) pour sélectionner plusieurs classes.
                        </div>

                    </div>

                    <!-- COURS -->
                    <div class="col-md-4">
                        <label class="form-label">Cours (Titre)</label>
                        <select name="cours_id" id="cours_id" class="form-select" required>
                            <option value="">— Sélectionnez d’abord la classe —</option>
                        </select>
                        <div class="form-text">Le titre du quiz sera l’intitulé du cours choisi.</div>
                    </div>

                    <!-- PERIODE ACTIVE -->
                    <div class="col-md-4">
                        <label class="form-label">Période active</label>
                        <input type="text" id="periode_label" class="form-control" value="" readonly>
                        <input type="hidden" name="periode_id" id="periode_id" value="">
                        <div class="form-text">Période définie par la Direction pour le cycle.</div>
                    </div>

                    <!-- TYPE D'EVALUATION -->
                    <div class="col-md-4">
                        <label class="form-label">Type d’évaluation</label>
                        <select name="type_quiz" class="form-select" required>
                            <option value="Exercice">Exercice</option>
                            <option value="Devoir">Devoir</option>
                            <option value="Interrogation">Interrogation</option>
                            <option value="Examen">Examen</option>
                        </select>
                    </div>

                    <!-- FORMAT -->
                    <div class="col-md-4">
                        <label class="form-label">Format</label>
                        <select name="format" id="format" class="form-select" required>
                            <option value="QCM">QCM</option>
                            <option value="RQ">Réponse Libre (RQ)</option>
                            <option value="PJ">Pièce jointe (PJ)</option>
                        </select>
                    </div>

                    <!-- DATE LIMITE -->
                    <div class="col-md-4">
                        <label class="form-label">Date limite</label>
                        <input type="date" name="date_limite" class="form-control">
                        <div class="form-text">Programmez un devoir en fixant une date limite.</div>
                    </div>

                    <!-- DESCRIPTION -->
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"
                            placeholder="Consignes, barème, etc."></textarea>
                    </div>

                    <!-- Zone QCM/RQ -->
                    <div class="col-12" id="zone-questions">
                        <h6 class="mb-2">Questions</h6>
                        <div id="questions"></div>
                        <div class="d-grid mt-3">
                            <button type="button" class="btn btn-outline-primary" id="btnAddQuestion">
                                + Ajouter une question
                            </button>
                        </div>
                        <div class="form-text mt-2">
                            Pour QCM : ajoutez des choix et cochez le(s) correct(s). Vous pouvez supprimer une question.
                        </div>
                    </div>

                    <!-- Zone PJ (toujours visible maintenant) -->
                    <div class="col-12" id="zone-pj">
                        <label class="form-label">Pièces jointes (optionnel)</label>
                        <input type="file" name="attachments[]" id="attachments" class="form-control" multiple
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.mp4,.mp3,.ppt,.pptx,.xls,.xlsx">

                        <div class="form-text">
                            Vous pouvez joindre des fichiers même pour QCM ou RQ.
                        </div>

                        <!-- Preview -->
                        <div id="filePreview" class="mt-3"></div>
                    </div>

                    <div class="col-12 mt-3">

                        <div class="alert alert-primary br-none text-left mb-4">
                            1. Pendant que vous remplissez le quiz, vos réponses sont
                            automatiquement enregistrées en tant que brouillon. Lorsque vous cliquez sur le bouton, le
                            quiz est soumis et envoyé au directeur pour validation. <br>
                            2. Les fichiers uploadés ne sont pas enregistrés comme brouillon. <br>
                            3. N’oubliez pas de vous assurer que tous les champs nécessaires sont bien remplis.
                        </div>
                        <input type="hidden" name="statut" id="statut" value="brouillon">

                        <div class="d-flex"><button type="button" id="btnPublish" class="btn btn-success me-2">
                                <i class="bi bi-send"></i> Publier maintenant votre quiz
                            </button>
                            <button type="button" id="btnDraft" class="btn btn-danger">
                                Enregistrer comme brouillon
                            </button>
                        </div>

                    </div>

                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ===================== Données préchargées =====================
const COURS_BY_CLASSE = <?= json_encode($coursByClasse,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const PERIODE_BY_CLASSE = <?= json_encode($periodeByClasse, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

// ===================== Sélecteurs =====================
const formatSel = document.getElementById('format');
const zoneQ = document.getElementById('zone-questions');
const zonePJ = document.getElementById('zone-pj');
const questionsEl = document.getElementById('questions');
const btnAdd = document.getElementById('btnAddQuestion');
const classeSel = document.getElementById('classe_ids');
const coursSel = document.getElementById('cours_id');
const periodeLabel = document.getElementById('periode_label');
const periodeIdInp = document.getElementById('periode_id');

// ===================== Fonctions pour RQ / QCM =====================
function rqBlock(i, expected = '', keywords = '', similarity = 100) {
    return `
    <label class="form-label">Réponse attendue (facultatif)</label>
    <textarea name="q[${i}][expected]" class="form-control" rows="2">${expected}</textarea>

    <label class="form-label mt-2">Mots-clés (facultatif, séparés par des virgules)</label>
    <input type="text" name="q[${i}][keywords]" class="form-control"
           placeholder="ex: machine, programme, données" value="${keywords}">

    <label class="form-label mt-2">Seuil de similarité (%)</label>
    <input type="number" name="q[${i}][similarity_min]" class="form-control"
           value="${similarity}" min="0" max="100">
    `;
}


function qcmBlock(i) {
    return `
    <div class="mt-2" id="choices-${i}"></div>
    <div class="mt-2">
      <button type="button" class="btn btn-sm btn-success" onclick="addChoice(${i})">
        + Ajouter une assertion
      </button>
    </div>
  `;
}

window.renumberChoices = function(qidx) {
    const holder = document.getElementById('choices-' + qidx);
    if (!holder) return;
    holder.querySelectorAll('.input-group').forEach((g, i) => {
        const cb = g.querySelector('input[type="checkbox"]');
        if (cb) cb.value = String(i);
    });
};

window.addChoice = function(qidx, defaultText = '', checked = false) {
    const holder = document.getElementById('choices-' + qidx);
    if (!holder) return;
    const choiceIndex = holder.querySelectorAll('.input-group').length;

    const c = document.createElement('div');
    c.className = 'input-group mb-2';
    c.innerHTML = `
    <div class="input-group-text">
      <input class="form-check-input mt-0" type="checkbox"
             name="q[${qidx}][correct][]" value="${choiceIndex}" ${checked ? 'checked' : ''}>
    </div>
    <input type="text" class="form-control" name="q[${qidx}][choice][]"
           placeholder="Proposition..." value="${defaultText.replace(/"/g,'&quot;')}" required>
    <button class="btn btn-danger" type="button"
            onclick="this.parentElement.remove(); renumberChoices(${qidx});">×</button>
  `;
    holder.appendChild(c);
};

window.toggleType = function(i, val) {
    const holder = document.getElementById('qblock-' + i);
    if (!holder) return;

    if (val === 'RQ') {
        holder.innerHTML = rqBlock(i); // version enrichie avec mots-clés et seuil
    } else {
        holder.innerHTML = qcmBlock(i);
        addChoice(i, 'Choix 1', true);
        addChoice(i, 'Choix 2', false);
    }
};

window.qIndex = 0;

function clearQuestions() {
    questionsEl.innerHTML = '';
    window.qIndex = 0;
}

window.addQuestion = function(forceType = null) {
    const fmt = (forceType ? (forceType === 'RQ' ? 'RQ' : 'QCM') :
        (formatSel.value === 'RQ' ? 'RQ' : 'QCM'));
    const idx = window.qIndex++;

    const wrap = document.createElement('div');
    wrap.className = 'border rounded p-3 mb-3 position-relative';

    wrap.innerHTML = `
    <button type="button" class="btn btn-sm btn-danger position-absolute"
            style="top:.5rem; right:.5rem"
            onclick="this.closest('.border').remove()">Supprimer</button>

    <div class="mb-2">
      <label class="form-label">Question</label>
      <textarea name="q[${idx}][text]" class="form-control" rows="2" required></textarea>
    </div>
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Points</label>
        <input type="number" step="0.5" min="0" name="q[${idx}][points]"
               class="form-control" value="1">
      </div>
      <div class="col-md-4">
        <label class="form-label">Type</label>
        <select class="form-select" name="q[${idx}][type]" onchange="toggleType(${idx}, this.value)">
          <option value="QCM" ${fmt==='QCM'?'selected':''}>QCM</option>
          <option value="RQ"  ${fmt==='RQ'?'selected':''}>RQ</option>
        </select>
      </div>
    </div>
    <div class="mt-3" data-choices id="qblock-${idx}">
      ${fmt==='RQ' ? rqBlock(idx) : qcmBlock(idx)}
    </div>
  `;

    questionsEl.appendChild(wrap);

    if (fmt !== 'RQ') {
        addChoice(idx, 'Choix 1', true);
        addChoice(idx, 'Choix 2', false);
    }
};

// ===================== Période active selon la classe =====================
function updatePeriode() {
    const selected = Array.from(classeSel.selectedOptions).map(o => parseInt(o.value));

    if (!selected.length) {
        periodeLabel.value = '';
        periodeIdInp.value = '';
        return;
    }

    // On prend la période de la première classe sélectionnée
    const first = selected[0];
    const p = PERIODE_BY_CLASSE[first];

    if (p) {
        periodeLabel.value = p.code + ' — ' + p.libelle;
        periodeIdInp.value = p.id;
    } else {
        periodeLabel.value = 'Aucune période active';
        periodeIdInp.value = '';
    }
}

// ===================== Cours selon la classe =====================
function populateCours() {
    const selected = Array.from(classeSel.selectedOptions).map(o => parseInt(o.value));
    const cid = selected[0] || 0;

    coursSel.innerHTML = '';

    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = cid ? '— Sélectionner le cours —' : '— Sélectionnez au moins une classe —';
    coursSel.appendChild(opt0);

    if (!cid || !COURS_BY_CLASSE[cid]) return;

    COURS_BY_CLASSE[cid].forEach(c => {
        const o = document.createElement('option');
        o.value = String(c.id);
        o.textContent = c.intitule;
        coursSel.appendChild(o);
    });
}

// ===================== Format QCM/RQ/PJ =====================
function updateZones(reset = false) {
    const fmt = formatSel.value;

    if (fmt === 'PJ') {
        zoneQ.classList.add('d-none');
        if (reset) clearQuestions();
    } else {
        zoneQ.classList.remove('d-none');
        if (reset) {
            clearQuestions();
            addQuestion(fmt);
        }
    }
}

// ===================== Attachements & init =====================
if (classeSel) {
    classeSel.addEventListener('change', () => {
        populateCours();
        updatePeriode();
    });
    // init selon classe déjà sélectionnée
    populateCours();
    updatePeriode();
}

formatSel?.addEventListener('change', () => updateZones(true));
updateZones(true);

if (btnAdd) {
    btnAdd.addEventListener('click', () => addQuestion());
} else {
    document.addEventListener('click', (e) => {
        const t = e.target;
        if (t && t.id === 'btnAddQuestion') {
            e.preventDefault();
            addQuestion();
        }
    });
}

/* ============================
   PREVIEW DES FICHIERS
============================ */

const attachmentInput = document.getElementById('attachments');
const previewContainer = document.getElementById('filePreview');

attachmentInput?.addEventListener('change', function() {

    previewContainer.innerHTML = '';

    Array.from(this.files).forEach(file => {

        const fileType = file.type;
        const reader = new FileReader();
        const wrapper = document.createElement('div');
        wrapper.className = "mb-3 border rounded p-2";

        if (fileType.startsWith('image/')) {

            reader.onload = function(e) {
                wrapper.innerHTML = `
                    <strong>${file.name}</strong><br>
                    <img src="${e.target.result}"
                         class="img-fluid mt-2"
                         style="max-height:200px;">
                `;
            };
            reader.readAsDataURL(file);

        } else if (fileType === 'application/pdf') {

            reader.onload = function(e) {
                wrapper.innerHTML = `
                    <strong>${file.name}</strong>
                    <embed src="${e.target.result}"
                           type="application/pdf"
                           width="100%"
                           height="300px"
                           class="mt-2"/>
                `;
            };
            reader.readAsDataURL(file);

        } else if (fileType.startsWith('video/')) {

            reader.onload = function(e) {
                wrapper.innerHTML = `
                    <strong>${file.name}</strong><br>
                    <video controls width="100%" class="mt-2">
                        <source src="${e.target.result}" type="${fileType}">
                    </video>
                `;
            };
            reader.readAsDataURL(file);

        } else if (fileType.startsWith('audio/')) {

            reader.onload = function(e) {
                wrapper.innerHTML = `
                    <strong>${file.name}</strong><br>
                    <audio controls class="mt-2">
                        <source src="${e.target.result}" type="${fileType}">
                    </audio>
                `;
            };
            reader.readAsDataURL(file);

        } else {

            wrapper.innerHTML = `
                📎 <strong>${file.name}</strong>
                <div class="text-muted small">
                    Type: ${fileType || 'Inconnu'} <br>
                    Taille: ${(file.size / 1024).toFixed(2)} KB
                </div>
            `;
        }

        previewContainer.appendChild(wrapper);
    });
});

let quizId = document.getElementById('quiz_id');
let saving = false;

function collectQuestions() {
    return window.qIndex !== undefined ? document.getElementById('formQuiz') : null;
}

function validateForm() {

    const form = document.getElementById('formQuiz');

    // ✅ classes obligatoires (multiple)
    const classes = document.getElementById('classe_ids');
    if (!classes || classes.selectedOptions.length === 0) {
        alert("❌ Sélectionnez au moins une classe");
        return false;
    }

    // ✅ cours obligatoire
    const cours = form.querySelector('[name="cours_id"]');
    if (!cours || !cours.value) {
        alert("❌ Sélectionnez un cours");
        return false;
    }

    // ✅ période
    const periode = document.getElementById('periode_id');
    if (!periode.value) {
        alert("❌ Période invalide");
        return false;
    }

    // ✅ type évaluation
    const type = form.querySelector('[name="type_quiz"]');
    if (!type.value) {
        alert("❌ Type d’évaluation requis");
        return false;
    }

    // ✅ format
    const format = form.querySelector('[name="format"]');
    if (!format.value) {
        alert("❌ Format requis");
        return false;
    }

    // ✅ description
    const desc = form.querySelector('[name="description"]');
    if (!desc.value.trim()) {
        alert("❌ Description obligatoire");
        return false;
    }

    // ✅ questions sauf PJ
    if (format.value !== 'PJ') {

        const questions = document.querySelectorAll('[name^="q["][name$="[text]"]');

        if (questions.length === 0) {
            alert("❌ Ajoutez au moins une question");
            return false;
        }

        for (let q of questions) {
            if (!q.value.trim()) {
                alert("❌ Une question est vide");
                return false;
            }
        }
    }

    return true;
}

document.getElementById('btnDraft')?.addEventListener('click', async function() {

    if (!validateForm()) return;

    const form = document.getElementById('formQuiz');

    // 🔥 dernière sauvegarde auto
    saving = false;
    await autoSaveAll();

    const quizId = document.getElementById('quiz_id').value;

    if (!quizId) {
        alert("⏳ Sauvegarde en cours...");
        return;
    }

    // ✅ redirection directe
    window.location.href = "/prof/quiz_view.php?id=" + quizId;

});

// async function autoSaveAll() {

//     if (saving) return;
//     saving = true;

//     const form = document.getElementById('formQuiz');
//     const formData = new FormData(form);

//     // 1️⃣ quiz
//     const r1 = await fetch('/prof/quiz_autosave.php', {
//         method: 'POST',
//         body: formData
//     });
//     const d1 = await r1.json();

//     if (d1.quiz_id) {
//         quizId.value = d1.quiz_id;
//         formData.set('quiz_id', d1.quiz_id);
//     }

//     // 2️⃣ questions
//     await fetch('/prof/quiz_questions_autosave.php', {
//         method: 'POST',
//         body: formData
//     });

//     saving = false;
// }

// 🔥 timer global
// setInterval(autoSaveAll, 3000);

// 🚫 Désactiver soumission manuelle (sécurité)
document.getElementById('formQuiz')?.addEventListener('submit', function(e) {
    e.preventDefault();
});

document.getElementById('btnPublish')?.addEventListener('click', async function() {

    if (!validateForm()) return;

    const form = document.getElementById('formQuiz');

    // 🔥 permettre autosave de fonctionner
    saving = false;

    // 🔥 dernière sauvegarde
    await autoSaveAll();

    const formData = new FormData(form);

    if (!formData.get('quiz_id')) {
        alert("⏳ Sauvegarde en cours... réessayez");
        return;
    }

    formData.set('statut', 'en attente');

    try {

        this.disabled = true;
        this.innerHTML = "⏳ Publication...";

        // ✅ 1. upload fichiers
        if (document.getElementById('attachments').files.length > 0) {

            const uploadRes = await fetch('/prof/quiz_upload_autosave.php', {
                method: 'POST',
                body: formData
            });

            const uploadData = await uploadRes.json();

            if (uploadData.status !== 'ok') {
                alert("❌ Erreur upload fichiers");
                this.disabled = false;
                return;
            }
        }

        // ✅ 2. publier quiz
        const res = await fetch('/prof/quiz_store.php', {
            method: 'POST',
            body: formData
        });

        const text = await res.text();
        console.log("SERVER:", text);

        let data;

        try {
            data = JSON.parse(text);
        } catch (e) {
            alert("❌ Erreur serveur (réponse invalide)");
            return;
        }

        if (data.success) {
            window.location.href = data.redirect;
        } else {
            alert("❌ Erreur publication");
            this.disabled = false;
        }

    } catch (e) {
        console.error(e);
        alert("❌ Erreur réseau");
        this.disabled = false;
    }
});

async function autoSaveAll() {

    if (saving) return; // 🔥 empêche conflit
    saving = true;

    try {

        const form = document.getElementById('formQuiz');
        const formData = new FormData(form);

        const r1 = await fetch('/prof/quiz_autosave.php', {
            method: 'POST',
            body: formData
        });

        const d1 = await r1.json();

        if (d1.quiz_id) {
            quizId.value = d1.quiz_id;
            formData.set('quiz_id', d1.quiz_id);
        }

        await fetch('/prof/quiz_questions_autosave.php', {
            method: 'POST',
            body: formData
        });

        // if (document.getElementById('attachments').files.length > 0) {
        //     await fetch('/prof/quiz_upload_autosave.php', {
        //         method: 'POST',
        //         body: formData
        //     });
        // }

    } catch (e) {
        console.error("Autosave error:", e);
    }

    saving = false;
}
setInterval(autoSaveAll, 3000);
</script>

<?php include __DIR__.'/layout/footer.php'; ?>