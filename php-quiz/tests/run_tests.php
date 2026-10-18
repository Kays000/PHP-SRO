<?php

declare(strict_types=1);

/*
 * Автоматические тесты функций приложения.
 * Запуск из папки проекта: php tests/run_tests.php
 * Тесты работают с временными файлами и не трогают реальные данные.
 */

$tmp = sys_get_temp_dir() . '/quiz-tests-' . bin2hex(random_bytes(4));
mkdir($tmp);
copy(__DIR__ . '/../data/questions.json', $tmp . '/questions.json');

define('QUESTIONS_FILE', $tmp . '/questions.json');
define('RESULTS_FILE', $tmp . '/results.json');
define('QUESTIONS_PER_TEST', 20);
define('TIME_LIMIT', 1200);

require __DIR__ . '/../includes/functions.php';

$total = 0;
$failed = 0;

function check(string $name, bool $condition): void
{
    global $total, $failed;
    $total++;
    if ($condition) {
        echo "  ok    $name\n";
    } else {
        $failed++;
        echo "  ОШИБКА $name\n";
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable) {
        return true;
    }

    return false;
}

$questions = load_questions();
// Берём по одному вопросу каждого типа из банка.
$single = $multiple = $text = null;
foreach ($questions as $q) {
    $single ??= $q['type'] === 'single' ? $q : null;
    $multiple ??= $q['type'] === 'multiple' ? $q : null;
    $text ??= $q['type'] === 'text' ? $q : null;
}

echo "Банк вопросов\n";
check('в банке не меньше 40 вопросов', count($questions) >= 40);
check('все 8 лекций представлены', count(array_unique(array_column($questions, 'lecture'))) === 8);

echo "Имя (нормализация и валидация)\n";
check('лишние пробелы убираются', clean_text("  Иван   Петров ") === 'Иван Петров');
check('нормальное имя', check_name('Иван Петров') === '');
check('пустое имя', check_name('') !== '');
check('1 символ — ошибка', check_name('А') !== '');
check('2 символа — верно', check_name('Ан') === '');
check('50 символов — верно', check_name(str_repeat('а', 50)) === '');
check('51 символ — ошибка', check_name(str_repeat('а', 51)) !== '');
check('HTML в имени отклоняется', check_name('<script>alert(1)</script>') !== '');

echo "Проверка присланного ответа\n";
check('single: верный номер варианта', read_answer($single, '0') === ['0', '']);
check('single: пустой ответ = пропуск', read_answer($single, '') === [null, '']);
check('single: ключа нет = пропуск', read_answer($single, null) === [null, '']);
check('single: массив вместо строки', read_answer($single, ['0'])[1] !== '');
check('single: несуществующий вариант', read_answer($single, '99')[1] !== '');
check('multiple: список вариантов', read_answer($multiple, ['1', '0']) === [['0', '1'], '']);
check('multiple: строка вместо массива', read_answer($multiple, '1')[1] !== '');
check('multiple: неверный вариант', read_answer($multiple, ['0', 'x'])[1] !== '');
check('multiple: пустой список = пропуск', read_answer($multiple, []) === [null, '']);
check('text: пробелы убираются', read_answer($text, '  echo  ') === ['echo', '']);
check('text: только пробелы = пропуск', read_answer($text, '   ') === [null, '']);
check('text: массив вместо строки', read_answer($text, ['x'])[1] !== '');
check('text: 100 символов — верно', read_answer($text, str_repeat('a', 100))[1] === '');
check('text: 101 символ — ошибка', read_answer($text, str_repeat('a', 101))[1] !== '');

echo "Проверка правильности ответа\n";
check('single верно', is_correct($single, (string) $single['correct']));
check('single неверно', !is_correct($single, (string) (($single['correct'] + 1) % count($single['options']))));
check('нет ответа = неверно', !is_correct($single, null));
$right = array_map('strval', $multiple['correct']);
check('multiple: все верные', is_correct($multiple, $right));
check('multiple: не все верные', !is_correct($multiple, array_slice($right, 1)));
check('multiple: лишний вариант', !is_correct($multiple, array_map('strval', array_keys($multiple['options']))));
$word = $text['correct'][0];
check('text: верно', is_correct($text, $word));
check('text: другой регистр и пробелы', is_correct($text, '  ' . strtoupper($word) . ' '));
check('text: со скобками вызова', is_correct($text, $word . '()'));
check('text: неверно', !is_correct($text, 'abracadabra'));

