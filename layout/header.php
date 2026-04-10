<?php
// prof/layout/header.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>Portail Professeur</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS (CDN) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .card-soft { border: 0; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,.07); }
  </style>
</head>
<body class="bg-light">
