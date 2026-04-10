<?php
declare(strict_types=1);

require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/helpers.php';
require_prof();

// -------------------------------------------------
// Helpers
// -------------------------------------------------
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['_csrf'];

// -------------------------------------------------
// Contexte PROF (agent + user)
// -------------------------------------------------
$prof = current_prof(); // doit retourner au moins ['id'=>...]
$agentId = (int)($prof['id'] ?? 0);
if ($agentId <= 0) $agentId = (int)($_SESSION['agent_id'] ?? 0);

// user_id du prof (table users) : utile pour messages ciblés dest_type='user'
$profUserId = (int)($_SESSION['user_id'] ?? 0);

$flashOk = '';
$flashErr = '';

// -------------------------------------------------
// Trouver le user Directeur (users.id) pour "envoyer au directeur"
// -------------------------------------------------
$directeurUserId = 0;
if ($res = $con->query("SELECT id FROM users WHERE LOWER(role) IN ('directeur','dir','direction') ORDER BY id ASC LIMIT 1")) {
  $row = $res->fetch_assoc();
  $directeurUserId = (int)($row['id'] ?? 0);
  $res->free();
}

// -------------------------------------------------
// Trouver l'agent Directeur (agent.id) pour lire "Du Directeur" via sender_id
// -------------------------------------------------
$directeurAgentId = (int)($_ENV['DIRECTEUR_AGENT_ID'] ?? 0);

// fallback: prendre un agent du service DIR & ADM
if ($directeurAgentId <= 0) {
  if ($res = $con->query("SELECT id FROM agent WHERE service='DIR & ADM' ORDER BY id ASC LIMIT 1")) {
    $row = $res->fetch_assoc();
    $directeurAgentId = (int)($row['id'] ?? 0);
    $res->free();
  }
}

// -------------------------------------------------
// POST: envoyer un message au Directeur (table annonces)
// -> on stocke sender_role='prof', sender_id=agentId, dest_type='user', dest_id=directeurUserId
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['_csrf'] ?? '');
  if (!hash_equals($csrf, $token)) {
    $flashErr = "Session expirée (CSRF). Recharge la page et réessaie.";
  } elseif ($agentId <= 0) {
    $flashErr = "Profil professeur invalide (agent_id manquant).";
  } elseif ($directeurUserId <= 0) {
    $flashErr = "Compte Directeur introuvable (table users.role).";
  } else {
    $titre   = trim((string)($_POST['titre'] ?? ''));
    $contenu = trim((string)($_POST['contenu'] ?? ''));

    if ($titre === '' || $contenu === '') {
      $flashErr = "Veuillez remplir le titre et le message.";
    } else {
      $sqlIns = "INSERT INTO annonces (titre, contenu, sender_role, sender_id, dest_type, dest_id)
                 VALUES (?, ?, 'prof', ?, 'user', ?)";
      if ($stmt = $con->prepare($sqlIns)) {
        $stmt->bind_param('ssii', $titre, $contenu, $agentId, $directeurUserId);
        if ($stmt->execute()) {
          $flashOk = "Message envoyé au Directeur ✅";
          $_POST['titre'] = '';
          $_POST['contenu'] = '';
        } else {
          $flashErr = "Erreur lors de l'envoi du message.";
        }
        $stmt->close();
      } else {
        $flashErr = "Erreur serveur (prepare).";
      }
    }
  }
}

