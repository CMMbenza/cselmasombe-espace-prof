<?php
// /prof/eleves/dossier_eleves.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_prof();

$prof     = current_prof();
$classeId = get_current_classe();

include __DIR__.'/../layout/header.php';
include __DIR__.'/../layout/navbar.php';

if (!$classeId): ?>
<div class="container">
    <div class="alert alert-info">
        Aucune classe sélectionnée.
        <a href="/prof/switch_classe.php">Choisir une classe</a>
    </div>
</div>
<?php include __DIR__.'/../layout/footer.php'; exit; endif;

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Helper bind_param (mysqli)
function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
  $refs = [];
  foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
  $stmt->bind_param($types, ...$refs);
}

$eleveId = (int)($_GET['eleve_id'] ?? 0);

// Filtres
$anneeFilter = trim((string)($_GET['annee'] ?? ''));

// ✅ periode_id peut être:
// - "0" => période active
// - un ID numérique => période normale
// - "EX_T1", "EX_T2", "EX_T3" => examen(s) selon cycle
$periodeFilterRaw = (string)($_GET['periode_id'] ?? '0');
$periodeFilter    = ctype_digit($periodeFilterRaw) ? (int)$periodeFilterRaw : 0;
$periodeExamKey   = ctype_digit($periodeFilterRaw) ? '' : strtoupper(trim($periodeFilterRaw));

$coursFilter = isset($_GET['cours_id']) ? (int)$_GET['cours_id'] : 0; // 0 = tous

// CSRF simple
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['_csrf'];

$err = '';
$success = '';
$eleve = null;

// Stats présence (reste global)
$presenceStats = ['total'=>0,'present'=>0,'absent'=>0];

// Stats quiz
$quizStats = ['total'=>0,'corrige'=>0,'moyenne'=>null,'max'=>null,'min'=>null];
$quizRecent = [];

// Cahier
$cahierStats = ['total'=>0,'avec_note'=>0,'moyenne'=>null,'max'=>null,'min'=>null];
$cahierRecent = [];

// Options filtres
$periodesOptions = [];
$anneeOptions    = [];
$coursOptions    = [];

// Période active + pondération + fiche points
$periodeActifId    = 0;
$periodeActifLabel = '';
$ponderationPoints = null; // points du cours pour période active
$fichePoints       = ['points_total'=>null,'appreciation'=>''];

// ✅ Examens (virtuels)
$isExamSelected     = false;
$examPeriodIds      = []; // ids périodes qui composent l'examen sélectionné
$examLabel          = '';
$examPonderationSum = null;
$examPointsSum      = null;

// ✅ AJOUTS : override examen (editable)
$examKey            = $periodeExamKey; // EX_T1/EX_T2/EX_T3 si examen choisi
$examPointsOverride = null;            // valeur enregistrée
$examPointsFinal    = null;            // override si existe sinon somme
$examAppOverride    = '';              // appréciation enregistrée

