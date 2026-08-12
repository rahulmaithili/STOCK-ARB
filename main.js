const { app, BrowserWindow, dialog, ipcMain } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const fs = require('fs');
const https = require('https');
const http = require('http');
const url = require('url');
const AdmZip = require('adm-zip');
const { google } = require('googleapis');

let mainWindow;
let phpServerProcess;

// Get local app version from package.json
const localVersion = require('./package.json').version;

// Google Drive Config path
const gdriveConfigPath = path.join(app.getPath('userData'), 'gdrive-config.json');

function startPhpServer() {
  const phpBinaryPath = path.join(__dirname, 'php', 'php.exe');
  const phpAppPath = path.join(__dirname, 'php-app');
  const port = 54321;

  console.log(`Starting PHP server from: ${phpBinaryPath}`);
  
  phpServerProcess = spawn(phpBinaryPath, [
    '-S', `127.0.0.1:${port}`,
    '-t', phpAppPath
  ], {
    cwd: phpAppPath,
    stdio: 'ignore'
  });

  phpServerProcess.on('error', (err) => {
    console.error('Failed to start PHP server:', err);
  });
}

// ----------------------------------------------------
// Google Drive Sync Engine & Authentication Handlers
// ----------------------------------------------------

function getGDriveConfig() {
  if (fs.existsSync(gdriveConfigPath)) {
    try {
      return JSON.parse(fs.readFileSync(gdriveConfigPath, 'utf8'));
    } catch (e) {
      console.error('Failed to read gdrive config:', e);
    }
  }
  return { linked: false, credentials: null, tokens: null, email: '', lastSync: '' };
}

function saveGDriveConfig(config) {
  fs.writeFileSync(gdriveConfigPath, JSON.stringify(config, null, 2), 'utf8');
}

// Get Google OAuth Client instance
function getOAuthClient(credentials) {
  return new google.auth.OAuth2(
    credentials.clientId,
    credentials.clientSecret,
    'http://localhost:54322/'
  );
}

ipcMain.handle('get-gdrive-status', async () => {
  return getGDriveConfig();
});

ipcMain.handle('unlink-gdrive', async () => {
  if (fs.existsSync(gdriveConfigPath)) {
    fs.unlinkSync(gdriveConfigPath);
  }
  return { success: true };
});

ipcMain.handle('link-google-drive', async (event, { clientId, clientSecret }) => {
  return new Promise((resolve) => {
    const credentials = { clientId, clientSecret };
    const oauth2Client = getOAuthClient(credentials);

    const authUrl = oauth2Client.generateAuthUrl({
      access_type: 'offline',
      scope: ['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/userinfo.email'],
      prompt: 'consent'
    });

    let authWindow = new BrowserWindow({
      width: 600,
      height: 700,
      show: true,
      title: 'Sign in with Google',
      webPreferences: { nodeIntegration: false, contextIsolation: true }
    });

    authWindow.loadURL(authUrl);

    // Start local server to capture redirect code
    const server = http.createServer(async (req, res) => {
      const query = url.parse(req.url, true).query;
      if (query.code) {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(`
          <html>
            <body style="font-family:sans-serif; text-align:center; padding-top:50px; background:#f8fafc; color:#1e293b;">
              <h2 style="color:#10b981;">Authentication Successful!</h2>
              <p>You can close this window now and return to the application.</p>
            </body>
          </html>
        `);

        try {
          const { tokens } = await oauth2Client.getToken(query.code);
          oauth2Client.setCredentials(tokens);

          // Fetch user profile email
          const oauth2 = google.oauth2({ version: 'v2', auth: oauth2Client });
          const userInfo = await oauth2.userinfo.get();

          const config = {
            linked: true,
            credentials,
            tokens,
            email: userInfo.data.email,
            lastSync: ''
          };
          saveGDriveConfig(config);

          setTimeout(() => {
            if (!authWindow.isDestroyed()) authWindow.close();
          }, 1000);

          resolve({ success: true, email: userInfo.data.email });
        } catch (err) {
          resolve({ success: false, error: err.message });
        } finally {
          server.close();
        }
      } else {
        res.writeHead(400, { 'Content-Type': 'text/html' });
        res.end('Authorization code missing.');
        server.close();
        resolve({ success: false, error: 'Authorization code missing' });
      }
    });

    server.listen(54322, '127.0.0.1');

    authWindow.on('closed', () => {
      server.close();
      resolve({ success: false, error: 'User closed authentication window' });
    });
  });
});

