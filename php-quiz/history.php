<?php

declare(strict_types=1);

// История попыток текущей сессии.

require __DIR__ . '/includes/functions.php';
start_app();

$all = read_json_file(RESULTS_FILE);
$rows = [];
foreach ($_SESSION['my_results'] ?? [] as $id) {
    if (isset($all[$id])) {
        $rows[] = $all[$id];
    }
}
$rows = array_reverse($rows);   // новые сверху

$page_title = 'Мои попытки';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Мои попытки</h1>
    <?php if ($rows === []): ?>
        <p>Завершённых попыток в этой сессии пока нет.</p>
        <a class="btn" href="index.php">Пройти тест</a>
    <?php else: ?>
        <table>
            <thead>
            <tr><th>Дата</th><th>Имя</th><th>Баллы</th><th>%</th><th>Оценка</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['finished_at']) ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['score']) ?> / <?= e($r['max']) ?></td>
                    <td><?= e($r['percent']) ?></td>
                    <td><?= e($r['grade']) ?></td>
                    <td><a href="result.php?id=<?= e($r['id']) ?>">Отчёт</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
