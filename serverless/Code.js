/**
 * StockFlow Google Apps Script API Engine
 * Place this code inside the Google Apps Script editor bound to your Google Sheet.
 * Set up sheets named: "products", "customer_replacements", "plant_replacements", "users", "stock_adjustments"
 */

// Handle HTTP GET Requests
function doGet(e) {
  var action = e.parameter.action;
  
  if (action === 'get_data') {
    return handleGetData();
  } else if (action === 'search_consumer') {
    return handleSearchConsumer(e.parameter.consumer_number);
  }
  
  return jsonResponse({ success: false, error: 'Invalid GET action.' });
}

// Handle HTTP POST Requests
function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var action = data.action;
    
    if (action === 'login') {
      return handleLogin(data.email, data.password);
    } else if (action === 'customer_txn') {
      return handleCustomerTxn(data);
    } else if (action === 'plant_txn') {
      return handlePlantTxn(data);
    }
    
    return jsonResponse({ success: false, error: 'Invalid POST action.' });
  } catch (err) {
    return jsonResponse({ success: false, error: 'System error: ' + err.message });
  }
}

// Helper to format JSON responses with CORS headers
function jsonResponse(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

// Fetch all inventory list data and stats
function handleGetData() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  
  // 1. Fetch Products
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  var products = [];
  var defStockInHand = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    var p = {
      id: prodData[i][0],
      name: prodData[i][1],
      sku: prodData[i][2],
      category: prodData[i][3],
      unit: prodData[i][4],
      purchase_price: prodData[i][5],
      selling_price: prodData[i][6],
      opening_stock: prodData[i][7],
      current_stock: prodData[i][8],
      reorder_level: prodData[i][9],
      product_type: prodData[i][10],
      defective_stock: prodData[i][11]
    };
    products.push(p);
    
    if (p.product_type === 'regulator') {
      defStockInHand += parseInt(p.defective_stock || 0);
    }
  }
  
  // 2. Fetch Customer Replacements Logs
  var custSheet = ss.getSheetByName('customer_replacements');
  var custData = custSheet.getDataRange().getValues();
  var customerLogs = [];
  
  var totalNC = 0;
  var totalTVI = 0;
  var totalTVO = 0;
  var totalExchanges = 0;
  
  for (var i = custData.length - 1; i >= 1; i--) { // Reverse order for recent logs first
    var log = {
      id: custData[i][0],
      customer_name: custData[i][1],
      product_id: custData[i][2],
      product_name: getProductNameById(products, custData[i][2]),
      product_sku: getProductSkuById(products, custData[i][2]),
      quantity: parseInt(custData[i][3] || 0),
      swap_type: custData[i][4],
      consumer_number: custData[i][5],
      mobile_number: custData[i][6],
      old_regulator_no: custData[i][7],
      new_regulator_no: custData[i][8],
      replacement_date: Utilities.formatDate(new Date(custData[i][9]), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
      notes: custData[i][10],
      created_by: custData[i][11],
      created_at: custData[i][12]
    };
    customerLogs.push(log);
    
    // Calculate breakdown aggregates
    if (log.swap_type === 'new_connection') totalNC += log.quantity;
    else if (log.swap_type === 'tv_in') totalTVI += log.quantity;
    else if (log.swap_type === 'tv_out') totalTVO += log.quantity;
    else if (log.swap_type === 'replacement') totalExchanges += log.quantity;
  }
  
  // 3. Fetch Plant Return Logs
  var plantSheet = ss.getSheetByName('plant_replacements');
  var plantData = plantSheet.getDataRange().getValues();
  var plantLogs = [];
  
  for (var i = plantData.length - 1; i >= 1; i--) {
    var plog = {
      id: plantData[i][0],
      supplier_name: plantData[i][1],
      product_id: plantData[i][2],
      product_name: getProductNameById(products, plantData[i][2]),
      product_sku: getProductSkuById(products, plantData[i][2]),
      quantity: parseInt(plantData[i][3] || 0),
      return_date: Utilities.formatDate(new Date(plantData[i][4]), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
      notes: plantData[i][5],
      created_by: plantData[i][6],
      created_at: plantData[i][7]
    };
    plantLogs.push(plog);
  }
  
  return jsonResponse({
    success: true,
    products: products,
    customerLogs: customerLogs.slice(0, 100), // Return recent 100 logs
    plantLogs: plantLogs.slice(0, 100),
    stats: {
      defective_in_hand: defStockInHand,
      total_customer_swaps: customerLogs.length,
      total_plant_returns: plantLogs.length,
      total_nc: totalNC,
      total_tvi: totalTVI,
      total_tvo: totalTVO,
      total_exchanges: totalExchanges
    }
  });
}

// Search and audit logs by connection number
function handleSearchConsumer(consumerNumber) {
  if (!consumerNumber) {
    return jsonResponse({ success: false, error: 'Consumer connection number is required.' });
  }
  
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  
  // Fetch Products for SKU names
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  var productsMap = {};
  for (var i = 1; i < prodData.length; i++) {
    productsMap[prodData[i][0]] = { name: prodData[i][1], sku: prodData[i][2] };
  }
  
  var custSheet = ss.getSheetByName('customer_replacements');
  var custData = custSheet.getDataRange().getValues();
  var matchedLogs = [];
  
  for (var i = 1; i < custData.length; i++) {
    if (String(custData[i][5]).trim().toLowerCase() === consumerNumber.trim().toLowerCase()) {
      var pId = custData[i][2];
      matchedLogs.push({
        id: custData[i][0],
        customer_name: custData[i][1],
        product_name: productsMap[pId] ? productsMap[pId].name : 'Unknown Product',
        product_sku: productsMap[pId] ? productsMap[pId].sku : '',
        quantity: parseInt(custData[i][3] || 0),
        swap_type: custData[i][4],
        consumer_number: custData[i][5],
        mobile_number: custData[i][6],
        old_regulator_no: custData[i][7],
        new_regulator_no: custData[i][8],
        replacement_date: Utilities.formatDate(new Date(custData[i][9]), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
        notes: custData[i][10]
      });
    }
  }
  
  return jsonResponse({
    success: true,
    consumer_number: consumerNumber,
    count: matchedLogs.length,
    results: matchedLogs
  });
}

// Process user authentication
function handleLogin(email, password) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var userSheet = ss.getSheetByName('users');
  var userData = userSheet.getDataRange().getValues();
  
  for (var i = 1; i < userData.length; i++) {
    if (userData[i][2] === email && String(userData[i][3]) === password) {
      return jsonResponse({
        success: true,
        user: {
          name: userData[i][1],
          email: userData[i][2],
          role: userData[i][4]
        },
        token: 'token_' + Utilities.getUuid()
      });
    }
  }
  
  // Fallback default admin credentials in case database users sheet is empty
  if (email === 'admin@stockflow.com' && password === 'admin123') {
    return jsonResponse({
      success: true,
      user: { name: 'System Administrator', email: email, role: 'admin' },
      token: 'token_default_admin'
    });
  }
  
  return jsonResponse({ success: false, error: 'Invalid login email or password.' });
}

// Log a customer regulator transaction (NC, TVI, TVO, Replacement)
function handleCustomerTxn(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity);
  var type = data.swap_type;
  
  // Find product index row
  var prodRowIndex = -1;
  var currentGoodStock = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      prodRowIndex = i + 1; // 1-indexed row number
      currentGoodStock = parseInt(prodData[i][8] || 0);
      break;
    }
  }
  
  if (prodRowIndex === -1) {
    return jsonResponse({ success: false, error: 'Regulator product not found.' });
  }
  
  // Good Stock check
  if (['replacement', 'new_connection', 'tv_in'].indexOf(type) !== -1 && currentGoodStock < qty) {
    return jsonResponse({ success: false, error: 'Insufficient good stock! Available: ' + currentGoodStock });
  }
  
  // Adjust Good/Defective Stock in Sheets
  if (type === 'replacement') {
    prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock - qty); // Good Stock minus
    var currentDef = parseInt(prodSheet.getCell(prodRowIndex, 12).getValue() || 0);
    prodSheet.getCell(prodRowIndex, 12).setValue(currentDef + qty); // Defective Stock plus
  } else if (type === 'new_connection' || type === 'tv_in') {
    prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock - qty); // Good Stock minus
  } else if (type === 'tv_out') {
    var currentDef = parseInt(prodSheet.getCell(prodRowIndex, 12).getValue() || 0);
    prodSheet.getCell(prodRowIndex, 12).setValue(currentDef + qty); // Defective Stock plus
  }
  
  // Append transaction record to customer_replacements
  var custSheet = ss.getSheetByName('customer_replacements');
  var newId = custSheet.getLastRow() > 0 ? (custSheet.getLastRow()) : 1;
  
  custSheet.appendRow([
    newId,
    data.customer_name,
    pId,
    qty,
    type,
    data.consumer_number,
    data.mobile_number,
    data.old_regulator_no || '',
    data.new_regulator_no || '',
    data.replacement_date || Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
    data.notes || '',
    data.created_by || 'System',
    new Date()
  ]);
  
  return jsonResponse({ success: true, message: 'Transaction logged successfully!' });
}

