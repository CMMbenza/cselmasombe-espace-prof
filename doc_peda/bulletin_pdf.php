<?php
// /prof/eleves/bulletin_pdf.php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/helpers.php';
require_prof();

$prof     = current_prof();
$agentId  = (int)$prof['id'];
$classeId = get_current_classe();

$eleveId = (int)($_GET['eleve_id'] ?? 0);
if ($eleveId<=0) die('Élève invalide.');

// Élève + vérif classe
$stmt = $con->prepare("SELECT e.id, e.nom, e.postnom, e.prenom, e.classe, c.description AS classe_desc
                       FROM eleve e
                       INNER JOIN classe c ON c.id=e.classe
                       WHERE e.id=?");
$stmt->bind_param('i', $eleveId);
$stmt->execute();
$eleve = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$eleve || (int)$eleve['classe'] !== (int)$classeId) die('Accès refusé.');

// Notes détaillées (tous les quiz du prof dans la classe)
$stmt = $con->prepare("
  SELECT q.titre, q.type_quiz, q.format, q.date_limite,
         qs.date_submitted, qs.statut, qs.note_totale
  FROM quiz_submission qs
  INNER JOIN quiz q ON q.id=qs.quiz_id
  WHERE qs.eleve_id=? AND q.agent_id=? AND q.classe_id=?
  ORDER BY q.created_at ASC
");
$stmt->bind_param('iii', $eleveId, $agentId, $classeId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Moyenne
$stmt = $con->prepare("
  SELECT AVG(qs.note_totale) AS avg_note
  FROM quiz_submission qs
  INNER JOIN quiz q ON q.id=qs.quiz_id
  WHERE qs.eleve_id=? AND q.agent_id=? AND q.classe_id=? AND qs.note_totale IS NOT NULL
");
$stmt->bind_param('iii', $eleveId, $agentId, $classeId);
$stmt->execute();
$avg = (float)($stmt->get_result()->fetch_assoc()['avg_note'] ?? 0);
$stmt->close();

$today = date('Y-m-d');
$html = '
<html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#222;}
  h1 { font-size: 18px; margin: 0 0 10px; }
  h2 { font-size: 14px; margin: 0 0 10px; }
  .small { color:#666; font-size: 11px; }
  table { width:100%; border-collapse: collapse; margin-top:10px; }
  th, td { border:1px solid #ccc; padding:6px; }
  th { background:#f5f5f5; text-align:left; }
  .right { text-align:right; }
</style>
</head><body>
  <h1>Bulletin provisoire</h1>
  <div class="small">Date: '.$today.'</div>
  <h2>Élève: '.e(trim(($eleve['nom']??'').' '.($eleve['postnom']??'').' '.($eleve['prenom']??''))).'</h2>
  <div>Classe: '.e($eleve['classe_desc']).'</div>
  <hr>
  <table>
    <thead>
      <tr>
        <th>Quiz</th>
        <th>Type/Format</th>
        <th>Date limite</th>
        <th>Soumis le</th>
        <th>Statut</th>
        <th class="right">Note</th>
      </tr>
    </thead>
    <tbody>';
if (!$rows) {
  $html .= '<tr><td colspan="6"><em>Aucune évaluation enregistrée.</em></td></tr>';
} else {
  foreach ($rows as $r) {
    $html .= '<tr>
      <td>'.e($r['titre']).'</td>
      <td>'.e($r['type_quiz']).' / '.e($r['format']).'</td>
      <td>'.e($r['date_limite'] ?? '').'</td>
      <td>'.e($r['date_submitted']).'</td>
      <td>'.e($r['statut']).'</td>
      <td class="right">'.(is_null($r['note_totale']) ? '—' : e((string)$r['note_totale'])).'</td>
    </tr>';
  }
}
$html .= '
    </tbody>
  </table>
  <h2>Moyenne générale: '.($avg ? e((string)round($avg,2)) : '—').'</h2>
</body></html>';

// Tenter Dompdf si installé
$dompdfOk = false;
$autoload = __DIR__.'/../../vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
  try {
    $dompdf = new Dompdf\Dompdf([
      'isRemoteEnabled' => true,
      'isHtml5ParserEnabled' => true,
      'defaultFont' => 'DejaVu Sans'
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $filename = 'bulletin_'.$eleveId.'_'.$today.'.pdf';
    $dompdf->stream($filename, ['Attachment'=>false]); // Affiche dans le navigateur
    $dompdfOk = true;
  } catch (Throwable $e) {
    $dompdfOk = false;
  }
}

if (!$dompdfOk) {
  // Fallback HTML (l’utilisateur peut faire Imprimer → Enregistrer en PDF)
  header('Content-Type: text/html; charset=utf-8');
  echo $html;
}
