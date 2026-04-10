<?php
// prof/includes/auth.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__.'/db.php';
require_once __DIR__.'/helpers.php';

/** Exige qu'un professeur soit connecté */
function require_prof(): void {
  if (empty($_SESSION['prof'])) {
    redirect('/prof/login.php');
  }
}

/** Retourne l'agent (prof) courant depuis la session */
function current_prof(): ?array {
  return $_SESSION['prof'] ?? null;
}

/** Définit la classe courante en session */
function set_current_classe(int $classeId): void {
  $_SESSION['classe_id'] = $classeId;
}

/** Récupère la classe courante depuis la session */
function get_current_classe(): ?int {
  return $_SESSION['classe_id'] ?? null;
}

/** Renvoie les classes affectées à l’agent (id, description, cycle_id, cycle_desc) */
function classes_of_agent(mysqli $con, int $agentId): array {
  $sql = "SELECT 
    c.id, 
    c.description,
    cy.description AS cycle_desc
FROM affectation_prof_classe apc
JOIN classe c ON c.id = apc.classe_id
LEFT JOIN cycle cy ON cy.id = c.cycle
WHERE apc.agent_id = ?
GROUP BY c.id
ORDER BY c.description ASC";
  $stmt = $con->prepare($sql);
  $stmt->bind_param('i', $agentId);
  $stmt->execute();
  $res = $stmt->get_result();
  return $res->fetch_all(MYSQLI_ASSOC);
}

/** Détails complets de la classe courante (classe + cycle) */
function current_classe_meta(mysqli $con, ?int $classeId): ?array {
  if (!$classeId) return null;
  $sql = "SELECT c.id, c.description, cy.id AS cycle_id, cy.description AS cycle_desc
          FROM classe c
          JOIN cycle cy ON cy.id=c.cycle
          WHERE c.id=?";
  $stmt = $con->prepare($sql);
  $stmt->bind_param('i', $classeId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}