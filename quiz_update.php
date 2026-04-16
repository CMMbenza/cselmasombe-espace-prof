<?php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

$prof    = current_prof();
$agentId = (int)$prof['id'];
$quizId  = (int)($_GET['id'] ?? 0);

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
    if ($questions) {
        $ids = array_column($questions, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmtC = $con->prepare("SELECT id, question_id, choice_text, is_correct, sort_order FROM quiz_choice WHERE question_id IN ($in) ORDER BY question_id, sort_order, id");
        $stmtC->bind_param($types, ...$ids);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($c = $resC->fetch_assoc()) {
            $choicesByQ[(int)$c['question_id']][] = $c;
        }
    }

    // Charger pièces jointes
    $stmtA = $con->prepare("SELECT id, file_path, original_name, mime_type, file_size, uploaded_at FROM quiz_attachment WHERE quiz_id=? ORDER BY id");
    $stmtA->bind_param('i', $quizId);
    $stmtA->execute();
    $attachments = $stmtA->get_result()->fetch_all(MYSQLI_ASSOC);

} catch (Throwable $e) {
    $error = "Impossible de charger le quiz.";
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

                <div class="mb-3">
                    <label class="form-label">Titre (Cours) :</label>
                    <input type="text" name="titre" class="form-control"
                        value="<?= e((string)($quiz['titre'] ?? '')) ?>" required>
                    <div class="form-text text-danger">
                        Le titre est lié au cours et ne peut pas être modifié.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"
                        rows="4"><?= e((string)($quiz['description'] ?? '')) ?></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
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

                    <div class="col-md-6 mb-3">
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
                </div>

                <div class="mb-3">
                    <label class="form-label">Date limite</label>
                    <input type="date" name="date_limite" class="form-control"
                        value="<?= e((string)($quiz['date_limite'] ?? '')) ?>">
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
const QUIZ_FORMAT = "<?= e($quiz['format'] ?? 'QCM') ?>";
document.getElementById('btnSaveAll')?.addEventListener('click', async function() {

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = "⏳ Enregistrement...";

    const quizId = <?= (int)$quizId ?>;

    // 🔥 construire les données
    const payload = {
        quiz_id: quizId,
        titre: document.querySelector('[name="titre"]').value,
        description: document.querySelector('[name="description"]').value,
        type_quiz: document.querySelector('[name="type_quiz"]').value,
        date_limite: document.querySelector('[name="date_limite"]').value,
        questions: []
    };

    document.querySelectorAll('.question-card').forEach(q => {

        const qid = q.dataset.id;

        const question = {
            id: qid,
            text: q.querySelector('.question-text').value,
            points: q.querySelector('.question-points').value,
            keywords: q.querySelector('.keywords-input')?.value || '',
            choices: []
        };

        // QCM choix
        q.querySelectorAll('.choice-item').forEach(c => {
            question.choices.push({
                id: c.dataset.id || null,
                text: c.querySelector('.choice-text').value,
                correct: c.querySelector('.choice-correct').checked ? 1 : 0
            });
        });

        payload.questions.push(question);
    });

    try {

        const res = await fetch('/prof/quiz_update_all.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const text = await res.text();
        console.log("RESPONSE RAW:", text);

        const data = JSON.parse(text);

        if (data.success) {
            window.location.href = "/prof/quiz_view.php?id=" + quizId;
        } else {
            alert("X Erreur lors de l'enregistrement");
            btn.disabled = false;
            btn.innerHTML = "💾 Enregistrer toutes les modifications";
        }

    } catch (e) {
        console.error(e);
        alert("X Erreur réseau");
        btn.disabled = false;
        btn.innerHTML = "💾 Enregistrer toutes les modifications";
    }

});

document.addEventListener('click', function(e) {

    // ➕ Ajouter choix
    if (e.target.classList.contains('add-choice')) {

        e.preventDefault();

        const container = e.target.closest('.question-card')
            .querySelector('.choices-container');

        const div = document.createElement('div');
        div.className = "input-group mb-2 choice-item";

        div.innerHTML = `
            <input type="text" class="form-control choice-text" placeholder="Nouvelle assertion">
            <span class="input-group-text">
                <input type="checkbox" class="choice-correct">
            </span>
            <button class="btn btn-danger btn-sm remove-choice">X</button>
        `;

        container.appendChild(div);
    }

    // X Supprimer choix
    if (e.target.classList.contains('remove-choice')) {
        e.preventDefault();
        e.target.closest('.choice-item').remove();
    }

});

document.getElementById('btnAddQuestion')?.addEventListener('click', function() {

    const container = document.getElementById('questions-container');

    const isRQ = QUIZ_FORMAT === 'RQ';

    const newCard = document.createElement('div');
    newCard.className = "card mb-3 question-card";
    newCard.dataset.id = "";

    newCard.innerHTML = `
        <div class="card-body">

            <div class="d-flex justify-content-between">
                <strong>Nouvelle question (${QUIZ_FORMAT})</strong>
                <button type="button" class="btn btn-sm btn-danger delete-question">Supprimer</button>
            </div>

            <textarea class="form-control mt-2 question-text" placeholder="Votre question..."></textarea>

            <div class="row mt-2">
                <div class="col-md-4">
                    <input type="number" class="form-control question-points" value="1">
                </div>

                <div class="col-md-4">
                    <input type="text" class="form-control" value="${QUIZ_FORMAT}" disabled>
                </div>
            </div>

            ${isRQ ? `
                <div class="mt-3">
                    <label>Mots clés IA</label>
                    <input type="text" class="form-control keywords-input">
                </div>
            ` : `
                <div class="mt-3 choices-container">
                    <h6>Choix QCM</h6>
                </div>

                <button class="btn btn-sm btn-success mt-2 add-choice">
                    ➕ Ajouter un choix
                </button>
            `}

        </div>
    `;

    container.appendChild(newCard);
});
document.addEventListener('click', function(e) {

    if (e.target.classList.contains('delete-question')) {
        e.preventDefault();
        e.target.closest('.question-card').remove();
    }

});
</script>

<?php include __DIR__.'/layout/footer.php'; ?>