// -------------------------------------------------
// 1) Annonces reçues (tous + profs)
// -------------------------------------------------
$annonces = [];
$q1 = $con->query("
  SELECT id, titre, contenu, created_at, sender_role, sender_id, dest_type, dest_id
  FROM annonces
  WHERE dest_type IN ('tous','profs')
  ORDER BY created_at DESC
  LIMIT 200
");
if ($q1) {
  $annonces = $q1->fetch_all(MYSQLI_ASSOC);
  $q1->free();
}

// -------------------------------------------------
// 2) Messages envoyés au Directeur (par moi)
// -------------------------------------------------
$sent = [];
if ($directeurUserId > 0 && $agentId > 0) {
  $sqlSent = "
    SELECT id, titre, contenu, created_at
    FROM annonces
    WHERE sender_role='prof' AND sender_id=? AND dest_type='user' AND dest_id=?
    ORDER BY created_at DESC
    LIMIT 200
  ";
  if ($stmt = $con->prepare($sqlSent)) {
    $stmt->bind_param('ii', $agentId, $directeurUserId);
    $stmt->execute();
    $sent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
}

// -------------------------------------------------
// 3) Du Directeur (on check sender_id = directeurAgentId)
// - messages publics/profs: dest_type IN ('tous','profs')
// - + messages directs: dest_type='user' AND dest_id = profUserId
// -------------------------------------------------
$fromDirecteur = [];

if ($directeurAgentId > 0) {
  if ($profUserId > 0) {
    $sqlDir = "
      SELECT id, titre, contenu, created_at, dest_type, dest_id
      FROM annonces
      WHERE sender_id = ?
        AND (
          dest_type IN ('tous','profs')
          OR (dest_type='user' AND dest_id=?)
        )
      ORDER BY created_at DESC
      LIMIT 200
    ";
    if ($stmt = $con->prepare($sqlDir)) {
      $stmt->bind_param('ii', $directeurAgentId, $profUserId);
      $stmt->execute();
      $fromDirecteur = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();
    }
  } else {
    // fallback si user_id absent : on affiche au moins tous/profs du directeur
    $sqlDir = "
      SELECT id, titre, contenu, created_at, dest_type, dest_id
      FROM annonces
      WHERE sender_id = ? AND dest_type IN ('tous','profs')
      ORDER BY created_at DESC
      LIMIT 200
    ";
    if ($stmt = $con->prepare($sqlDir)) {
      $stmt->bind_param('i', $directeurAgentId);
      $stmt->execute();
      $fromDirecteur = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmt->close();
    }
  }
}

include __DIR__.'/layout/header.php';
include __DIR__.'/layout/navbar.php';
?>

<div class="container">
    <div class="row g-3">

        <!-- Colonne gauche -->
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <h2 class="h5 mb-0">Messages</h2>
                        <span class="small text-muted">Reçus + Envoyés</span>
                    </div>

                    <?php if ($flashOk): ?>
                    <div class="alert alert-success py-2"><?= e($flashOk) ?></div>
                    <?php endif; ?>
                    <?php if ($flashErr): ?>
                    <div class="alert alert-danger py-2"><?= e($flashErr) ?></div>
                    <?php endif; ?>

                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-annonces" data-bs-toggle="tab"
                                data-bs-target="#pane-annonces" type="button" role="tab">
                                Reçus (Annonces)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-directeur" data-bs-toggle="tab"
                                data-bs-target="#pane-directeur" type="button" role="tab">
                                Du Directeur
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-envoyes" data-bs-toggle="tab"
                                data-bs-target="#pane-envoyes" type="button" role="tab">
                                Envoyés au Directeur
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content pt-3">

                        <!-- Reçus -->
                        <div class="tab-pane fade show active" id="pane-annonces" role="tabpanel"
                            aria-labelledby="tab-annonces">
                            <?php if (!$annonces): ?>
                            <div class="text-muted">Aucune annonce.</div>
                            <?php else: foreach ($annonces as $a): ?>
                            <article class="mb-4">
                                <header class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h3 class="h6 mb-1"><?= e($a['titre']) ?></h3>
                                        <div class="small text-muted">
                                            Dest.: <?= e($a['dest_type']) ?> • Auteur: <?= e($a['sender_role']) ?>
                                            (#<?= (int)$a['sender_id'] ?>)
                                        </div>
                                    </div>
                                    <time class="small text-muted"><?= e((string)$a['created_at']) ?></time>
                                </header>
                                <p class="mb-2"><?= nl2br(e($a['contenu'])) ?></p>
                                <hr>
                            </article>
                            <?php endforeach; endif; ?>
                        </div>

                        <!-- Du Directeur -->
                        <div class="tab-pane fade" id="pane-directeur" role="tabpanel" aria-labelledby="tab-directeur">
                            <?php if ($directeurAgentId <= 0): ?>
                            <div class="alert alert-warning">
                                Impossible d’identifier l’ID du Directeur (agent).<br>
                                Astuce: définis <code>DIRECTEUR_AGENT_ID</code> (ex: dans .env) ou assure-toi qu’un
                                agent a <code>service='DIR & ADM'</code>.
                            </div>
                            <?php elseif (!$fromDirecteur): ?>
                            <div class="text-muted">Aucun message du Directeur.</div>
                            <?php else: foreach ($fromDirecteur as $d): ?>
                            <article class="mb-4">
                                <header class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h3 class="h6 mb-1"><?= e($d['titre']) ?></h3>
                                        <div class="small text-muted">
                                            Dest.: <?= e((string)$d['dest_type']) ?>
                                            <?php if ((string)$d['dest_type'] === 'user'): ?> • Message
                                            direct<?php endif; ?>
                                        </div>
                                    </div>
                                    <time class="small text-muted"><?= e((string)$d['created_at']) ?></time>
                                </header>
                                <p class="mb-2"><?= nl2br(e($d['contenu'])) ?></p>
                                <hr>
                            </article>
                            <?php endforeach; endif; ?>
                        </div>

                        <!-- Envoyés -->
                        <div class="tab-pane fade" id="pane-envoyes" role="tabpanel" aria-labelledby="tab-envoyes">
                            <?php if (!$sent): ?>
                            <div class="text-muted">Aucun message envoyé au Directeur.</div>
                            <?php else: foreach ($sent as $s): ?>
                            <article class="mb-4">
                                <header class="d-flex justify-content-between align-items-start gap-2">
                                    <h3 class="h6 mb-1"><?= e($s['titre']) ?></h3>
                                    <time class="small text-muted"><?= e((string)$s['created_at']) ?></time>
                                </header>
                                <p class="mb-2"><?= nl2br(e($s['contenu'])) ?></p>
                                <hr>
                            </article>
                            <?php endforeach; endif; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Colonne droite -->
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h6 mb-3">Envoyer un message au Directeur</h2>

                    <?php if ($directeurUserId <= 0): ?>
                    <div class="alert alert-warning">
                        Aucun compte Directeur trouvé dans <code>users.role</code>.
                    </div>
                    <?php endif; ?>

                    <form method="post" class="needs-validation" novalidate>
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                        <div class="mb-3">
                            <label class="form-label">Titre</label>
                            <input type="text" name="titre" class="form-control" maxlength="150" required
                                value="<?= e((string)($_POST['titre'] ?? '')) ?>">
                            <div class="invalid-feedback">Le titre est obligatoire.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea name="contenu" class="form-control" rows="6"
                                required><?= e((string)($_POST['contenu'] ?? '')) ?></textarea>
                            <div class="invalid-feedback">Le message est obligatoire.</div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="reset" class="btn btn-outline-secondary">Effacer</button>
                            <button type="submit" class="btn btn-primary"
                                <?= $directeurUserId <= 0 ? 'disabled' : '' ?>>Envoyer</button>
                        </div>

                        <div class="small text-muted mt-3">
                            Stockage : <code>sender_role='prof'</code>, <code>sender_id=agent.id</code>,
                            <code>dest_type='user'</code> (Directeur).
                        </div>
                    </form>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function() {
    'use strict';
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<?php include __DIR__.'/layout/footer.php'; ?>