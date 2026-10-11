// Ponte entre a tela do painel e o processo principal. A tela não tem acesso direto ao computador.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('painel', {
  aoEvento: (fn) => { ipcRenderer.on('evento', (_e, ev) => fn(ev)); },
  aoStatus: (fn) => { ipcRenderer.on('status-leitura', (_e, s) => fn(s)); },
  iniciarLeituras: () => ipcRenderer.invoke('iniciar-leituras'),
  salvarCSV: (nome, conteudo) => ipcRenderer.invoke('salvar-csv', nome, conteudo),
});
