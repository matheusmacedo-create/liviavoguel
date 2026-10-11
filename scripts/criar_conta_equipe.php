<?php
/**
 * Cria (ou troca a senha de) uma conta do painel da equipe (equipe.html). Não fala com o banco: imprime o SQL para colar
 * no phpMyAdmin da Hostinger (aba SQL). A senha é pedida sem aparecer na tela e só o hash vai para o SQL.
 *
 * Uso:  php scripts/criar_conta_equipe.php <email>
 * Rodar de novo para o mesmo e-mail troca a senha e reativa a conta. Para tirar o acesso de alguém:
 *   UPDATE lv_equipe_contas SET ativa = 0 WHERE email = '<email>';
 */
declare(strict_types=1);

// Só pela linha de comando: se o arquivo for aberto pelo navegador, não faz nada.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Mais longa que a da modelo: a conta da equipe paga saques e bloqueia contas. */
const SENHA_MINIMA = 12;

function sair_com_erro(string $mensagem): never
{
    fwrite(STDERR, $mensagem . "\n");
    exit(1);
}

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

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    sair_com_erro("Uso: php scripts/criar_conta_equipe.php <email>");
}
$senha = ler_senha('Senha (' . SENHA_MINIMA . '+ caracteres): ');
if (mb_strlen($senha) < SENHA_MINIMA) {
    sair_com_erro('A senha precisa de ' . SENHA_MINIMA . ' caracteres ou mais.');
}
if (ler_senha('Repita a senha: ') !== $senha) {
    sair_com_erro('As senhas não conferem.');
}
$q = static fn(string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
fwrite(STDERR, "\nCole o SQL abaixo no phpMyAdmin (aba SQL) do banco do site:\n\n");
echo 'INSERT INTO lv_equipe_contas (email, senha_hash, ativa, criado_em) VALUES ('
    . implode(', ', [$q($email), $q(password_hash($senha, PASSWORD_DEFAULT)), '1', $q(gmdate('Y-m-d H:i:s'))]) . ")\n"
    . "  ON DUPLICATE KEY UPDATE senha_hash = VALUES(senha_hash), ativa = 1;\n";
fwrite(STDERR, "\nDepois, entre em /equipe.html com $email e a senha que você digitou.\n");
