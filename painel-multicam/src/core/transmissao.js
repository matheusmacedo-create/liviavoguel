// Regra do show privado: quais sites devem estar recebendo o vídeo agora.
// Usada pelo repasse de verdade (repasse.js) e pela simulação do modo demonstração.

export function saidasDesejadas({ destinos, ligados, obsAoVivo, privadoEm }) {
  if (!obsAoVivo) return new Set();
  // Em privado, só o site do privado continua recebendo, mesmo que estivesse desligado.
  if (privadoEm) return new Set(destinos.includes(privadoEm) ? [privadoEm] : []);
  return new Set(destinos.filter((id) => ligados.has(id)));
}

// Texto curto para uma saída parada, conforme o motivo.
export function motivoParado({ id, ligados, obsAoVivo, privadoEm }) {
  if (privadoEm && privadoEm !== id) return 'Pausado: privado em outro site';
  if (!ligados.has(id)) return 'Desligado';
  if (!obsAoVivo) return 'Esperando o OBS';
  return 'Parado';
}
