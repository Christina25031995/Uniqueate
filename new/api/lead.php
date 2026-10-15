<?php
// Заявка с сайта UniKate → письмо на почту Кати + резервная копия на хостинге (Россия, 152-ФЗ).
// Секретов нет: письмо уходит через mail() хостинга reg.ru. Для доставки нужен SPF домена.
declare(strict_types=1);

const TO   = 'unikatemarketing@yandex.com';
const FROM = 'no-reply@unikate-marketing.ru';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $data): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function clean(string $k, int $max = 120): string {
  $v = trim((string)($_POST[$k] ?? ''));
  $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
  return mb_substr($v, 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'method']);

// бот заполнил скрытое поле → делаем вид, что всё хорошо
if (clean('website') !== '') out(200, ['ok' => true]);

$name  = clean('name');
$phone = clean('phone', 40);
$email = clean('email');
$tg    = clean('tg', 60);
$pd    = !empty($_POST['pd']);
$ads   = !empty($_POST['ads']);

if ($name === '' || $tg === '' || !preg_match('/\d{5,}/', preg_replace('/\D/', '', $phone) ?? '')
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$pd) {
  out(422, ['ok' => false, 'error' => 'fields']);
}

$dir = __DIR__ . '/_private';
if (!is_dir($dir)) @mkdir($dir, 0700, true);

// не больше 5 заявок с одного IP за 10 минут
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rl = $dir . '/rl-' . substr(hash('sha256', $ip . date('YmdH') . floor((int)date('i') / 10)), 0, 16);
$n = (int)@file_get_contents($rl);
if ($n >= 5) out(429, ['ok' => false, 'error' => 'rate']);
@file_put_contents($rl, (string)($n + 1));
foreach (glob($dir . '/rl-*') ?: [] as $f) if (filemtime($f) < time() - 1200) @unlink($f);

date_default_timezone_set('Europe/Moscow');
$when = date('d.m.Y H:i');
$text = "Новая заявка с сайта unikate-marketing.ru\n\n"
      . "Имя: $name\nТелефон: $phone\nПочта: $email\nTelegram: $tg\n"
      . "Согласие на обработку ПДн: да\nСогласие на рассылку: " . ($ads ? 'да' : 'нет') . "\n"
      . "Время: $when (МСК)\n";

// резервная копия: если письмо потеряется, заявка останется на хостинге
$log = $dir . '/leads-' . date('Y-m') . '.php';
$row = json_encode(['t' => $when, 'name' => $name, 'phone' => $phone, 'email' => $email, 'tg' => $tg, 'ads' => $ads], JSON_UNESCAPED_UNICODE);
$saved = @file_put_contents($log, (is_file($log) ? '' : "<?php exit; ?>\n") . $row . "\n", FILE_APPEND | LOCK_EX) !== false;

$headers = 'From: =?UTF-8?B?' . base64_encode('Сайт UniKate') . '?= <' . FROM . ">\r\n"
         . "Reply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
$subject = '=?UTF-8?B?' . base64_encode("Заявка с сайта: $name") . '?=';
$sent = @mail(TO, $subject, $text, $headers, '-f' . FROM);

if (!$sent && !$saved) out(500, ['ok' => false, 'error' => 'send']);
out(200, ['ok' => true]);
