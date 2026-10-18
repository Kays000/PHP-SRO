<?php
/** Верх страницы. Перед подключением задайте $page_title. */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title ?? 'Тест') ?> — Тест по PHP</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar-inner">
        <a class="brand" href="index.php">Тест по PHP</a>
        <nav>
            <a href="index.php">Главная</a>
            <a href="history.php">Мои попытки</a>
        </nav>
    </div>
</header>
<main class="wrap">
