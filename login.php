<?php
// prof/login.php
declare(strict_types=1);

require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Empêcher la mise en cache (utile si on revient en arrière après logout)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Si déjà connecté, on va directement au dashboard
if (!empty($_SESSION['prof']) && !empty($_SESSION['prof']['id'])) {
  redirect('/prof/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code_connexion_raw = trim((string)($_POST['code_connexion'] ?? ''));
  $code_connexion = ctype_digit($code_connexion_raw) ? (int)$code_connexion_raw : 0;

  if ($code_connexion > 0) {
    $stmt = $con->prepare("SELECT * FROM agent WHERE code_connexion = ?");
    $stmt->bind_param('i', $code_connexion);
    $stmt->execute();
    $agent = $stmt->get_result()->fetch_assoc();

    if ($agent) {
      $_SESSION['prof'] = $agent;

      require_once __DIR__.'/includes/auth.php';
      $classes = classes_of_agent($con, (int)$agent['id']);
      if (count($classes) === 1) {
        set_current_classe((int)$classes[0]['id']);
      }

      redirect('/prof/dashboard.php');
    } else {
      $error = "Code de connexion incorrect.";
    }
  } else {
    $error = "Veuillez saisir un code de connexion valide.";
  }
}

include __DIR__.'/layout/header.php';
?>
<div class="container my-5" style="max-width:520px">
    <div class="card card-soft">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Connexion Professeur/Enseignant</h1>
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Bienvenue dans votre espace enseignant – connectez-vous pour accompagner vos élèves et enrichir leur apprentissage.
                    </label>
                    <div class="input-group">
                        <input type="password" name="code_connexion" id="code_connexion" class="form-control" required
                            inputmode="numeric" autocomplete="current-password"
                            placeholder="Saisissez votre code de connexion" />
                        <span class="input-group-text" id="togglePwd" style="cursor:pointer">
                            <i class="bi bi-eye" id="iconPwd"></i>
                        </span>
                    </div>
                    <!-- <div class="form-text">
            Saisissez votre <code>code_connexion</code> tel qu’enregistré dans la table <code>agent</code>.
          </div> -->
                </div>

                <button class="btn btn-primary w-100">Se connecter</button>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<script>
// Show/Hide password avec icône
(function() {
    const input = document.getElementById('code_connexion');
    const toggle = document.getElementById('togglePwd');
    const icon = document.getElementById('iconPwd');

    if (input && toggle && icon) {
        toggle.addEventListener('click', function() {
            const isPwd = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPwd ? 'text' : 'password');
            icon.className = isPwd ? 'bi bi-eye-slash' : 'bi bi-eye';
            input.focus();
        });
    }
})();
</script>

<?php include __DIR__.'/layout/footer.php'; ?>