// -------------------------
// 1) Vérifier élève + profil
// -------------------------
if ($eleveId <= 0) {
  $err = "Élève introuvable (identifiant manquant).";
} else {

  // 1.a Profil élève + cycle
  $sql = "
    SELECT
      e.*,
      c.description AS classe_desc,
      c.cycle       AS cycle_id
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    WHERE e.id = ? AND e.classe = ?
    LIMIT 1
  ";
  if ($stmt = $con->prepare($sql)) {
    $stmt->bind_param('ii', $eleveId, $classeId);
    $stmt->execute();
    $eleve  = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$eleve) $err = "Élève introuvable dans votre classe.";
  } else {
    $err = "Erreur lors du chargement du profil élève.";
  }

  // 1.b Années scolaires
  if (!$err) {
    $sql = "SELECT annee_scolaire FROM annee_scolaire ORDER BY dateDebut DESC";
    if ($res = $con->query($sql)) {
      while ($row = $res->fetch_assoc()) $anneeOptions[] = (string)$row['annee_scolaire'];
    }
  }

  // 1.c Périodes du cycle
  if (!$err) {
    $cycleId = (int)($eleve['cycle_id'] ?? 0);
    if ($cycleId > 0) {
      // ✅ on récupère ordre pour garantir le regroupement (P1,P2)(P3,P4)...
      $sql = "SELECT id, CODE, libelle, actif, ordre FROM periodes WHERE cycle_id = ? ORDER BY ordre";
      if ($stmt = $con->prepare($sql)) {
        $stmt->bind_param('i', $cycleId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $periodesOptions[] = [
            'id'      => (int)$row['id'],
            'code'    => (string)$row['CODE'],
            'libelle' => (string)$row['libelle'],
            'actif'   => (int)$row['actif'],
            'ordre'   => (int)$row['ordre'],
          ];
        }
        $stmt->close();
      }
    }

    // ✅ construire les examens selon nombre de périodes
    // - primaire/maternelle => 6 périodes => EX_T1=P1+P2, EX_T2=P3+P4, EX_T3=P5+P6
    // - humanité/secondaire => 4 périodes => EX_T1=P1+P2, EX_T2=P3+P4
    $orderedPeriodIds = array_values(array_map(fn($p)=>(int)$p['id'], $periodesOptions));
    $nbPeriodes = count($orderedPeriodIds);

    $examGroups = []; // key => [ids]
    if ($nbPeriodes >= 6) {
      $examGroups = [
        'EX_T1' => [$orderedPeriodIds[0], $orderedPeriodIds[1]],
        'EX_T2' => [$orderedPeriodIds[2], $orderedPeriodIds[3]],
        'EX_T3' => [$orderedPeriodIds[4], $orderedPeriodIds[5]],
      ];
    } elseif ($nbPeriodes >= 4) {
      $examGroups = [
        'EX_T1' => [$orderedPeriodIds[0], $orderedPeriodIds[1]],
        'EX_T2' => [$orderedPeriodIds[2], $orderedPeriodIds[3]],
      ];
    } else {
      $examGroups = []; // pas d'examen si pas assez de périodes
    }

    // ✅ Déterminer la période "affichée"
    if ($periodeExamKey !== '' && isset($examGroups[$periodeExamKey])) {
      // EXAMEN sélectionné
      $isExamSelected = true;
      $examPeriodIds  = $examGroups[$periodeExamKey];

      if ($periodeExamKey === 'EX_T1') $examLabel = 'Examen — 1er Trimestre';
      elseif ($periodeExamKey === 'EX_T2') $examLabel = 'Examen — 2e Trimestre';
      elseif ($periodeExamKey === 'EX_T3') $examLabel = 'Examen — 3e Trimestre';
      else $examLabel = 'Examen';

      $periodeActifId    = 0; // pas une période réelle
      $periodeActifLabel = $examLabel;

    } elseif ($periodeFilter > 0) {
      // période réelle sélectionnée
      $periodeActifId = $periodeFilter;
      foreach ($periodesOptions as $p) {
        if ((int)$p['id'] === $periodeActifId) {
          $periodeActifLabel = trim($p['code'].' — '.$p['libelle']);
          break;
        }
      }
    } else {
      // période active
      foreach ($periodesOptions as $p) {
        if (!empty($p['actif'])) {
          $periodeActifId = (int)$p['id'];
          $periodeActifLabel = trim($p['code'].' — '.$p['libelle']);
          break;
        }
      }
      // fallback: première période
      if ($periodeActifId === 0 && !empty($periodesOptions[0]['id'])) {
        $periodeActifId = (int)$periodesOptions[0]['id'];
        $periodeActifLabel = trim($periodesOptions[0]['code'].' — '.$periodesOptions[0]['libelle']);
      }
    }
  }

  // 1.d Cours du prof dans la classe
  if (!$err) {
    $profId = (int)($prof['id'] ?? 0);
    if ($profId > 0) {
      $sql = "
        SELECT DISTINCT co.id, co.intitule
        FROM affectation_prof_classe apc
        JOIN cours co ON co.id = apc.cours_id
        WHERE apc.agent_id = ? AND apc.classe_id = ?
        ORDER BY co.intitule ASC
      ";
      if ($stmt = $con->prepare($sql)) {
        $stmt->bind_param('ii', $profId, $classeId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $coursOptions[] = ['id'=>(int)$row['id'], 'intitule'=>(string)$row['intitule']];
        }
        $stmt->close();
      }

      // Neutraliser un cours non autorisé
      if ($coursFilter > 0) {
        $allowed = false;
        foreach ($coursOptions as $c) {
          if ((int)$c['id'] === $coursFilter) { $allowed = true; break; }
        }
        if (!$allowed) $coursFilter = 0;
      }
    }
  }
}

