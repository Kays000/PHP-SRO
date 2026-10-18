<?php

declare(strict_types=1);

// Главная страница: ввод имени и начало теста.

require __DIR__ . '/includes/functions.php';
start_app();

$attempt = $_SESSION['attempt'] ?? null;   // незавершённая попытка, если есть
$name = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['attempt']);
        redirect('index.php');
    }

    if ($action === 'start') {
        if ($attempt !== null) {
            redirect('test.php');
        }

        $raw = $_POST['name'] ?? '';
        if (!is_string($raw)) {
            $error = 'Имя должно быть текстом.';
        } else {
            $name = clean_text($raw);
            $error = check_name($name);
        }

        if ($error === '') {
            $_SESSION['attempt'] = new_attempt($name, load_questions());
            session_regenerate_id(true);       // новый id сессии после начала работы
            redirect('test.php?n=1');
        }
        http_response_code(422);
    }
}

$page_title = 'Начало теста';
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <h1>Тест по основам PHP</h1>
    <p>Вопросы по материалам PHP. В каждой попытке — <strong><?= e(QUESTIONS_PER_TEST) ?> случайных вопросов</strong>
       из общего банка. Есть вопросы с одним ответом, с несколькими ответами и с коротким текстовым ответом.
       Время на тест — <strong><?= e(intdiv(TIME_LIMIT, 60)) ?> мин.</strong></p>

    <?php if ($attempt !== null): ?>
        <div class="alert alert-info">
            Есть незавершённая попытка: <strong><?= e($attempt['name']) ?></strong>.
        </div>
        <div class="actions">
            <a class="btn" href="test.php?n=<?= e($attempt['pos'] ?? 1) ?>">Продолжить тест</a>
            <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-secondary" type="submit">Отменить попытку</button>
            </form>
        </div>
    <?php else: ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="start">
            <label for="name">Ваше имя</label>
            <input id="name" name="name" type="text" value="<?= e($name) ?>" maxlength="50" required>
            <?php if ($error !== ''): ?>
                <p class="field-error"><?= e($error) ?></p>
            <?php endif; ?>
            <button class="btn" type="submit">Начать тест</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
