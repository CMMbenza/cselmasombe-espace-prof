<?php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];
$quizId  = (int)($_GET['id'] ?? 0);

// 🔥 Classes du prof
$stmt = $con->prepare("
    SELECT c.id, CONCAT(c.description, ' ', cy.description) AS full_name
    FROM classe c
    INNER JOIN cycle cy ON cy.id = c.cycle
    INNER JOIN affectation_prof_classe apc ON apc.classe_id = c.id
    WHERE apc.agent_id = ?
    GROUP BY c.id
");
$stmt->bind_param("i", $agentId);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$classesByLevel = $classes; // on ne groupe plus

if ($quizId <= 0) {
    redirect('/prof/quiz_list.php');
}

$error = '';
$success = '';

try {
    // Charger quiz
$stmt = $con->prepare("SELECT * FROM quiz WHERE id=? LIMIT 1");
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();

// 🔥 récupérer classes liées au quiz
$stmtQC = $con->prepare("SELECT classe_id FROM quiz_classe WHERE quiz_id=?");
$stmtQC->bind_param('i', $quizId);
$stmtQC->execute();
$resQC = $stmtQC->get_result();

$quizClasses = [];

while ($row = $resQC->fetch_assoc()) {
    $quizClasses[] = (int)$row['classe_id'];
}

    if (!$quiz || (int)$quiz['agent_id'] !== $agentId) {
        redirect('/prof/quiz_list.php');
    }

    // Charger questions
    $stmtQ = $con->prepare("
        SELECT id, `TYPE`, question_text, points, sort_order, expected_answer, similarity_min
        FROM quiz_question WHERE quiz_id=? ORDER BY sort_order, id
    ");
    $stmtQ->bind_param('i', $quizId);
    $stmtQ->execute();
    $questions = $stmtQ->get_result()->fetch_all(MYSQLI_ASSOC);

    // Charger mots-clés
    $keywordsByQ = [];
    if ($questions) {
        $ids = array_column($questions, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmtK = $con->prepare("SELECT question_id, keyword, poids FROM quiz_question_keyword WHERE question_id IN ($in)");
        $stmtK->bind_param($types, ...$ids);
        $stmtK->execute();
        $resK = $stmtK->get_result();
        while ($k = $resK->fetch_assoc()) {
            $keywordsByQ[(int)$k['question_id']][] = $k;
        }
    }

// Charger choix (pour QCM)
$choicesByQ = [];

if (!empty($questions)) {

    $ids = array_column($questions, 'id');

    if (!empty($ids)) {

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $sql = "
            SELECT 
                id,
                question_id,
                choice_text,
                is_correct,
                sort_order
            FROM quiz_choice
            WHERE question_id IN ($in)
            ORDER BY question_id, sort_order, id
        ";

        $stmtC = $con->prepare($sql);
        $stmtC->bind_param($types, ...$ids);
        $stmtC->execute();

        $resC = $stmtC->get_result();

        while ($c = $resC->fetch_assoc()) {
            $choicesByQ[(int)$c['question_id']][] = $c;
        }
    }
// }

        // 🔥 récupérer classes liées au quiz
        $stmtC = $con->prepare("
            SELECT classe_id 
            FROM quiz_classe 
            WHERE quiz_id=?
        ");
        $stmtC->bind_param('i', $quizId);
        $stmtC->execute();

        $resC = $stmtC->get_result();

        $quizClasses = [];

        while ($row = $resC->fetch_assoc()) {
            $quizClasses[] = (int)$row['classe_id'];
        }
    }

    // Charger pièces jointes
    $stmtA = $con->prepare("SELECT id, file_path, original_name, mime_type, file_size, uploaded_at FROM quiz_attachment WHERE quiz_id=? ORDER BY id");
    $stmtA->bind_param('i', $quizId);
    $stmtA->execute();
    $attachments = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC);

    $coursByClasse = [];

if ($classes) {
    $classIds = array_map(fn($c) => (int)$c['id'], $classes);

    if ($classIds) {
        $in  = implode(',', array_fill(0, count($classIds), '?'));
        $typ = str_repeat('i', count($classIds));

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
                'id' => (int)$row['id'],
                'intitule' => $row['intitule']
            ];
        }

        $stmt->close();
    }
}

} catch (Throwable $e) {
    $error = "Impossible de charger le quiz.";
}
$cycles = [];

$res = $con->query("SELECT id, description FROM cycle ORDER BY description");

while ($row = $res->fetch_assoc()) {
    $cycles[] = $row;
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>

<div class="container my-4">

    <h1 class="h5 mb-3">Modifier le Quiz</h1>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= e((string)$error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= e((string)$success) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">

            <form method="post">

                <div class="row">

                    <!-- CLASSE -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Classe</label>
                        <select name="classe_ids[]" id="classe_ids" class="form-select" multiple required>

                            <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= in_array((int)$c['id'], $quizClasses) ? 'selected' : '' ?>>
                                <?= e($c['full_name']) ?>
                            </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <!-- COURS -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Cours</label>
                        <select name="cours_id" id="cours_id" class="form-select" required>

                            <?php
                            $loadedCours = [];

                            foreach ($quizClasses as $cid) {
                                foreach ($coursByClasse[$cid] ?? [] as $c) {

                                    // éviter doublon
                                    if (in_array($c['id'], $loadedCours)) continue;
                                    $loadedCours[] = $c['id'];
                            ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?= ((int)$quiz['cours_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['intitule']) ?>
                            </option>
                            <?php
                            }
                        }
                        ?>

                        </select>
                    </div>

                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"
                        rows="4"><?= e((string)($quiz['description'] ?? '')) ?></textarea>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Type</label>
                        <select name="type_quiz" class="form-select" required>
                            <?php
                            $types = ['Exercice','Devoir','Interrogation','Examen'];
                            foreach ($types as $t):
                            ?>
                            <option value="<?= e($t) ?>" <?= ($quiz['type_quiz'] ?? '') === $t ? 'selected' : '' ?>>
                                <?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Format</label>
                        <select name="format" class="form-select" disabled required>
                            <?php
                            $formats = ['QCM','RQ','PJ'];
                            foreach ($formats as $f):
                            ?>
                            <option value="<?= e($f) ?>" <?= ($quiz['format'] ?? '') === $f ? 'selected' : '' ?>>
                                <?= e($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label text-danger">Date limite</label>
                        <input type="date" name="date_limite" class="form-control"
                            value="<?= e((string)($quiz['date_limite'] ?? '')) ?>">
                    </div>

                </div>
                <hr class="my-4">

                <h5 class="mb-3">Questions</h5>
                <div id="questions-container">
                    <?php foreach ($questions as $q): ?>
                    <div class="card mb-3 question-card" data-id="<?= (int)$q['id'] ?>">
                        <div class="card-body">

                            <div class="d-flex justify-content-between">
                                <strong>Question #<?= (int)$q['id'] ?></strong>
                                <button type="button" class="btn btn-sm btn-danger delete-question">Supprimer</button>
                            </div>

                            <textarea
                                class="form-control mt-2 question-text"><?= e((string)($q['question_text'] ?? '')) ?></textarea>

                            <div class="row mt-2">
                                <div class="col-md-4">
                                    <input type="number" class="form-control question-points"
                                        value="<?= e((string)($q['points'] ?? 1)) ?>" placeholder="Points">
                                </div>

                                <div class="col-md-4">
                                    <select class="form-select question-type" disabled>
                                        <option value="QCM" <?= ($q['TYPE'] ?? '')==='QCM'?'selected':'' ?>>QCM</option>
                                        <option value="RQ" <?= ($q['TYPE'] ?? '')==='RQ'?'selected':'' ?>>RQ</option>
                                    </select>
                                </div>
                            </div>

                            <?php if (($q['TYPE'] ?? '') === 'RQ'): ?>
                            <div class="mt-3">
                                <label>Mots clés IA (séparés par virgule)</label>
                                <input type="text" class="form-control keywords-input"
                                    value="<?= e((string)implode(',', array_map(fn($k)=>($k['keyword'] ?? ''), $keywordsByQ[(int)$q['id']] ?? []))) ?>">
                                <button class="btn btn-outline-warning btn-sm mt-2 save-keywords">
                                    Sauvegarder mots-clés
                                </button>
                            </div>
                            <?php endif; ?>

                            <?php if (($q['TYPE'] ?? '')==='QCM'): ?>
                            <div class="mt-3 choices-container">
                                <h6>Choix QCM</h6>
                                <?php $chs = $choicesByQ[(int)$q['id']] ?? []; ?>
                                <?php foreach ($chs as $ch): ?>
                                <div class="input-group mb-2 choice-item" data-id="<?= (int)$ch['id'] ?>">
                                    <input type="text" class="form-control choice-text"
                                        value="<?= e((string)($ch['choice_text'] ?? '')) ?>">
                                    <span class="input-group-text">
                                        <input type="checkbox" class="choice-correct"
                                            <?= !empty($ch['is_correct'])?'checked':'' ?>>
                                    </span>
                                    <button class="btn btn-danger btn-sm remove-choice text-white">X</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button class="btn btn-sm btn-success mt-2 add-choice">➕ Ajouter un assertion</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mb-3 text-center">
                    <button type="button" id="btnAddQuestion" class="btn btn-outline-primary">
                        ➕ Ajouter une question
                    </button>
                </div>

                <div class="mb-3">
                    <hr class="my-4">

                    <h5>Pièces jointes</h5>

                    <div id="attachments-container">

                        <?php foreach ($attachments as $a): ?>
                        <div class="d-flex align-items-center mb-2 attachment-item" data-id="<?= (int)$a['id'] ?>">

                            <a href="<?= e($a['file_path']) ?>" target="_blank" class="me-3">
                                📎 <?= e($a['original_name']) ?>
                            </a>

                            <button class="btn btn-sm btn-danger delete-attachment">
                                Supprimer
                            </button>

                        </div>
                        <?php endforeach; ?>

                    </div>

                    <div class="mt-3">
                        <input type="file" id="newAttachments" multiple class="form-control">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4">
                    <a href="/prof/quiz_view.php?id=<?= (int)$quizId ?>" class="btn btn-dark">
                        ← Annuler
                    </a>

                    <button type="button" id="btnSaveAll" class="btn btn-success">
                        💾 Enregistrer toutes les modifications
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log("JS READY");
});

const QUIZ_FORMAT = "<?= e($quiz['format'] ?? 'QCM') ?>";
const COURS_BY_CLASSE = <?= json_encode($coursByClasse) ?>;
const CLASSES = <?= json_encode($classes) ?>;
const quizId = <?= (int)$quizId ?>;

document.getElementById('classe_ids').addEventListener('change', function() {

    const selected = Array.from(this.selectedOptions).map(o => o.value);
    const coursSelect = document.getElementById('cours_id');

    coursSelect.innerHTML = '';

    let added = new Set();

    selected.forEach(classeId => {

        if (!COURS_BY_CLASSE[classeId]) return;

        COURS_BY_CLASSE[classeId].forEach(c => {

            if (added.has(c.id)) return;
            added.add(c.id);

            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.intitule;

            coursSelect.appendChild(opt);
        });

    });

});

const payload = {
    quiz_id: quizId,
    classe_ids: Array.from(document.getElementById('classe_ids').selectedOptions).map(o => o.value),
    cours_id: document.getElementById('cours_id').value,
    description: document.querySelector('[name="description"]').value,
    type_quiz: document.querySelector('[name="type_quiz"]').value,
    date_limite: document.querySelector('[name="date_limite"]').value,
    questions: []
};

document.getElementById('btnSaveAll')?.addEventListener('click', async function(e) {
    e.preventDefault();

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = "⏳ Enregistrement...";

    try {

        const quizId = <?= (int)$quizId ?>;

        // 🔥 Classes sélectionnées
        const classe_ids = Array.from(
            document.getElementById('classe_ids').selectedOptions
        ).map(o => parseInt(o.value));

        // 🔥 cours sélectionné
        const cours_id = parseInt(document.getElementById('cours_id').value);

        // 🔥 sécurité basique
        if (classe_ids.length === 0) {
            alert("Veuillez sélectionner au moins une classe");
            throw new Error("No class selected");
        }

        if (!cours_id) {
            alert("Veuillez sélectionner un cours");
            throw new Error("No course selected");
        }

        // 🔥 payload propre
        const payload = {
            quiz_id: quizId,
            classe_ids,
            cours_id,
            description: document.querySelector('[name="description"]').value,
            type_quiz: document.querySelector('[name="type_quiz"]').value,
            format: document.querySelector('[name="format"]').value,
            date_limite: document.querySelector('[name="date_limite"]').value,
            questions: []
        };

        // 🔥 questions
        document.querySelectorAll('.question-card').forEach(q => {

            const question = {
                id: q.dataset.id || null,
                text: q.querySelector('.question-text').value.trim(),
                points: parseInt(q.querySelector('.question-points').value || 1),
                keywords: q.querySelector('.keywords-input')?.value || '',
                choices: []
            };

            q.querySelectorAll('.choice-item').forEach(c => {
                question.choices.push({
                    id: c.dataset.id ? parseInt(c.dataset.id) : null,
                    text: c.querySelector('.choice-text').value.trim(),
                    correct: c.querySelector('.choice-correct').checked ? 1 : 0
                });
            });

            payload.questions.push(question);
        });

        // 🔥 upload fichiers
        const files = document.getElementById('newAttachments').files;

        if (files.length > 0) {
            const fd = new FormData();
            fd.append('quiz_id', quizId);

            for (let f of files) {
                fd.append('attachments[]', f);
            }

            await fetch('/prof/quiz_upload_autosave.php', {
                method: 'POST',
                body: fd
            });
        }

        // 🔥 envoi final
        const res = await fetch('/prof/quiz_update_all.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();

        if (data.success) {
            window.location.href = "/prof/quiz_view.php?id=" + quizId;
        } else {
            alert("Erreur lors de l'enregistrement");
        }

    } catch (e) {
        console.error(e);
        alert("Erreur lors de la sauvegarde");
    } finally {
        btn.disabled = false;
        btn.innerHTML = "💾 Enregistrer toutes les modifications";
    }
});

document.addEventListener('click', function(e) {

    const btn = e.target.closest('.add-choice');
    if (!btn) return;

    e.preventDefault();

    const card = btn.closest('.question-card');
    if (!card) return;

    const container = card.querySelector('.choices-container');
    if (!container) return;

    const div = document.createElement('div');
    div.className = "input-group mb-2 choice-item";

    div.innerHTML = `
        <input type="text" class="form-control choice-text" placeholder="Nouvelle assertion">
        <span class="input-group-text">
            <input type="checkbox" class="choice-correct">
        </span>
        <button type="button" class="btn btn-danger btn-sm remove-choice">X</button>
    `;

    container.appendChild(div);
});

document.addEventListener('click', function(e) {

    const btn = e.target.closest('.remove-choice');
    if (!btn) return;

    e.preventDefault();
    btn.closest('.choice-item')?.remove();
});

document.addEventListener('click', function(e) {

    const btn = e.target.closest('.delete-question');
    if (!btn) return;

    e.preventDefault();
    btn.closest('.question-card')?.remove();
});

document.addEventListener('click', function(e) {

    // ➕ AJOUT QUESTION
    if (e.target.closest('#btnAddQuestion')) {

        e.preventDefault();

        const container = document.getElementById('questions-container');
        if (!container) return;

        const card = document.createElement('div');
        card.className = "card mb-3 question-card";

        card.innerHTML = `
            <div class="card-body">

                <div class="d-flex justify-content-between">
                    <strong>Nouvelle question</strong>
                    <button type="button" class="btn btn-sm btn-danger delete-question">Supprimer</button>
                </div>

                <textarea class="form-control mt-2 question-text"></textarea>

                <div class="row mt-2">
                    <div class="col-md-4">
                        <input type="number" class="form-control question-points" value="1">
                    </div>
                </div>

                <div class="mt-3 choices-container">
                    <h6>Choix QCM</h6>
                </div>

                <button type="button" class="btn btn-sm btn-success mt-2 add-choice">
                    ➕ Ajouter une assertion
                </button>

            </div>
        `;

        container.appendChild(card);
        return;
    }
});

document.addEventListener('click', function(e) {

    if (e.target.classList.contains('delete-question')) {
        e.preventDefault();
        e.target.closest('.question-card').remove();
    }

});

document.addEventListener('click', async function(e) {

    if (e.target.classList.contains('delete-attachment')) {

        e.preventDefault();

        const row = e.target.closest('.attachment-item');
        const id = row.dataset.id;

        if (!confirm("Supprimer ce fichier ?")) return;

        try {

            const res = await fetch('/prof/quiz_delete_attachment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id
                })
            });

            const data = await res.json();

            if (data.success) {
                row.remove();
            } else {
                alert("Erreur suppression");
            }

        } catch (e) {
            alert("Erreur réseau");
        }
    }

});
window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('classe_ids').dispatchEvent(new Event('change'));
});
</script>

<?php include __DIR__.'/layout/footer.php'; ?>