// -------------------------
// 1.e Gestion POST :
// - save_exam_points => enregistrer la note d'examen (override)
// - save_fiche_points => période normale (bloqué si examen)
// -------------------------
if (!$err && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $action   = (string)($_POST['action'] ?? '');
  $postCsrf = (string)($_POST['_csrf'] ?? '');

  if (!hash_equals($csrf, $postCsrf)) {
    $err = "Session expirée (CSRF). Recharge la page et réessaie.";
  } elseif ($action === 'save_exam_points') {

    if (!$isExamSelected) {
      $err = "Action examen invalide : sélectionne d’abord un examen (EX_T1/EX_T2/EX_T3).";
    } else {

      $cours_id_form = (int)($_POST['cours_id'] ?? 0);
      $exam_key_form = strtoupper(trim((string)($_POST['exam_key'] ?? '')));

      if ($cours_id_form <= 0) {
        $err = "Veuillez sélectionner un cours.";
      } elseif (!in_array($exam_key_form, ['EX_T1','EX_T2','EX_T3'], true)) {
        $err = "Examen invalide.";
      } else {

        $anneeToSave = $anneeFilter !== '' ? $anneeFilter : (string)($eleve['anneeScolaire'] ?? '');
        if ($anneeToSave === '') $anneeToSave = '—';

        $points_exam = trim((string)($_POST['points_exam'] ?? ''));
        $app_exam    = trim((string)($_POST['appreciation_exam'] ?? ''));

        $pointsVal = null;
        if ($points_exam !== '') $pointsVal = (float)str_replace(',', '.', $points_exam);

        // created_by (agent.id) ou NULL
        $profId = (int)($prof['id'] ?? 0);
        $createdBy = null;
        if ($profId > 0) {
          $chk = $con->prepare("SELECT id FROM agent WHERE id = ? LIMIT 1");
          if ($chk) {
            $chk->bind_param('i', $profId);
            $chk->execute();
            $ok = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ok) $createdBy = $profId;
          }
        }

        // ✅ Table override examens (doit exister)
        $sql = "
          INSERT INTO cours_points_examens
            (classe_id, eleve_id, cours_id, examen_key, anneeScolaire, points_total, appreciation, created_by)
          VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            points_total = VALUES(points_total),
            appreciation = VALUES(appreciation),
            created_by   = VALUES(created_by)
        ";

        if ($stmt2 = $con->prepare($sql)) {
          // NOTE: bind_param ne supporte pas bien les null stricts sur certains drivers,
          // on envoie string/null, MySQL convertira.
          $pt = ($pointsVal === null) ? null : (string)$pointsVal;
          $cb = ($createdBy === null) ? null : (int)$createdBy;

          $stmt2->bind_param(
            'iiissssi',
            $classeId,
            $eleveId,
            $cours_id_form,
            $exam_key_form,
            $anneeToSave,
            $pt,
            $app_exam,
            $cb
          );
          $stmt2->execute();
          $stmt2->close();

          $success = "Note d’examen enregistrée.";
        } else {
          $err = "Erreur lors de l'enregistrement de l'examen (prepare).";
        }
      }
    }

  } elseif ($action === 'save_fiche_points') {

    // ✅ On bloque l'enregistrement si un examen est sélectionné (car examen = somme / override)
    if ($isExamSelected) {
      $err = "Impossible d’encoder directement un EXAMEN ici : utilisez le formulaire Examen.";
    } else {

      $cours_id_form   = (int)($_POST['cours_id'] ?? 0);
      $periode_id_form = (int)($_POST['periode_id'] ?? 0);

      if ($cours_id_form <= 0) {
        $err = "Veuillez sélectionner un cours pour enregistrer la fiche de points.";
      } elseif ($periode_id_form <= 0) {
        $err = "Période invalide. Veuillez sélectionner une période valide.";
      } else {

        $anneeToSave = $anneeFilter !== '' ? $anneeFilter : (string)($eleve['anneeScolaire'] ?? '');
        if ($anneeToSave === '') $anneeToSave = '—';

        $points_total = trim((string)($_POST['points_total'] ?? ''));
        $appreciation = trim((string)($_POST['appreciation'] ?? ''));

        $pointsVal = null;
        if ($points_total !== '') {
          $pointsVal = (float)str_replace(',', '.', $points_total);
        }

        // ✅ created_by (agent.id) ou NULL
        $profId = (int)($prof['id'] ?? 0);
        $createdBy = null;
        if ($profId > 0) {
          $chk = $con->prepare("SELECT id FROM agent WHERE id = ? LIMIT 1");
          if ($chk) {
            $chk->bind_param('i', $profId);
            $chk->execute();
            $ok = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($ok) $createdBy = $profId;
          }
        }

        // SQL dynamique pour éviter les mismatch bind_param
        $pointsInsertSql = ($pointsVal === null) ? "NULL" : "CAST(? AS DECIMAL(6,2))";
        $pointsUpdateSql = ($pointsVal === null) ? "NULL" : "CAST(? AS DECIMAL(6,2))";
        $createdSql      = ($createdBy === null) ? "NULL" : "?";

        $sql = "
          INSERT INTO cours_points_eleves
            (classe_id, eleve_id, cours_id, periode_id, anneeScolaire, points_total, appreciation, created_by)
          VALUES
            (?, ?, ?, ?, ?, {$pointsInsertSql}, ?, {$createdSql})
          ON DUPLICATE KEY UPDATE
            points_total = {$pointsUpdateSql},
            appreciation = VALUES(appreciation),
            created_by   = VALUES(created_by)
        ";

        $stmt2 = $con->prepare($sql);
        if (!$stmt2) {
          $err = "Erreur lors de l'enregistrement (prepare).";
        } else {
          $types  = "iiiis"; // classe_id, eleve_id, cours_id, periode_id, anneeScolaire
          $params = [$classeId, $eleveId, $cours_id_form, $periode_id_form, $anneeToSave];

          if ($pointsVal !== null) {
            $types .= "s";
            $params[] = (string)$pointsVal;
          }

          $types .= "s"; // appreciation
          $params[] = $appreciation;

          if ($createdBy !== null) {
            $types .= "i";
            $params[] = $createdBy;
          }

          if ($pointsVal !== null) {
            $types .= "s";
            $params[] = (string)$pointsVal;
          }

          $refs = [];
          foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
          $stmt2->bind_param($types, ...$refs);

          $stmt2->execute();
          $stmt2->close();
          $success = "Points enregistrés.";
        }
      }
    }
  }
}

// -------------------------
// 2) Présence globale
// -------------------------
if (!$err) {
  $where  = "ad.eleve_id = ?";
  $types  = 'i';
  $params = [$eleveId];

  if ($anneeFilter !== '') {
    $where   .= " AND a.anneeScolaire = ?";
    $types   .= 's';
    $params[] = $anneeFilter;
  }

  $sql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS present,
      SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absent
    FROM appel_detail ad
    JOIN appel a ON a.id = ad.appel_id
    WHERE {$where}
  ";

  if ($stmt = $con->prepare($sql)) {
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
      $presenceStats['total']   = (int)($res['total'] ?? 0);
      $presenceStats['present'] = (int)($res['present'] ?? 0);
      $presenceStats['absent']  = (int)($res['absent'] ?? 0);
    }
  }
}

