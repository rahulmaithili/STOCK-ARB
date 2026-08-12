const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('electronAPI', {
  linkGoogleDrive: (clientId, clientSecret) => ipcRenderer.invoke('link-google-drive', { clientId, clientSecret }),
  syncDatabaseToCloud: () => ipcRenderer.invoke('sync-database-to-cloud'),
  restoreDatabaseFromCloud: () => ipcRenderer.invoke('restore-database-from-cloud'),
  getGoogleDriveStatus: () => ipcRenderer.invoke('get-gdrive-status'),
  unlinkGoogleDrive: () => ipcRenderer.invoke('unlink-gdrive'),
  getNetworkStatus: () => ipcRenderer.invoke('get-network-status'),
  saveNetworkConfig: (config) => ipcRenderer.invoke('save-network-config', config)
});
