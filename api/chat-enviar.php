<?php
/**
 * POST { modelo, texto } → { ok, mensagem }. Exige login e a modelo ao vivo. Até LV_CHAT_MAX caracteres; palavra
 * bloqueada pela modelo → 422; mais de uma mensagem a cada LV_CHAT_INTERVALO s ou de LV_CHAT_POR_MINUTO por minuto → 429.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/base.php';
require_once __DIR__ . '/lib/modelo-conta.php';
require_once __DIR__ . '/lib/chat.php';

$b = lv_ler_json();
$usuarioId = lv_exigir_login();
$slug = is_string($b['modelo'] ?? null) ? $b['modelo'] : '';
if (!isset(LV_MODELOS[$slug])) {
    lv_falhar(422, 'Modelo não encontrada.');
}
if (!(lv_ao_vivo_todas()[$slug]['online'] ?? false)) {
    lv_falhar(409, LV_MODELOS[$slug]['nome'] . ' está offline agora.');
}
$texto = lv_texto(is_string($b['texto'] ?? null) ? $b['texto'] : '', 1000);
if ($texto === '' || mb_strlen($texto) > LV_CHAT_MAX) {
    lv_falhar(422, 'A mensagem precisa ter de 1 a ' . LV_CHAT_MAX . ' caracteres.', ['campo' => 'texto']);
}
if (lv_chat_bloqueada($slug, $texto) !== null) {
    lv_falhar(422, LV_MODELOS[$slug]['nome'] . ' bloqueou uma das palavras dessa mensagem.', ['campo' => 'texto']);
}
$chave = 'u:' . $usuarioId;
if (lv_contar_eventos('chat', $chave, LV_CHAT_INTERVALO) > 0 || lv_contar_eventos('chat', $chave, 60) >= LV_CHAT_POR_MINUTO) {
    lv_falhar(429, 'Calma! Espere um pouquinho antes de mandar outra mensagem.');
}
$usuario = lv_usuario($usuarioId) ?? lv_falhar(401, 'Entre na sua conta para continuar.', ['login' => true]);
lv_registrar('chat', $chave);
$id = lv_chat_postar($slug, $usuarioId, (string) $usuario['apelido'], 'msg', $texto);
lv_json(['ok' => true, 'mensagem' => ['id' => $id, 'tipo' => 'msg', 'apelido' => $usuario['apelido'], 'texto' => $texto, 'creditos' => null, 'data' => gmdate('Y-m-d\TH:i:s\Z')]]);
