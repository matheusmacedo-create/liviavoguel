// Ponte entre a tela do painel e o processo principal. A tela não tem acesso direto ao computador.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('painel', {
  aoEvento: (fn) => { ipcRenderer.on('evento', (_e, ev) => fn(ev)); },
  aoStatus: (fn) => { ipcRenderer.on('status-leitura', (_e, s) => fn(s)); },
  iniciarLeituras: () => ipcRenderer.invoke('iniciar-leituras'),
  salvarCSV: (nome, conteudo) => ipcRenderer.invoke('salvar-csv', nome, conteudo),
  transmissao: {
    info: () => ipcRenderer.invoke('transmissao-info'),
    aoMudar: (fn) => { ipcRenderer.on('transmissao', (_e, estado) => fn(estado)); },
    privado: (site) => ipcRenderer.invoke('transmissao-comando', 'privado', site ?? null),
    ligar: (site, valor) => ipcRenderer.invoke('transmissao-comando', 'ligar', site, valor),
    ligarTodos: (valor) => ipcRenderer.invoke('transmissao-comando', 'todos', null, valor),
  },
});