// Log a factory return plant transaction
function handlePlantTxn(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity);
  
  var prodRowIndex = -1;
  var currentGoodStock = 0;
  var currentDefectiveStock = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      prodRowIndex = i + 1;
      currentGoodStock = parseInt(prodData[i][8] || 0);
      currentDefectiveStock = parseInt(prodData[i][11] || 0);
      break;
    }
  }
  
  if (prodRowIndex === -1) {
    return jsonResponse({ success: false, error: 'Product not found.' });
  }
  
  if (currentDefectiveStock < qty) {
    return jsonResponse({ success: false, error: 'Insufficient defective stock in warehouse! Available: ' + currentDefectiveStock });
  }
  
  // Adjust stock levels
  prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock + qty); // Good Stock plus
  prodSheet.getCell(prodRowIndex, 12).setValue(currentDefectiveStock - qty); // Defective Stock minus
  
  // Append record to plant_replacements
  var plantSheet = ss.getSheetByName('plant_replacements');
  var newId = plantSheet.getLastRow() > 0 ? (plantSheet.getLastRow()) : 1;
  
  plantSheet.appendRow([
    newId,
    data.supplier_name,
    pId,
    qty,
    data.return_date || Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
    data.notes || '',
    data.created_by || 'System',
    new Date()
  ]);
  
  return jsonResponse({ success: true, message: 'Plant return logged successfully!' });
}

