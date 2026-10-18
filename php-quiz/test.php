<?php

declare(strict_types=1);

// Страница вопроса: показывает вопрос, принимает ответ, ведёт к следующему или завершает тест.

require __DIR__ . '/includes/functions.php';
start_app();

// Нет активной попытки — отправляем на главную (или на результат, если тест уже завершён).
if (!isset($_SESSION['attempt'])) {
    if (isset($_SESSION['last_result'])) {
        redirect('result.php?id=' . $_SESSION['last_result']);
    }
    redirect('index.php');
}

$questions = load_questions();
$attempt = $_SESSION['attempt'];

// Время вышло — завершаем тест автоматически (проверка на сервере).
if (time() >= $attempt['deadline']) {
    finish_test($questions, true);
}

$total = count($attempt['ids']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $n = question_number($_POST['n'] ?? null, $total);
    $button = $_POST['button'] ?? '';
    if ($n === 0 || !in_array($button, ['prev', 'next', 'save', 'finish'], true)) {
        show_error(400, 'Некорректный запрос.');
    }

    $question = $questions[$attempt['ids'][$n - 1]];
    [$value, $error] = read_answer($question, $_POST['answer'] ?? null);

    if ($error === '') {
        if ($value === null) {
            unset($attempt['answers'][$question['id']]);
        } else {
            $attempt['answers'][$question['id']] = $value;
        }

        if ($button === 'finish') {
            $_SESSION['attempt'] = $attempt;
            finish_test($questions, false);
        }

        $target = match ($button) {
            'prev'  => max(1, $n - 1),
            'next'  => min($total, $n + 1),
            default => $n,
        };
        $attempt['pos'] = $target;
        $_SESSION['attempt'] = $attempt;
        redirect('test.php?n=' . $target);      // после POST всегда редирект (PRG)
    }

    http_response_code(422);    // ошибка ввода: покажем тот же вопрос с сообщением
} else {
    $n = question_number($_GET['n'] ?? null, $total);
    if ($n === 0) {
        $n = (int) $attempt['pos'];
    }
    $_SESSION['attempt']['pos'] = $n;
    $question = $questions[$attempt['ids'][$n - 1]];
}

$saved = $attempt['answers'][$question['id']] ?? null;
$answeredCount = count($attempt['answers']);
$left = max(0, $attempt['deadline'] - time());

$hints = [
    'single'   => 'Выберите один вариант ответа.',
    'multiple' => 'Выберите все верные варианты.',
    'text'     => 'Введите короткий ответ (одно слово или имя функции).',
];

$page_title = "Вопрос $n из $total";
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="quiz-head">
        <div>
            <span class="muted"><?= e($attempt['name']) ?></span>
            <progress max="<?= e($total) ?>" value="<?= e($answeredCount) ?>"></progress>
            <span class="muted">Отвечено: <?= e($answeredCount) ?> из <?= e($total) ?></span>
        </div>
        <div class="timer" id="timer" data-left="<?= e($left) ?>">
            Осталось: <span id="timer-value"><?= e(sprintf('%02d:%02d', intdiv($left, 60), $left % 60)) ?></span>
        </div>
    </div>

    <ol class="pager">
        <?php foreach ($attempt['ids'] as $i => $id): ?>
            <li>
                <a href="test.php?n=<?= e($i + 1) ?>"
                   class="pill<?= isset($attempt['answers'][$id]) ? ' pill-done' : '' ?><?= $i + 1 === $n ? ' pill-current' : '' ?>"><?= e($i + 1) ?></a>
            </li>
        <?php endforeach; ?>
    </ol>

    <h1>Вопрос <?= e($n) ?> из <?= e($total) ?></h1>

    <form method="post" action="test.php" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="n" value="<?= e($n) ?>">

        <fieldset>
            <legend><?= e($question['text']) ?></legend>
            <p class="muted"><?= e($hints[$question['type']]) ?></p>

            <?php if ($question['type'] === 'single'): ?>
                <?php foreach ($question['options'] as $i => $option): ?>
                    <label class="option">
                        <input type="radio" name="answer" value="<?= e($i) ?>" <?= $saved === (string) $i ? 'checked' : '' ?>>
                        <span><?= e($option) ?></span>
                    </label>
                <?php endforeach; ?>

            <?php elseif ($question['type'] === 'multiple'): ?>
                <?php foreach ($question['options'] as $i => $option): ?>
                    <label class="option">
                        <input type="checkbox" name="answer[]" value="<?= e($i) ?>"
                            <?= is_array($saved) && in_array((string) $i, $saved, true) ? 'checked' : '' ?>>
                        <span><?= e($option) ?></span>
                    </label>
                <?php endforeach; ?>

            <?php else: ?>
                <input type="text" name="answer" value="<?= e(is_string($saved) ? $saved : '') ?>"
                       maxlength="100" autocomplete="off" aria-label="Ваш ответ">
            <?php endif; ?>
        </fieldset>

        <?php if ($error !== ''): ?>
            <p class="field-error"><?= e($error) ?></p>
        <?php endif; ?>

        <div class="actions">
            <?php if ($n > 1): ?>
                <button class="btn btn-secondary" type="submit" name="button" value="prev">← Назад</button>
            <?php endif; ?>
            <button class="btn btn-secondary" type="submit" name="button" value="save">Сохранить</button>
            <?php if ($n < $total): ?>
                <button class="btn" type="submit" name="button" value="next">Далее →</button>
            <?php endif; ?>
            <button class="btn btn-danger" type="submit" name="button" value="finish"
                    data-confirm="Завершить тест и получить результат? Вернуться к вопросам будет нельзя.">Завершить тест</button>
        </div>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