ipcMain.handle('sync-database-to-cloud', async () => {
  const config = getGDriveConfig();
  if (!config.linked || !config.tokens) {
    return { success: false, error: 'Google Drive is not linked.' };
  }

  try {
    const oauth2Client = getOAuthClient(config.credentials);
    oauth2Client.setCredentials(config.tokens);

    // Handle token refresh automatically
    oauth2Client.on('tokens', (newTokens) => {
      config.tokens = { ...config.tokens, ...newTokens };
      saveGDriveConfig(config);
    });

    const drive = google.drive({ version: 'v3', auth: oauth2Client });
    const dbPath = path.join(__dirname, 'php-app', 'database.db');

    if (!fs.existsSync(dbPath)) {
      return { success: false, error: 'Local database.db file not found!' };
    }

    // 1. Search or Create folder "StockARB Backups"
    let folderId = '';
    const folderRes = await drive.files.list({
      q: "mimeType='application/vnd.google-apps.folder' and name='StockARB Backups' and trashed=false",
      fields: 'files(id)'
    });

    if (folderRes.data.files.length > 0) {
      folderId = folderRes.data.files[0].id;
    } else {
      const folderMetadata = {
        name: 'StockARB Backups',
        mimeType: 'application/vnd.google-apps.folder'
      };
      const newFolder = await drive.files.create({
        resource: folderMetadata,
        fields: 'id'
      });
      folderId = newFolder.data.id;
    }

    // 2. Search or Create file "stock_backup_gdrive.db" inside that folder
    let fileId = '';
    const fileRes = await drive.files.list({
      q: `name='stock_backup_gdrive.db' and '${folderId}' in parents and trashed=false`,
      fields: 'files(id)'
    });

    const media = {
      mimeType: 'application/octet-stream',
      body: fs.createReadStream(dbPath)
    };

    if (fileRes.data.files.length > 0) {
      fileId = fileRes.data.files[0].id;
      // Update existing file
      await drive.files.update({
        fileId: fileId,
        media: media
      });
    } else {
      // Create new file
      const fileMetadata = {
        name: 'stock_backup_gdrive.db',
        parents: [folderId]
      };
      const newFile = await drive.files.create({
        resource: fileMetadata,
        media: media,
        fields: 'id'
      });
      fileId = newFile.data.id;
    }

    const timestamp = new Date().toLocaleString();
    config.lastSync = timestamp;
    saveGDriveConfig(config);

    return { success: true, timestamp };
  } catch (err) {
    console.error('Google Drive Sync failed:', err);
    return { success: false, error: err.message };
  }
});

ipcMain.handle('restore-database-from-cloud', async () => {
  const config = getGDriveConfig();
  if (!config.linked || !config.tokens) {
    return { success: false, error: 'Google Drive is not linked.' };
  }

  try {
    const oauth2Client = getOAuthClient(config.credentials);
    oauth2Client.setCredentials(config.tokens);

    const drive = google.drive({ version: 'v3', auth: oauth2Client });
    const dbPath = path.join(__dirname, 'php-app', 'database.db');

    // 1. Search folder "StockARB Backups"
    const folderRes = await drive.files.list({
      q: "mimeType='application/vnd.google-apps.folder' and name='StockARB Backups' and trashed=false",
      fields: 'files(id)'
    });

    if (folderRes.data.files.length === 0) {
      return { success: false, error: '"StockARB Backups" folder not found in your Google Drive!' };
    }
    const folderId = folderRes.data.files[0].id;

    // 2. Search file "stock_backup_gdrive.db"
    const fileRes = await drive.files.list({
      q: `name='stock_backup_gdrive.db' and '${folderId}' in parents and trashed=false`,
      fields: 'files(id)'
    });

    if (fileRes.data.files.length === 0) {
      return { success: false, error: '"stock_backup_gdrive.db" file not found in your Google Drive folder!' };
    }
    const fileId = fileRes.data.files[0].id;

    // Stop PHP server temporarily to free SQLite file locks
    if (phpServerProcess) {
      phpServerProcess.kill();
    }

    // Download file content
    const dest = fs.createWriteStream(dbPath);
    const downloadRes = await drive.files.get(
      { fileId: fileId, alt: 'media' },
      { responseType: 'stream' }
    );

    await new Promise((resolve, reject) => {
      downloadRes.data
        .on('end', () => {
          resolve();
        })
        .on('error', (err) => {
          reject(err);
        })
        .pipe(dest);
    });

    // Restart PHP server
    startPhpServer();

    return { success: true };
  } catch (err) {
    console.error('Google Drive Restore failed:', err);
    // Restart PHP server in case of failure to keep app running
    startPhpServer();
    return { success: false, error: err.message };
  }
});

// ----------------------------------------------------
// Software Update Logic
// ----------------------------------------------------

function checkUpdates() {
  const options = {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' }
  };

  https.get('https://raw.githubusercontent.com/rahulmaithili/STOCK-ARB/main/package.json', options, (res) => {
    let data = '';
    res.on('data', (chunk) => { data += chunk; });
    res.on('end', () => {
      try {
        const remotePkg = JSON.parse(data);
        const remoteVersion = remotePkg.version;
        
        console.log(`Local Version: ${localVersion}, Remote Version: ${remoteVersion}`);
        
        if (compareVersions(remoteVersion, localVersion) > 0) {
          showUpdatePrompt(remoteVersion);
        }
      } catch (e) {
        console.warn('Failed to parse update version check response:', e);
      }
    });
  }).on('error', (err) => {
    console.warn('Update check failed (likely offline):', err.message);
  });
}

