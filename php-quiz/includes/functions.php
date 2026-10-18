<?php

declare(strict_types=1);

/*
 * Все функции приложения в одном файле.
 * Разделы: 1) настройки, 2) запуск и безопасность, 3) работа с JSON, 4) логика теста.
 */

// ====================================================================
// 1. НАСТРОЙКИ (в тестах значения можно задать заранее через define)
// ====================================================================

if (!defined('QUESTIONS_FILE')) {
    define('QUESTIONS_FILE', __DIR__ . '/../data/questions.json');
}
if (!defined('RESULTS_FILE')) {
    define('RESULTS_FILE', __DIR__ . '/../data/results.json');
}
if (!defined('QUESTIONS_PER_TEST')) {
    define('QUESTIONS_PER_TEST', 20);          // сколько вопросов в одной попытке
}
if (!defined('TIME_LIMIT')) {
    // секунд на попытку; для проверки таймера можно запустить с QUIZ_TIME_LIMIT=20
    define('TIME_LIMIT', (int) (getenv('QUIZ_TIME_LIMIT') ?: 20 * 60));
}

// ====================================================================
// 2. ЗАПУСК, БЕЗОПАСНОСТЬ
// ====================================================================

/** Запускает сессию и включает общую обработку ошибок. Вызывается первой строкой каждой страницы. */
function start_app(): void
{
    // Пользователю технические ошибки не показываем, они пишутся в лог.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(function (Throwable $e): void {
        error_log('[quiz] ' . $e->getMessage());
        show_error(500, 'Данные временно недоступны. Попробуйте позже.');
    });

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** Экранирует текст перед выводом в HTML. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Перенаправление (после обработки формы, чтобы обновление страницы не повторяло отправку). */
function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Показывает страницу с ошибкой и останавливает скрипт. */
function show_error(int $code, string $message): never
{
    http_response_code($code);
    $page_title = 'Ошибка ' . $code;
    require __DIR__ . '/header.php';
    echo '<section class="card"><h1>Ошибка ' . e($code) . '</h1><p>' . e($message) . '</p>';
    echo '<a class="btn" href="index.php">На главную</a></section>';
    require __DIR__ . '/footer.php';
    exit;
}

/** CSRF-токен текущей сессии (создаётся один раз). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

/** Сравнивает токен из формы с токеном сессии. */
function csrf_is_valid(mixed $sent): bool
{
    $expected = $_SESSION['csrf'] ?? '';

    return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
}

/** Останавливает обработку POST-запроса, если токен неверный или отсутствует. */
function check_csrf(): void
{
    if (!csrf_is_valid($_POST['csrf'] ?? null)) {
        show_error(403, 'Неверный CSRF-токен. Обновите страницу и повторите действие.');
    }
}

// ====================================================================
// 3. РАБОТА С JSON
// ====================================================================

/** Читает JSON-файл. Нет файла или он пустой — пустой массив. Повреждён — исключение. */
function read_json_file(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $text = file_get_contents($file);
    if ($text === false) {
        throw new RuntimeException('Не удалось прочитать файл ' . basename($file));
    }
    if (trim($text) === '') {
        return [];
    }
    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('Файл повреждён: ' . basename($file));
    }

    return $data;
}

/**
 * Сохраняет результат в results.json. Пока идёт запись, файл заблокирован (flock),
 * поэтому два одновременных запроса не затрут данные друг друга.
 * Ключ записи — id попытки: если такой уже есть, дубль не создаётся.
 */