// -------------------------
// 3) Stats quiz (filtre année + période/examen + cours)
// -------------------------
if (!$err) {
  $where  = "qs.eleve_id = ?";
  $types  = 'i';
  $params = [$eleveId];

  if ($anneeFilter !== '') {
    $where   .= " AND e.anneeScolaire = ?";
    $types   .= 's';
    $params[] = $anneeFilter;
  }

  if ($isExamSelected && $examPeriodIds) {
    $place = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $where .= " AND q.periode_id IN ($place)";
    $types .= str_repeat('i', count($examPeriodIds));
    foreach ($examPeriodIds as $pid) $params[] = (int)$pid;
  } elseif ($periodeFilter > 0) {
    $where   .= " AND q.periode_id = ?";
    $types   .= 'i';
    $params[] = $periodeFilter;
  }

  if ($coursFilter > 0) {
    $where   .= " AND q.cours_id = ?";
    $types   .= 'i';
    $params[] = $coursFilter;
  }

  $sql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN qs.statut = 'corrige' THEN 1 ELSE 0 END) AS corrige,
      AVG(qs.note_totale) AS moyenne,
      MAX(qs.note_totale) AS max_note,
      MIN(qs.note_totale) AS min_note
    FROM quiz_submission qs
    JOIN quiz q   ON q.id = qs.quiz_id
    JOIN eleve e  ON e.id = qs.eleve_id
    WHERE {$where}
  ";

  if ($stmt = $con->prepare($sql)) {
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
      $quizStats['total']   = (int)($res['total'] ?? 0);
      $quizStats['corrige'] = (int)($res['corrige'] ?? 0);
      $quizStats['moyenne'] = $res['moyenne'] !== null ? (float)$res['moyenne'] : null;
      $quizStats['max']     = $res['max_note'] !== null ? (float)$res['max_note'] : null;
      $quizStats['min']     = $res['min_note'] !== null ? (float)$res['min_note'] : null;
    }
  }
}

// -------------------------
// 4) Derniers quiz
// -------------------------
if (!$err) {
  $where  = "qs.eleve_id = ?";
  $types  = 'i';
  $params = [$eleveId];

  if ($anneeFilter !== '') {
    $where   .= " AND e.anneeScolaire = ?";
    $types   .= 's';
    $params[] = $anneeFilter;
  }

  if ($isExamSelected && $examPeriodIds) {
    $place = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $where .= " AND q.periode_id IN ($place)";
    $types .= str_repeat('i', count($examPeriodIds));
    foreach ($examPeriodIds as $pid) $params[] = (int)$pid;
  } elseif ($periodeFilter > 0) {
    $where   .= " AND q.periode_id = ?";
    $types   .= 'i';
    $params[] = $periodeFilter;
  }

  if ($coursFilter > 0) {
    $where   .= " AND q.cours_id = ?";
    $types   .= 'i';
    $params[] = $coursFilter;
  }

  $sql = "
    SELECT
      qs.id,
      qs.date_submitted,
      qs.note_totale,
      qs.statut,
      q.titre,
      q.type_quiz,
      q.format
    FROM quiz_submission qs
    JOIN quiz q  ON q.id = qs.quiz_id
    JOIN eleve e ON e.id = qs.eleve_id
    WHERE {$where}
    ORDER BY qs.date_submitted DESC
    LIMIT 10
  ";

  if ($stmt = $con->prepare($sql)) {
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $quizRecent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
}

// -------------------------
// 5) Stats cahier
// -------------------------
if (!$err) {
  $where  = "cc.eleve_id = ? AND cc.classe_id = ?";
  $types  = 'ii';
  $params = [$eleveId, $classeId];

  if ($anneeFilter !== '') {
    $where   .= " AND e.anneeScolaire = ?";
    $types   .= 's';
    $params[] = $anneeFilter;
  }

  if ($isExamSelected && $examPeriodIds) {
    $place = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $where .= " AND cc.periode_id IN ($place)";
    $types .= str_repeat('i', count($examPeriodIds));
    foreach ($examPeriodIds as $pid) $params[] = (int)$pid;
  } elseif ($periodeFilter > 0) {
    $where   .= " AND cc.periode_id = ?";
    $types   .= 'i';
    $params[] = $periodeFilter;
  }

  if ($coursFilter > 0) {
    $where   .= " AND cc.cours_id = ?";
    $types   .= 'i';
    $params[] = $coursFilter;
  }

  $sql = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN cc.points IS NOT NULL THEN 1 ELSE 0 END) AS avec_note,
      AVG(cc.points) AS moyenne,
      MAX(cc.points) AS max_points,
      MIN(cc.points) AS min_points
    FROM cahier_cotes cc
    JOIN eleve e ON e.id = cc.eleve_id
    WHERE {$where}
  ";

  if ($stmt = $con->prepare($sql)) {
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
      $cahierStats['total']     = (int)($res['total'] ?? 0);
      $cahierStats['avec_note'] = (int)($res['avec_note'] ?? 0);
      $cahierStats['moyenne']   = $res['moyenne']    !== null ? (float)$res['moyenne']    : null;
      $cahierStats['max']       = $res['max_points'] !== null ? (float)$res['max_points'] : null;
      $cahierStats['min']       = $res['min_points'] !== null ? (float)$res['min_points'] : null;
    }
  }
}

