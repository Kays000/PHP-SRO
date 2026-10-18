<?php

declare(strict_types=1);

// Страница результата: итоговый балл и разбор ответов.

require __DIR__ . '/includes/functions.php';
start_app();

$id = $_GET['id'] ?? ($_SESSION['last_result'] ?? '');
if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
    redirect('index.php');
}

// Чужие результаты смотреть нельзя: id своих попыток хранятся в сессии.
if (!in_array($id, $_SESSION['my_results'] ?? [], true)) {
    show_error(403, 'Нет доступа к этому результату.');
}

$all = read_json_file(RESULTS_FILE);
if (!isset($all[$id])) {
    show_error(404, 'Результат не найден.');
}
$result = $all[$id];

$page_title = 'Результат теста';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Результат теста</h1>
    <p class="muted">
        <?= e($result['name']) ?> · <?= e($result['finished_at']) ?>
        · время: <?= e(sprintf('%d:%02d', intdiv($result['duration'], 60), $result['duration'] % 60)) ?>
        <?php if ($result['timed_out']): ?>· <strong>завершён по времени</strong><?php endif; ?>
    </p>

    <div class="score <?= $result['percent'] >= 60 ? 'score-pass' : 'score-fail' ?>">
        <div class="score-main"><?= e($result['score']) ?> / <?= e($result['max']) ?></div>
        <div><?= e($result['percent']) ?>% — <strong><?= e($result['grade']) ?></strong></div>
    </div>

    <h2>Разбор ответов</h2>
    <?php foreach ($result['details'] as $i => $d): ?>
        <article class="review <?= $d['is_correct'] ? 'review-ok' : 'review-bad' ?>">
            <h3><?= e($i + 1) ?>. <?= e($d['text']) ?></h3>
            <p>
                <span class="badge"><?= $d['is_correct'] ? 'Верно' : ($d['answered'] ? 'Неверно' : 'Нет ответа') ?></span>
                <?php if ($d['lecture'] !== null): ?><span class="muted">Лекция <?= e($d['lecture']) ?></span><?php endif; ?>
            </p>
            <p><strong>Ваш ответ:</strong> <?= e($d['user']) ?></p>
            <?php if (!$d['is_correct']): ?>
                <p><strong>Правильный ответ:</strong> <?= e($d['correct']) ?></p>
            <?php endif; ?>
            <?php if ($d['explanation'] !== ''): ?>
                <p class="muted"><?= e($d['explanation']) ?></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <div class="actions">
        <a class="btn" href="index.php">Пройти ещё раз</a>
        <a class="btn btn-secondary" href="history.php">Мои попытки</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