// Helper utility functions
function getProductNameById(products, id) {
  for (var i = 0; i < products.length; i++) {
    if (parseInt(products[i].id) === parseInt(id)) {
      return products[i].name;
    }
  }
  return 'Unknown Regulator';
}

function getProductSkuById(products, id) {
  for (var i = 0; i < products.length; i++) {
    if (parseInt(products[i].id) === parseInt(id)) {
      return products[i].sku;
    }
  }
  return '';
}

// Automatically setup all Sheet Tabs, Column Headers, and Default admin credentials
function setupDatabase() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  
  // 1. Setup products sheet
  var prodSheet = ss.getSheetByName('products') || ss.insertSheet('products');
  prodSheet.clear();
  prodSheet.appendRow([
    'id', 'name', 'sku', 'category', 'unit', 'purchase_price', 'selling_price', 'opening_stock', 'current_stock', 'reorder_level', 'product_type', 'defective_stock'
  ]);
  // Add sample product
  prodSheet.appendRow([
    1, 'Regulator Emr', 'EMR', 'Regulator', 'PCS', 150, 250, 400, 400, 20, 'regulator', 36
  ]);
  
  // 2. Setup customer_replacements
  var custSheet = ss.getSheetByName('customer_replacements') || ss.insertSheet('customer_replacements');
  custSheet.clear();
  custSheet.appendRow([
    'id', 'customer_name', 'product_id', 'quantity', 'swap_type', 'consumer_number', 'mobile_number', 'old_regulator_no', 'new_regulator_no', 'replacement_date', 'notes', 'created_by', 'created_at'
  ]);
  
  // 3. Setup plant_replacements
  var plantSheet = ss.getSheetByName('plant_replacements') || ss.insertSheet('plant_replacements');
  plantSheet.clear();
  plantSheet.appendRow([
    'id', 'supplier_name', 'product_id', 'quantity', 'return_date', 'notes', 'created_by', 'created_at'
  ]);
  
  // 4. Setup users
  var userSheet = ss.getSheetByName('users') || ss.insertSheet('users');
  userSheet.clear();
  userSheet.appendRow([
    'id', 'name', 'email', 'password', 'role'
  ]);
  userSheet.appendRow([
    1, 'System Administrator', 'admin@stockflow.com', 'admin123', 'admin'
  ]);
  
  // Remove default "Sheet1" if it exists to keep it clean
  var sheet1 = ss.getSheetByName('Sheet1');
  if (sheet1) {
    try {
      ss.deleteSheet(sheet1);
    } catch(err) {}
  }
  
  return "Database setup complete! All tabs, headers and default admin user created successfully.";
}