// -------------------------
// 6) Dernières lignes cahier
// -------------------------
if (!$err) {
  $where  = "cc.eleve_id = ? AND cc.classe_id = ?";
  $types  = 'ii';
  $params = [$eleveId, $classeId];

  if ($anneeFilter !== '') {
    $where   .= " AND e.anneeScolaire = ?";
    $types   .= 's';
    $params[] = $anneeFilter;
  }

  if ($isExamSelected && $examPeriodIds) {
    $place = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $where .= " AND cc.periode_id IN ($place)";
    $types .= str_repeat('i', count($examPeriodIds));
    foreach ($examPeriodIds as $pid) $params[] = (int)$pid;
  } elseif ($periodeFilter > 0) {
    $where   .= " AND cc.periode_id = ?";
    $types   .= 'i';
    $params[] = $periodeFilter;
  }

  if ($coursFilter > 0) {
    $where   .= " AND cc.cours_id = ?";
    $types   .= 'i';
    $params[] = $coursFilter;
  }

  $sql = "
    SELECT
      cc.id,
      cc.type_app,
      cc.points,
      cc.remarque,
      cc.created_at,
      co.intitule AS cours,
      p.CODE      AS periode_code,
      p.libelle   AS periode_libelle
    FROM cahier_cotes cc
    JOIN cours co        ON co.id = cc.cours_id
    LEFT JOIN periodes p ON p.id = cc.periode_id
    JOIN eleve e         ON e.id = cc.eleve_id
    WHERE {$where}
    ORDER BY cc.created_at DESC
    LIMIT 10
  ";

  if ($stmt = $con->prepare($sql)) {
    bind_params($stmt, $types, $params);
    $stmt->execute();
    $cahierRecent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }
}

// -------------------------
// 7) Pondération + fiche points (période active) / Examen (somme + override)
// -------------------------
if (!$err && $coursFilter > 0) {

  $anneeToLoad = $anneeFilter !== '' ? $anneeFilter : (string)($eleve['anneeScolaire'] ?? '');
  if ($anneeToLoad === '') $anneeToLoad = '—';

  if ($isExamSelected && $examPeriodIds) {

    // ✅ Pondération examen = somme des pondérations des périodes de l'examen
    $place = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $sql = "SELECT periode_id, points FROM cours_ponderations WHERE cours_id = ? AND periode_id IN ($place)";
    if ($stmt = $con->prepare($sql)) {
      $types = 'i' . str_repeat('i', count($examPeriodIds));
      $params = array_merge([$coursFilter], $examPeriodIds);
      bind_params($stmt, $types, $params);
      $stmt->execute();
      $res = $stmt->get_result();
      $sum = 0;
      $found = false;
      while ($row = $res->fetch_assoc()) {
        if ($row['points'] !== null) { $sum += (int)$row['points']; $found = true; }
      }
      $stmt->close();
      $examPonderationSum = $found ? $sum : null;
      $ponderationPoints = $examPonderationSum; // pour affichage
    }

    // ✅ Points examen calculés = somme des points_total encodés sur les périodes de l'examen
    $place2 = implode(',', array_fill(0, count($examPeriodIds), '?'));
    $sql = "
      SELECT SUM(points_total) AS total_exam
      FROM cours_points_eleves
      WHERE classe_id = ?
        AND eleve_id  = ?
        AND cours_id  = ?
        AND anneeScolaire = ?
        AND periode_id IN ($place2)
    ";
    if ($stmt = $con->prepare($sql)) {
      $types = 'iiis' . str_repeat('i', count($examPeriodIds));
      $params = array_merge([$classeId, $eleveId, $coursFilter, $anneeToLoad], $examPeriodIds);
      bind_params($stmt, $types, $params);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      $examPointsSum = ($row && $row['total_exam'] !== null) ? (float)$row['total_exam'] : null;
    }

    // ✅ AJOUT : Charger override examen (si existe)
    $sql = "
      SELECT points_total, appreciation
      FROM cours_points_examens
      WHERE classe_id=? AND eleve_id=? AND cours_id=? AND examen_key=? AND anneeScolaire=?
      LIMIT 1
    ";
    if ($stmt = $con->prepare($sql)) {
      $stmt->bind_param('iiiss', $classeId, $eleveId, $coursFilter, $periodeExamKey, $anneeToLoad);
      $stmt->execute();
      $rowO = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($rowO) {
        $examPointsOverride = ($rowO['points_total'] !== null) ? (float)$rowO['points_total'] : null;
        $examAppOverride    = (string)($rowO['appreciation'] ?? '');
      }
    }

    // ✅ Valeur finale affichée = override si existe sinon calcul auto
    $examPointsFinal = ($examPointsOverride !== null) ? $examPointsOverride : $examPointsSum;

    $fichePoints['points_total'] = $examPointsFinal;
    $fichePoints['appreciation'] = ($examPointsOverride !== null)
      ? ("Examen (modifié manuellement). Calcul auto: ".($examPointsSum !== null ? number_format((float)$examPointsSum,2,',',' ') : '—'))
      : "Examen calculé automatiquement : somme des périodes.";

  } elseif ($periodeActifId > 0) {

    // 7.a Pondération période
    $sql = "SELECT points FROM cours_ponderations WHERE cours_id = ? AND periode_id = ? LIMIT 1";
    if ($stmt = $con->prepare($sql)) {
      $stmt->bind_param('ii', $coursFilter, $periodeActifId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row && $row['points'] !== null) $ponderationPoints = (int)$row['points'];
    }

    // 7.b Charger fiche points existante
    $sql = "
      SELECT points_total, appreciation
      FROM cours_points_eleves
      WHERE classe_id = ? AND eleve_id = ? AND cours_id = ? AND periode_id = ? AND anneeScolaire = ?
      LIMIT 1
    ";
    if ($stmt = $con->prepare($sql)) {
      $stmt->bind_param('iiiis', $classeId, $eleveId, $coursFilter, $periodeActifId, $anneeToLoad);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row) {
        $fichePoints['points_total'] = $row['points_total'] !== null ? (float)$row['points_total'] : null;
        $fichePoints['appreciation'] = (string)($row['appreciation'] ?? '');
      }
    }
  }
}

