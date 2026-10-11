// Distribuidor de vídeo FLV: recebe o vídeo do OBS (em FLV) e entrega uma cópia para cada site.
//
// Um site que entra no meio da live precisa receber, nesta ordem: o cabeçalho FLV, os dados do vídeo
// (metadata e "sequence headers" do vídeo e do áudio) e, a partir daí, as tags começando num quadro-chave.
// O tempo de cada site recomeça do zero no quadro-chave em que ele entrou.
//
// Formato FLV: cabeçalho ("FLV", versão, flags, tamanho) + 4 bytes, depois tags:
// tipo (1 byte) | tamanho dos dados (3) | tempo (3 + 1 byte alto) | stream id (3) | dados | tamanho anterior (4)

const TAG_AUDIO = 8;
const TAG_VIDEO = 9;
const TAG_SCRIPT = 18;

export function tempoDaTag(tag) {
  return ((tag[4] << 16) | (tag[5] << 8) | tag[6]) + tag[7] * 2 ** 24;
}

export function comTempo(tag, ms) {
  const copia = Buffer.from(tag);
  const t = Math.max(0, Math.round(ms)) % 2 ** 32;
  copia[4] = (t >>> 16) & 0xff;
  copia[5] = (t >>> 8) & 0xff;
  copia[6] = t & 0xff;
  copia[7] = (t >>> 24) & 0xff;
  return copia;
}

export function infoDaTag(tag) {
  const tipo = tag[0] & 0x1f;
  const temDados = tag.length > 11 + 4 + 1;
  let chave = false;
  let sequencia = false;
  if (tipo === TAG_VIDEO && temDados) {
    const b0 = tag[11];
    const estendido = (b0 & 0x80) !== 0; // "Enhanced RTMP" (HEVC, AV1) do OBS mais novo
    const quadro = (b0 >> 4) & (estendido ? 0x07 : 0x0f);
    chave = quadro === 1;
    if (estendido) sequencia = (b0 & 0x0f) === 0;
    else sequencia = [7, 12].includes(b0 & 0x0f) && tag[12] === 0; // H.264 ou HEVC, pacote de configuração
  } else if (tipo === TAG_AUDIO && temDados) {
    sequencia = (tag[11] >> 4) === 10 && tag[12] === 0; // AAC, pacote de configuração
  }
  return { tipo, tempo: tempoDaTag(tag), chave, sequencia };
}

// Junta os pedaços que chegam do processo e devolve cabeçalho e tags inteiros.
export function criarLeitorFLV({ aoCabecalho, aoTag }) {
  let sobra = Buffer.alloc(0);
  let leuCabecalho = false;
  return (pedaco) => {
    let buf = sobra.length ? Buffer.concat([sobra, pedaco]) : pedaco;
    let pos = 0;
    if (!leuCabecalho) {
      if (buf.length < 9) { sobra = Buffer.from(buf); return; }
      if (buf.toString('latin1', 0, 3) !== 'FLV') throw new Error('O vídeo recebido não está em FLV');
      const fim = buf.readUInt32BE(5) + 4;
      if (buf.length < fim) { sobra = Buffer.from(buf); return; }
      aoCabecalho(Buffer.from(buf.subarray(0, fim)));
      leuCabecalho = true;
      pos = fim;
    }
    while (buf.length - pos >= 11) {
      const total = 11 + buf.readUIntBE(pos + 1, 3) + 4;
      if (buf.length - pos < total) break;
      aoTag(Buffer.from(buf.subarray(pos, pos + total)));
      pos += total;
    }
    sobra = Buffer.from(buf.subarray(pos));
  };
}

export function criarDistribuidorFLV() {
  let cabecalho = null;
  let metadata = null;
  let seqVideo = null;
  let seqAudio = null;
  let ler = null;
  const assinantes = new Set();

  function aoTag(tag) {
    const info = infoDaTag(tag);
    if (info.tipo === TAG_SCRIPT) metadata = tag;
    if (info.sequencia) {
      if (info.tipo === TAG_VIDEO) seqVideo = tag;
      else seqAudio = tag;
    }
    for (const a of assinantes) {
      if (a.base === null) {
        if (info.tipo !== TAG_VIDEO || !info.chave || info.sequencia) continue;
        a.base = info.tempo;
        a.escrever(cabecalho);
        for (const t of [metadata, seqVideo, seqAudio]) if (t && t !== tag) a.escrever(comTempo(t, 0));
      }
      a.escrever(comTempo(tag, info.tempo - a.base));
    }
  }

  function reiniciar() {
    cabecalho = metadata = seqVideo = seqAudio = null;
    ler = criarLeitorFLV({ aoCabecalho: (c) => { cabecalho = c; }, aoTag });
    for (const a of assinantes) a.base = null;
  }

  reiniciar();

  return {
    // Um novo envio do OBS (o OBS parou e voltou): esquece o vídeo anterior.
    reiniciar,
    alimentar: (pedaco) => ler(pedaco),
    // escrever(buffer) recebe tudo que deve ir para o site. Devolve a função para cancelar.
    assinar(escrever) {
      const a = { escrever, base: null };
      assinantes.add(a);
      return () => assinantes.delete(a);
    },
  };
}