function save_result(array $record): array
{
    $file = @fopen(RESULTS_FILE, 'c+');
    if ($file === false) {
        throw new RuntimeException('Нет доступа к файлу результатов (проверьте права на папку data)');
    }

    try {
        flock($file, LOCK_EX);

        $text = (string) stream_get_contents($file);
        $all = trim($text) === '' ? [] : json_decode($text, true);
        if (!is_array($all)) {
            throw new RuntimeException('Файл results.json повреждён');
        }

        if (isset($all[$record['id']])) {
            return $all[$record['id']];         // повторная отправка: возвращаем уже сохранённое
        }

        $all[$record['id']] = $record;
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($file);

        return $record;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

// ====================================================================
// 4. ЛОГИКА ТЕСТА
// ====================================================================

/** Убирает лишние пробелы по краям и внутри строки. */
function clean_text(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** Проверка имени. Возвращает текст ошибки или пустую строку, если всё верно. */
function check_name(string $name): string
{
    $length = mb_strlen($name);
    if ($length < 2 || $length > 50) {
        return 'Имя должно содержать от 2 до 50 символов.';
    }
    if (preg_match('/^[\p{L}\p{N} .\'-]+$/u', $name) !== 1) {
        return 'В имени можно использовать буквы, цифры, пробел, точку, дефис и апостроф.';
    }

    return '';
}

/** Загружает вопросы из JSON и проверяет формат. Возвращает массив [id => вопрос]. */
function load_questions(): array
{
    $list = read_json_file(QUESTIONS_FILE);
    if ($list === []) {
        throw new RuntimeException('Файл вопросов пуст');
    }

    $questions = [];
    foreach ($list as $q) {
        $ok = is_array($q) && isset($q['id'], $q['type'], $q['text'], $q['correct']) && is_int($q['id'])
            && in_array($q['type'], ['single', 'multiple', 'text'], true);

        if ($ok && $q['type'] === 'text') {
            $ok = is_array($q['correct']) && $q['correct'] !== [];
        } elseif ($ok) {
            $ok = isset($q['options']) && is_array($q['options']) && count($q['options']) >= 2;
            if ($ok && $q['type'] === 'single') {
                $ok = is_int($q['correct']) && isset($q['options'][$q['correct']]);
            } elseif ($ok) {
                $ok = is_array($q['correct']) && $q['correct'] !== []
                    && array_diff($q['correct'], array_keys($q['options'])) === [];
            }
        }

        if (!$ok) {
            throw new RuntimeException('Неверный формат вопроса в questions.json');
        }
        $questions[$q['id']] = $q;
    }

    return $questions;
}

/** Создаёт новую попытку: случайные вопросы в случайном порядке и время окончания. */
function new_attempt(string $name, array $questions): array
{
    $ids = array_keys($questions);
    shuffle($ids);

    return [
        'id'       => bin2hex(random_bytes(16)),
        'name'     => $name,
        'ids'      => array_slice($ids, 0, QUESTIONS_PER_TEST),
        'answers'  => [],
        'pos'      => 1,
        'started'  => time(),
        'deadline' => time() + TIME_LIMIT,
    ];
}

/** Номер вопроса из запроса: от 1 до $total, иначе 0. */
function question_number(mixed $raw, int $total): int
{
    if (!is_string($raw) || !ctype_digit($raw)) {
        return 0;
    }
    $n = (int) $raw;

    return ($n >= 1 && $n <= $total) ? $n : 0;
}

/**
 * Проверяет присланный ответ. Возвращает [значение, ошибка]:
 *  [null, '']      — ответ пустой (вопрос пропущен);
 *  [null, 'текст'] — ввод некорректен;
 *  [значение, '']  — ответ принят (строка или список строк-номеров вариантов).
 */
function read_answer(array $q, mixed $raw): array
{
    if ($q['type'] === 'text') {
        if ($raw === null) {
            return [null, ''];
        }
        if (!is_string($raw)) {
            return [null, 'Ответ должен быть текстом.'];
        }
        $text = clean_text($raw);
        if ($text === '') {
            return [null, ''];
        }
        if (mb_strlen($text) > 100) {
            return [null, 'Ответ слишком длинный (максимум 100 символов).'];
        }

        return [$text, ''];
    }

    $valid = array_map('strval', array_keys($q['options']));

    if ($q['type'] === 'single') {
        if ($raw === null || $raw === '') {
            return [null, ''];
        }
        if (!is_string($raw) || !in_array($raw, $valid, true)) {
            return [null, 'Выбран недопустимый вариант ответа.'];
        }

        return [$raw, ''];
    }

    // несколько вариантов
    if ($raw === null || $raw === []) {
        return [null, ''];
    }
    if (!is_array($raw)) {
        return [null, 'Ожидается список вариантов.'];
    }
    $chosen = [];
    foreach ($raw as $item) {
        if (!is_string($item) || !in_array($item, $valid, true)) {
            return [null, 'Выбран недопустимый вариант ответа.'];
        }
        $chosen[$item] = $item;
    }
    $chosen = array_values($chosen);
    sort($chosen);

    return [$chosen, ''];
}

/** Приводит текстовый ответ к сравнимому виду: без регистра, лишних пробелов и скобок (). */
function normalize_text(string $text): string
{
    $text = mb_strtolower(clean_text($text));

    return trim((string) preg_replace('/\(\s*\)$/', '', $text));
}

/** Верен ли ответ на вопрос. */
function is_correct(array $q, string|array|null $answer): bool
{
    if ($answer === null || $answer === [] || $answer === '') {
        return false;
    }

    if ($q['type'] === 'single') {
        return $answer === (string) $q['correct'];
    }

    if ($q['type'] === 'multiple') {
        if (!is_array($answer)) {
            return false;
        }
        $right = array_map('strval', $q['correct']);
        sort($right);
        sort($answer);

        return $right === $answer;
    }

    // text
    if (!is_string($answer)) {
        return false;
    }
    foreach ($q['correct'] as $accepted) {
        if (normalize_text($answer) === normalize_text((string) $accepted)) {
            return true;
        }
    }

    return false;
}

/** Ответ в виде текста для отчёта (для вопросов с вариантами — тексты вариантов). */
function answer_to_text(array $q, string|array|null $answer): string
{
    if ($answer === null || $answer === [] || $answer === '') {
        return '— нет ответа —';
    }
    if ($q['type'] === 'text') {
        return (string) $answer;
    }
    $parts = [];
    foreach ((array) $answer as $index) {
        $parts[] = $q['options'][(int) $index] ?? '?';
    }

    return implode('; ', $parts);
}

/** Правильный ответ в виде текста для отчёта. */
function correct_to_text(array $q): string
{
    if ($q['type'] === 'text') {
        return implode(' / ', $q['correct']);
    }

    return answer_to_text($q, array_map('strval', (array) $q['correct']));
}

/** Оценка по проценту правильных ответов. */
function grade_for(float $percent): string
{
    if ($percent >= 90) {
        return 'Отлично';
    }
    if ($percent >= 75) {
        return 'Хорошо';
    }
    if ($percent >= 60) {
        return 'Удовлетворительно';
    }

    return 'Неудовлетворительно';
}

/** Подсчёт итогов попытки и разбор каждого вопроса. Каждый вопрос стоит 1 балл. */
function build_result(array $questions, array $attempt, bool $timedOut): array
{
    $score = 0;
    $details = [];

    foreach ($attempt['ids'] as $id) {
        $q = $questions[$id];
        $answer = $attempt['answers'][$id] ?? null;
        $correct = is_correct($q, $answer);
        if ($correct) {
            $score++;
        }
        $details[] = [
            'text'        => $q['text'],
            'lecture'     => $q['lecture'] ?? null,
            'user'        => answer_to_text($q, $answer),
            'correct'     => correct_to_text($q),
            'is_correct'  => $correct,
            'answered'    => $answer !== null,
            'explanation' => $q['explanation'] ?? '',
        ];
    }

    $max = count($attempt['ids']);
    $percent = $max > 0 ? round($score / $max * 100, 1) : 0.0;

    return [
        'id'          => $attempt['id'],
        'name'        => $attempt['name'],
        'finished_at' => date('Y-m-d H:i:s'),
        'duration'    => max(0, time() - (int) $attempt['started']),
        'timed_out'   => $timedOut,
        'score'       => $score,
        'max'         => $max,
        'percent'     => $percent,
        'grade'       => grade_for($percent),
        'details'     => $details,
    ];
}

/** Завершает попытку: считает, сохраняет в JSON, очищает сессию и ведёт на страницу результата. */
function finish_test(array $questions, bool $timedOut): never
{
    $record = save_result(build_result($questions, $_SESSION['attempt'], $timedOut));

    unset($_SESSION['attempt']);
    $_SESSION['my_results'] = array_merge($_SESSION['my_results'] ?? [], [$record['id']]);
    $_SESSION['last_result'] = $record['id'];

    redirect('result.php?id=' . $record['id']);
}