?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h5 mb-0">Dossier élève</h1>
        <a href="/prof/eleves.php" class="btn btn-sm btn-outline-secondary">← Retour à la liste</a>
    </div>

    <?php if ($err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success py-2"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if (!$err): ?>
    <?php
      $fullName   = trim(($eleve['nom']??'').' '.($eleve['postnom']??'').' '.($eleve['prenom']??''));
      $genreRaw   = strtoupper((string)($eleve['genre'] ?? ''));
      $genreLabel = $genreRaw === 'F' ? 'Fille' : ($genreRaw === 'M' ? 'Garçon' : $genreRaw);
    ?>

    <!-- Filtres Année + Période + Cours -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get">
                <input type="hidden" name="eleve_id" value="<?= (int)$eleveId ?>">

                <div class="col-md-4">
                    <label class="form-label">Année scolaire</label>
                    <select name="annee" class="form-select" onchange="this.form.submit()">
                        <option value="">Toutes les années</option>
                        <?php foreach ($anneeOptions as $an): ?>
                        <option value="<?= e($an) ?>" <?= $anneeFilter === $an ? 'selected' : '' ?>><?= e($an) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Période</label>
                    <select name="periode_id" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= ($periodeFilterRaw === '0') ? 'selected' : '' ?>>Période active</option>

                        <?php
                // ✅ Examens (si disponibles)
                $orderedIds = array_values(array_map(fn($p)=>(int)$p['id'], $periodesOptions));
                $nb = count($orderedIds);
                $hasExamT1 = ($nb >= 4);
                $hasExamT2 = ($nb >= 4);
                $hasExamT3 = ($nb >= 6);
              ?>
                        <?php if ($hasExamT1): ?>
                        <option value="EX_T1" <?= ($periodeExamKey === 'EX_T1') ? 'selected' : '' ?>>Examen — 1er
                            Trimestre</option>
                        <?php endif; ?>
                        <?php if ($hasExamT2): ?>
                        <option value="EX_T2" <?= ($periodeExamKey === 'EX_T2') ? 'selected' : '' ?>>Examen — 2e
                            Trimestre</option>
                        <?php endif; ?>
                        <?php if ($hasExamT3): ?>
                        <option value="EX_T3" <?= ($periodeExamKey === 'EX_T3') ? 'selected' : '' ?>>Examen — 3e
                            Trimestre</option>
                        <?php endif; ?>

                        <optgroup label="Périodes">
                            <?php foreach ($periodesOptions as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"
                                <?= ($periodeFilter === (int)$p['id'] && !$isExamSelected) ? 'selected' : '' ?>>
                                <?= e($p['code']) ?> —
                                <?= e($p['libelle']) ?><?= !empty($p['actif']) ? ' (active)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    <div class="form-text">
                        Période affichée : <strong><?= e($periodeActifLabel ?: '—') ?></strong>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Cours</label>
                    <select name="cours_id" class="form-select" onchange="this.form.submit()">
                        <option value="0" <?= $coursFilter === 0 ? 'selected' : '' ?>>Tous mes cours</option>
                        <?php foreach ($coursOptions as $co): ?>
                        <option value="<?= (int)$co['id'] ?>" <?= $coursFilter === (int)$co['id'] ? 'selected' : '' ?>>
                            <?= e($co['intitule']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$coursOptions): ?>
                    <div class="small text-muted mt-1">Aucun cours affecté à votre compte pour cette classe.</div>
                    <?php endif; ?>
                </div>

                <div class="col-12 text-end">
                    <?php if ($anneeFilter !== '' || $periodeFilterRaw !== '0' || $coursFilter > 0): ?>
                    <a href="?eleve_id=<?= (int)$eleveId ?>" class="btn btn-sm btn-outline-secondary">Réinitialiser</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Profil élève -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                <div>
                    <h2 class="h5 mb-1"><?= e($fullName) ?></h2>
                    <div class="text-muted small">ID : <?= (int)$eleve['id'] ?> — Classe :
                        <?= e($eleve['classe_desc'] ?? '—') ?></div>
                </div>
                <div class="text-end">
                    <?php if ($genreRaw === 'F'): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Fille</span>
                    <?php elseif ($genreRaw === 'M'): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Garçon</span>
                    <?php else: ?>
                    <span
                        class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= e($genreLabel) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 small">
                <div class="col-md-4">
                    <h6 class="text-uppercase text-muted mb-2">Identité</h6>
                    <div><strong>Date de naissance :</strong> <?= e($eleve['dateDeNaissance'] ?? '—') ?></div>
                    <div><strong>Lieu de naissance :</strong> <?= e($eleve['lieu'] ?? '—') ?></div>
                    <div><strong>Année scolaire (actuelle) :</strong> <?= e($eleve['anneeScolaire'] ?? '—') ?></div>
                </div>
                <div class="col-md-4">
                    <h6 class="text-uppercase text-muted mb-2">Infos internes</h6>
                    <div><strong>Créé le :</strong> <?= e($eleve['dateCreated'] ?? '—') ?></div>
                    <div><strong>Modifié le :</strong> <?= e($eleve['dateUpdate'] ?? '—') ?></div>
                    <div><strong>Créé par :</strong> <?= e($eleve['createdby'] ?? '—') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat globales -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Présence (global)</h2>
                        <span class="badge bg-light text-muted">Total jours : <?= (int)$presenceStats['total'] ?></span>
                    </div>
                    <?php
              $total   = max((int)$presenceStats['total'], 0);
              $present = max((int)$presenceStats['present'], 0);
              $absent  = max((int)$presenceStats['absent'], 0);
              $taux    = $total > 0 ? round(($present / $total) * 100) : 0;
            ?>
                    <?php if ($total === 0): ?>
                    <p class="text-muted small mb-0">Aucune donnée de présence n’a encore été encodée.</p>
                    <?php else: ?>
                    <ul class="list-unstyled small mb-2">
                        <li><strong>Présent :</strong> <?= $present ?> jour(s)</li>
                        <li><strong>Absent :</strong> <?= $absent ?> jour(s)</li>
                        <li><strong>Taux :</strong> <?= $taux ?> %</li>
                    </ul>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $taux ?>%;"
                            aria-valuenow="<?= $taux ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Quiz / Évaluations</h2>
                        <span class="badge bg-light text-muted">Total : <?= (int)$quizStats['total'] ?></span>
                    </div>
                    <?php if ((int)$quizStats['total'] === 0): ?>
                    <p class="text-muted small mb-0">Aucun quiz trouvé avec le filtre courant.</p>
                    <?php else: ?>
                    <ul class="list-unstyled small mb-0">
                        <li><strong>Corrigés :</strong> <?= (int)$quizStats['corrige'] ?></li>
                        <li><strong>Moyenne :</strong>
                            <?= $quizStats['moyenne'] !== null ? number_format($quizStats['moyenne'],2,',',' ') : '—' ?>
                        </li>
                        <li><strong>Max :</strong>
                            <?= $quizStats['max'] !== null ? number_format($quizStats['max'],2,',',' ') : '—' ?></li>
                        <li><strong>Min :</strong>
                            <?= $quizStats['min'] !== null ? number_format($quizStats['min'],2,',',' ') : '—' ?></li>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Cahier des cotes</h2>
                        <span class="badge bg-light text-muted">Total : <?= (int)$cahierStats['total'] ?></span>
                    </div>
                    <?php if ((int)$cahierStats['total'] === 0): ?>
                    <p class="text-muted small mb-0">Aucune ligne trouvée avec le filtre courant.</p>
                    <?php else: ?>
                    <ul class="list-unstyled small mb-0">
                        <li><strong>Avec points :</strong> <?= (int)$cahierStats['avec_note'] ?></li>
                        <li><strong>Moyenne :</strong>
                            <?= $cahierStats['moyenne'] !== null ? number_format($cahierStats['moyenne'],2,',',' ') : '—' ?>
                        </li>
                        <li><strong>Max :</strong>
                            <?= $cahierStats['max'] !== null ? number_format($cahierStats['max'],2,',',' ') : '—' ?>
                        </li>
                        <li><strong>Min :</strong>
                            <?= $cahierStats['min'] !== null ? number_format($cahierStats['min'],2,',',' ') : '—' ?>
                        </li>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Derniers quiz -->
    <div class="card shadow-sm mb-4">
        <div class="card-body table-responsive">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Derniers quiz</h2>
                <span class="text-muted small">10 plus récents</span>
            </div>
            <?php if (!$quizRecent): ?>
            <p class="text-muted small mb-0">Aucun quiz trouvé avec le filtre courant.</p>
            <?php else: ?>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Titre</th>
                        <th>Type</th>
                        <th>Format</th>
                        <th>Note</th>
                        <th>Statut</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quizRecent as $q): ?>
                    <tr>
                        <td><?= e($q['titre']) ?></td>
                        <td><?= e($q['type_quiz']) ?></td>
                        <td><?= e($q['format']) ?></td>
                        <td><?= $q['note_totale'] === null ? '<span class="text-muted small">—</span>' : number_format((float)$q['note_totale'],2,',',' ') ?>
                        </td>
                        <td>
                            <?php if ($q['statut'] === 'corrige'): ?>
                            <span
                                class="badge bg-success-subtle text-success border border-success-subtle">Corrigé</span>
                            <?php else: ?>
                            <span
                                class="badge bg-warning-subtle text-warning border border-warning-subtle"><?= e($q['statut']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($q['date_submitted']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dernières appréciations -->
    <div class="card shadow-sm mb-4">
        <div class="card-body table-responsive">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Dernières appréciations (cahier)</h2>
                <span class="text-muted small">10 plus récentes</span>
            </div>
            <?php if (!$cahierRecent): ?>
            <p class="text-muted small mb-0">Aucune ligne trouvée avec le filtre courant.</p>
            <?php else: ?>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Cours</th>
                        <th>Période</th>
                        <th>Type</th>
                        <th>Points</th>
                        <th>Remarque</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cahierRecent as $c): ?>
                    <tr>
                        <td><?= e($c['created_at']) ?></td>
                        <td><?= e($c['cours'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($c['periode_code']) || !empty($c['periode_libelle'])): ?>
                            <?= e($c['periode_code'] ?? '') ?><?= !empty($c['periode_libelle']) ? ' — '.e($c['periode_libelle']) : '' ?>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($c['type_app'] ?? '—') ?></td>
                        <td><?= $c['points'] === null ? '<span class="text-muted small">—</span>' : number_format((float)$c['points'],2,',',' ') ?>
                        </td>
                        <td><?= !empty($c['remarque']) ? nl2br(e($c['remarque'])) : '<span class="text-muted small">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- ✅ Pondération + Fiche points / Examen -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h2 class="h6 mb-0">Pondération & Fiche de points</h2>
                <span class="text-muted small">Période : <strong><?= e($periodeActifLabel ?: '—') ?></strong></span>
            </div>

            <?php if ($coursFilter <= 0): ?>
            <div class="alert alert-info py-2 mb-0">
                Sélectionne d’abord un <strong>cours</strong> dans le filtre ci-dessus pour voir la pondération et les
                points.
            </div>
            <?php else: ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 rounded border bg-light">
                        <div class="text-uppercase text-muted small mb-1">
                            Pondération <?= $isExamSelected ? 'de l’examen' : 'du cours' ?>
                        </div>
                        <div class="fs-4 fw-semibold">
                            <?= $ponderationPoints !== null ? (int)$ponderationPoints : '—' ?>
                        </div>
                        <div class="text-muted small">
                            Source : <code>cours_ponderations</code>
                            <?= $isExamSelected ? '(somme des périodes)' : '(cours + période)' ?>
                        </div>
                        <?php if ($ponderationPoints === null): ?>
                        <div class="small text-danger mt-2">
                            Pondération non définie pour cette sélection.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-8">
                    <?php if ($isExamSelected): ?>
                    <!-- ✅ FORMULAIRE EXAMEN (modifiable + enregistrable) -->
                    <form method="post" class="border rounded p-3">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="save_exam_points">
                        <input type="hidden" name="cours_id" value="<?= (int)$coursFilter ?>">
                        <input type="hidden" name="exam_key" value="<?= e($periodeExamKey) ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Points (Examen)</label>
                                <input type="text" name="points_exam" class="form-control"
                                    value="<?= $examPointsOverride !== null ? e((string)$examPointsOverride) : ($examPointsSum !== null ? e((string)$examPointsSum) : '') ?>"
                                    placeholder="<?= $examPointsSum !== null ? 'Calcul auto: '.number_format((float)$examPointsSum,2,',',' ') : 'Ex: 20' ?>">
                                <div class="form-text">
                                    Calcul auto (somme périodes) :
                                    <strong><?= $examPointsSum !== null ? e(number_format((float)$examPointsSum,2,',',' ')) : '—' ?></strong><br>
                                    Si tu modifies ici, cette valeur devient la note officielle de l’examen.
                                </div>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Appréciation (Examen)</label>
                                <textarea name="appreciation_exam" class="form-control" rows="4"
                                    placeholder="Justification / barème / remarque..."><?= e($examAppOverride ?: ($fichePoints['appreciation'] ?? '')) ?></textarea>
                                <div class="form-text">
                                    Tu peux laisser vide si tu veux juste fixer la note.
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button class="btn btn-primary" type="submit">Enregistrer l’examen</button>
                            </div>
                        </div>
                    </form>

                    <?php else: ?>
                    <!-- ✅ FORMULAIRE PÉRIODE (inchangé) -->
                    <form method="post" class="border rounded p-3">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="save_fiche_points">
                        <input type="hidden" name="cours_id" value="<?= (int)$coursFilter ?>">
                        <input type="hidden" name="periode_id" value="<?= (int)$periodeActifId ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Points (période)</label>
                                <input type="text" name="points_total" class="form-control" placeholder="Ex: 40"
                                    value="<?= $fichePoints['points_total'] !== null ? e((string)$fichePoints['points_total']) : '' ?>">
                                <div class="form-text">Total / synthèse pour le cours sur la période.</div>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Appréciation / Construction</label>
                                <textarea name="appreciation" rows="4" class="form-control"
                                    placeholder="Écris ici la synthèse / barème / remarques..."><?= e($fichePoints['appreciation'] ?? '') ?></textarea>
                                <div class="form-text">
                                    Cette note est enregistrée pour <strong>l’élève</strong>, le <strong>cours</strong>
                                    et la <strong>période</strong>.
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button class="btn btn-primary" type="submit">Enregistrer</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include __DIR__.'/../layout/footer.php'; ?>