function compareVersions(v1, v2) {
  const parts1 = v1.split('.').map(Number);
  const parts2 = v2.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    if (parts1[i] > parts2[i]) return 1;
    if (parts1[i] < parts2[i]) return -1;
  }
  return 0;
}

function showUpdatePrompt(newVersion) {
  const choice = dialog.showMessageBoxSync(mainWindow, {
    type: 'question',
    buttons: ['Update Now', 'Later'],
    title: 'Software Update Available',
    message: `A new version (v${newVersion}) is available.\nWould you like to install the update now?\nYour stock database will NOT be affected.`,
    defaultId: 0,
    cancelId: 1
  });

  if (choice === 0) {
    downloadUpdate(newVersion);
  }
}

function downloadUpdate(newVersion) {
  const tempZipPath = path.join(app.getPath('temp'), 'stockflow-update.zip');
  const file = fs.createWriteStream(tempZipPath);
  
  // Show downloading progress alert
  const progressDialog = dialog.showMessageBox(mainWindow, {
    type: 'info',
    buttons: [],
    title: 'Downloading Update',
    message: 'Downloading software updates from GitHub. Please wait, the application will restart automatically...'
  });

  https.get('https://codeload.github.com/rahulmaithili/STOCK-ARB/zip/refs/heads/main', (response) => {
    response.pipe(file);
    file.on('finish', () => {
      file.close(() => {
        applyUpdate(tempZipPath, newVersion);
      });
    });
  }).on('error', (err) => {
    fs.unlinkSync(tempZipPath);
    dialog.showErrorBox('Update Download Failed', `Failed to download update: ${err.message}`);
  });
}

function applyUpdate(zipPath, newVersion) {
  try {
    const zip = new AdmZip(zipPath);
    const extractPath = path.join(app.getPath('temp'), 'stockflow-extracted');
    
    // Extract everything to temp directory
    zip.extractAllTo(extractPath, true);
    
    const extractedAppPath = path.join(extractPath, 'STOCK-ARB-main');
    const localAppPath = path.join(__dirname, 'php-app');
    
    // Stop PHP server temporarily to overwrite files
    if (phpServerProcess) {
      phpServerProcess.kill();
    }

    // Copy extracted files to local php-app folder
    copyFolderRecursiveSync(extractedAppPath, localAppPath);
    
    // Update package.json version field on disk
    const packageJsonPath = path.join(__dirname, 'package.json');
    if (fs.existsSync(packageJsonPath)) {
      const pkg = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
      pkg.version = newVersion;
      fs.writeFileSync(packageJsonPath, JSON.stringify(pkg, null, 2), 'utf8');
    }

    // Cleanup temp zip
    fs.unlinkSync(zipPath);
    fs.rmSync(extractPath, { recursive: true, force: true });

    // Success notification and restart
    dialog.showMessageBoxSync(mainWindow, {
      type: 'info',
      buttons: ['OK'],
      title: 'Update Successful',
      message: 'Software updated successfully! The application will now relaunch.'
    });

    app.relaunch();
    app.exit(0);
  } catch (err) {
    dialog.showErrorBox('Update Extraction Failed', `Failed to install files: ${err.message}`);
  }
}

function copyFolderRecursiveSync(source, target) {
  if (!fs.existsSync(target)) {
    fs.mkdirSync(target);
  }

  const files = fs.readdirSync(source);
  files.forEach((file) => {
    const curSource = path.join(source, file);
    const curTarget = path.join(target, file);

    // CRITICAL EXCLUSIONS: Preserve local SQLite database and custom database configs
    if (file === 'database.db' && fs.existsSync(curTarget)) {
      console.log('Skipping database.db overwrite');
      return;
    }
    if (file === 'config.php' && path.basename(path.dirname(curTarget)) === 'config' && fs.existsSync(curTarget)) {
      console.log('Skipping config/config.php overwrite');
      return;
    }

    if (fs.lstatSync(curSource).isDirectory()) {
      copyFolderRecursiveSync(curSource, curTarget);
    } else {
      fs.copyFileSync(curSource, curTarget);
    }
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 1000,
    minHeight: 700,
    show: false,
    icon: path.join(__dirname, 'php-app', 'assets', 'img', 'favicon.jpg'),
    backgroundColor: '#ffffff',
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      preload: path.join(__dirname, 'preload.js')
    }
  });

  mainWindow.loadURL('http://127.0.0.1:54321/index.php');
  mainWindow.setMenuBarVisibility(false);

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    
    // Check for updates shortly after app shows
    setTimeout(checkUpdates, 3000);
  });

  mainWindow.on('closed', function () {
    mainWindow = null;
  });
}

app.whenReady().then(() => {
  startPhpServer();
  setTimeout(createWindow, 1200);

  app.on('activate', function () {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', function () {
  if (phpServerProcess) {
    phpServerProcess.kill();
  }
  if (process.platform !== 'darwin') app.quit();
});
