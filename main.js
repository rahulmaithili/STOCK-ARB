const { app, BrowserWindow, dialog } = require('electron');
const path = require('path');
const { spawn } = require('child_process');
const fs = require('fs');
const https = require('https');
const AdmZip = require('adm-zip');

let mainWindow;
let phpServerProcess;

// Get local app version from package.json
const localVersion = require('./package.json').version;

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
    backgroundColor: '#ffffff',
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true
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