echo "Итоги и оценка\n";
$attempt = new_attempt('Тест', $questions);
check('в попытке 20 вопросов', count($attempt['ids']) === 20);
check('вопросы в попытке не повторяются', count(array_unique($attempt['ids'])) === 20);
$answers = [];
foreach ($attempt['ids'] as $id) {
    $q = $questions[$id];
    $answers[$id] = $q['type'] === 'text' ? $q['correct'][0]
        : ($q['type'] === 'single' ? (string) $q['correct'] : array_map('strval', $q['correct']));
}
$attempt['answers'] = $answers;
$perfect = build_result($questions, $attempt, false);
check('все верно: 20 из 20', $perfect['score'] === 20 && $perfect['max'] === 20);
check('все верно: 100% и «Отлично»', $perfect['percent'] === 100.0 && $perfect['grade'] === 'Отлично');
$attempt['answers'] = [];
$empty = build_result($questions, $attempt, false);
check('нет ответов: 0 баллов', $empty['score'] === 0 && $empty['grade'] === 'Неудовлетворительно');
check('в отчёте есть все вопросы', count($perfect['details']) === 20);
check('90% → Отлично', grade_for(90.0) === 'Отлично');
check('89.9% → Хорошо', grade_for(89.9) === 'Хорошо');
check('75% → Хорошо', grade_for(75.0) === 'Хорошо');
check('74.9% → Удовлетворительно', grade_for(74.9) === 'Удовлетворительно');
check('60% → Удовлетворительно', grade_for(60.0) === 'Удовлетворительно');
check('59.9% → Неудовлетворительно', grade_for(59.9) === 'Неудовлетворительно');

echo "Номер вопроса\n";
check('1 и 20 допустимы', question_number('1', 20) === 1 && question_number('20', 20) === 20);
check('0, 21, -1, abc отклоняются', question_number('0', 20) === 0 && question_number('21', 20) === 0
    && question_number('-1', 20) === 0 && question_number('abc', 20) === 0);
check('массив вместо строки отклоняется', question_number(['1'], 20) === 0);

echo "Хранение в JSON\n";
$attempt['answers'] = $answers;
$record = build_result($questions, $attempt, false);
save_result($record);
save_result($record);
check('повторное сохранение не создаёт дубль', count(read_json_file(RESULTS_FILE)) === 1);
$second = $record;
$second['id'] = bin2hex(random_bytes(16));
save_result($second);
check('новая попытка добавляется, старая остаётся', count(read_json_file(RESULTS_FILE)) === 2);
file_put_contents($tmp . '/broken.json', '{ не json');
check('повреждённый JSON → исключение', throws(fn() => read_json_file($tmp . '/broken.json')));
file_put_contents(RESULTS_FILE, '{ повреждено');
check('save_result с повреждённым файлом → исключение', throws(fn() => save_result($record)));
check('повреждённый файл не перезаписан', file_get_contents(RESULTS_FILE) === '{ повреждено');
file_put_contents(QUESTIONS_FILE, '[]');
check('пустой файл вопросов → исключение', throws(fn() => load_questions()));
file_put_contents(QUESTIONS_FILE, '[{"id":1,"type":"single","text":"?","options":["a","b"],"correct":7}]');
check('правильный ответ вне вариантов → исключение', throws(fn() => load_questions()));

echo "CSRF и экранирование\n";
$_SESSION = [];
$token = csrf_token();
check('токен: 64 hex-символа', preg_match('/^[a-f0-9]{64}$/', $token) === 1);
check('токен не меняется в сессии', csrf_token() === $token);
check('верный токен принят', csrf_is_valid($token));
check('неверный токен отклонён', !csrf_is_valid('0' . substr($token, 1)));
check('отсутствие токена отклонено', !csrf_is_valid(null));
check('токен-массив отклонён', !csrf_is_valid([$token]));
check('e() экранирует HTML', e('<b onclick="x">\'</b>') === '&lt;b onclick=&quot;x&quot;&gt;&#039;&lt;/b&gt;');

echo "\nИтого: " . ($total - $failed) . " из $total проверок пройдено\n";
exit($failed === 0 ? 0 : 1);
