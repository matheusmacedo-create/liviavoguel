#!/usr/bin/env php
<?php
/**
 * Cria (ou troca a senha de) uma conta da área da modelo. Não fala com o banco: imprime o SQL para colar
 * no phpMyAdmin da Hostinger (aba SQL). A senha é pedida sem aparecer na tela e só o hash vai para o SQL.
 *
 * Uso:  php scripts/criar_conta_modelo.php <slug> <email>
 * Ex.:  php scripts/criar_conta_modelo.php livia-voguel livia@exemplo.com.br
 *
 * O slug precisa existir em api/lib/modelos.php (LV_MODELOS). Rodar de novo para o mesmo slug troca o
 * e-mail e a senha da conta existente, e reativa a conta se estiver bloqueada.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/api/lib/modelos.php';

/** Senha mínima da modelo (a mesma de LV_SENHA_MINIMA em api/lib/base.php). */
const SENHA_MINIMA = 8;

function sair_com_erro(string $mensagem): never
{
    fwrite(STDERR, $mensagem . "\n");
    exit(1);
}

/** Lê uma linha do terminal sem mostrar o que é digitado (quando o terminal permite). */
function ler_senha(string $pergunta): string
{
    fwrite(STDERR, $pergunta);
    $oculto = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN) && shell_exec('stty -echo 2>/dev/null') !== false;
    $linha = fgets(STDIN);
    if ($oculto) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    return rtrim((string) $linha, "\r\n");
}

[, $slug, $email] = $argv + [null, '', ''];
$slug = trim((string) $slug);
$email = strtolower(trim((string) $email));

if ($slug === '' || $email === '') {
    sair_com_erro("Uso: php scripts/criar_conta_modelo.php <slug> <email>\nSlugs disponíveis: " . implode(', ', array_keys(LV_MODELOS)));
}
if (!isset(LV_MODELOS[$slug])) {
    sair_com_erro("Slug \"$slug\" não existe em api/lib/modelos.php. Disponíveis: " . implode(', ', array_keys(LV_MODELOS)));
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    sair_com_erro("E-mail inválido: $email");
}

$senha = ler_senha('Senha da modelo (' . SENHA_MINIMA . '+ caracteres): ');
if (mb_strlen($senha) < SENHA_MINIMA) {
    sair_com_erro('A senha precisa de ' . SENHA_MINIMA . ' caracteres ou mais.');
}
if (ler_senha('Repita a senha: ') !== $senha) {
    sair_com_erro('As senhas não conferem.');
}

$hash = password_hash($senha, PASSWORD_DEFAULT);
$agora = gmdate('Y-m-d H:i:s');
// Os três valores já foram validados (slug da lista, e-mail válido, hash bcrypt) e nenhum tem aspas simples.
$q = static fn(string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";

fwrite(STDERR, "\nCole o SQL abaixo no phpMyAdmin (aba SQL) do banco do site:\n\n");
echo 'INSERT INTO lv_modelos_contas (slug, email, senha_hash, ativa, criado_em) VALUES ('
    . implode(', ', [$q($slug), $q($email), $q($hash), '1', $q($agora)]) . ")\n"
    . "  ON DUPLICATE KEY UPDATE email = VALUES(email), senha_hash = VALUES(senha_hash), ativa = 1;\n";
fwrite(STDERR, "\nDepois, a modelo entra em /modelo-entrar.html com $email e a senha que você digitou.\n");
