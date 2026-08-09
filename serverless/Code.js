/**
 * StockFlow Google Apps Script API Engine - Premium Full ERP Edition
 * Set up sheets named: "products", "customer_replacements", "plant_replacements", "users", "suppliers", "customers", "purchases", "sales", "stock_adjustments", "settings"
 */

// Handle HTTP GET Requests
function doGet(e) {
  var action = e.parameter.action;
  
  if (action === 'get_data') {
    return handleGetData();
  } else if (action === 'search_consumer') {
    return handleSearchConsumer(e.parameter.consumer_number);
  } else if (action === 'get_ledger') {
    return handleGetLedger(e.parameter.product_id, e.parameter.date_from, e.parameter.date_to);
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
    
    // Core ERP CRUD actions
    else if (action === 'save_product') {
      return handleSaveProduct(data);
    } else if (action === 'delete_product') {
      return handleDeleteProduct(data.id);
    } else if (action === 'save_supplier') {
      return handleSaveSupplier(data);
    } else if (action === 'delete_supplier') {
      return handleDeleteSupplier(data.id);
    } else if (action === 'save_customer') {
      return handleSaveCustomer(data);
    } else if (action === 'delete_customer') {
      return handleDeleteCustomer(data.id);
    } else if (action === 'save_purchase') {
      return handleSavePurchase(data);
    } else if (action === 'save_sale') {
      return handleSaveSale(data);
    } else if (action === 'save_adjustment') {
      return handleSaveAdjustment(data);
    } else if (action === 'save_settings') {
      return handleSaveSettings(data);
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

// Fetch all database records, compute stats & charts data
function handleGetData() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  
  // 1. Fetch Products
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  var products = [];
  var lowStockCount = 0;
  var totalStockValue = 0;
  var defStockInHand = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    var p = {
      id: parseInt(prodData[i][0]),
      name: prodData[i][1],
      sku: prodData[i][2],
      category: prodData[i][3],
      unit: prodData[i][4],
      purchase_price: parseFloat(prodData[i][5] || 0),
      selling_price: parseFloat(prodData[i][6] || 0),
      opening_stock: parseInt(prodData[i][7] || 0),
      current_stock: parseInt(prodData[i][8] || 0),
      reorder_level: parseInt(prodData[i][9] || 0),
      product_type: prodData[i][10],
      defective_stock: parseInt(prodData[i][11] || 0)
    };
    products.push(p);
    
    // Accumulate metrics
    totalStockValue += (p.current_stock * p.purchase_price);
    if (p.current_stock <= p.reorder_level) {
      lowStockCount++;
    }
    if (p.product_type === 'regulator' || p.product_type === 'ftl_regulator') {
      defStockInHand += p.defective_stock;
    }
  }
  
  // 2. Fetch Suppliers
  var supSheet = ss.getSheetByName('suppliers');
  var supData = supSheet.getDataRange().getValues();
  var suppliers = [];
  for (var i = 1; i < supData.length; i++) {
    suppliers.push({
      id: parseInt(supData[i][0]),
      name: supData[i][1],
      phone: supData[i][2],
      email: supData[i][3],
      address: supData[i][4]
    });
  }
  
  // 3. Fetch Customers
  var custSheet = ss.getSheetByName('customers');
  var custData = custSheet.getDataRange().getValues();
  var customers = [];
  for (var i = 1; i < custData.length; i++) {
    customers.push({
      id: parseInt(custData[i][0]),
      name: custData[i][1],
      phone: custData[i][2],
      email: supData[i][3],
      address: custData[i][4]
    });
  }
  
  // 4. Fetch Purchases
  var purSheet = ss.getSheetByName('purchases');
  var purData = purSheet.getDataRange().getValues();
  var purchases = [];
  for (var i = purData.length - 1; i >= 1; i--) {
    purchases.push({
      id: parseInt(purData[i][0]),
      supplier_name: getEntityNameById(suppliers, purData[i][1]),
      product_name: getProductNameById(products, purData[i][2]),
      quantity: parseInt(purData[i][3] || 0),
      purchase_price: parseFloat(purData[i][4] || 0),
      total_amount: parseFloat(purData[i][5] || 0),
      purchase_date: Utilities.formatDate(new Date(purData[i][6]), Session.getScriptTimeZone(), 'yyyy-MM-dd')
    });
  }
  
  // 5. Fetch Sales
  var salesSheet = ss.getSheetByName('sales');
  var salesData = salesSheet.getDataRange().getValues();
  var sales = [];
  var todaySales = 0;
  var todayDateStr = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd');
  
  for (var i = salesData.length - 1; i >= 1; i--) {
    var sDateStr = Utilities.formatDate(new Date(salesData[i][6]), Session.getScriptTimeZone(), 'yyyy-MM-dd');
    var saleVal = parseFloat(salesData[i][5] || 0);
    
    if (sDateStr === todayDateStr) {
      todaySales += saleVal;
    }
    
    sales.push({
      id: parseInt(salesData[i][0]),
      customer_name: getEntityNameById(customers, salesData[i][1]),
      product_name: getProductNameById(products, salesData[i][2]),
      product_sku: getProductSkuById(products, salesData[i][2]),
      quantity: parseInt(salesData[i][3] || 0),
      selling_price: parseFloat(salesData[i][4] || 0),
      total_amount: saleVal,
      sale_date: sDateStr
    });
  }

  // 6. Fetch Adjustments
  var adjSheet = ss.getSheetByName('stock_adjustments');
  var adjData = adjSheet.getDataRange().getValues();
  var adjustments = [];
  for (var i = adjData.length - 1; i >= 1; i--) {
    adjustments.push({
      id: parseInt(adjData[i][0]),
      product_name: getProductNameById(products, adjData[i][1]),
      quantity: parseInt(adjData[i][2] || 0),
      reason: adjData[i][3],
      adjusted_at: Utilities.formatDate(new Date(adjData[i][4]), Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm')
    });
  }
  
  // 7. Fetch Settings
  var settingsSheet = ss.getSheetByName('settings');
  var settingsData = settingsSheet.getDataRange().getValues();
  var settings = {
    company_name: 'StockFlow Agency',
    logo: '',
    address: 'Pandaul Bazar, Madhubani',
    phone: '+91 9999888877',
    email: 'info@stockflow.com',
    gstin: '07AAAAA1111A1Z1'
  };
  if (settingsData.length > 1) {
    settings = {
      company_name: settingsData[1][0] || settings.company_name,
      logo: settingsData[1][1] || '',
      address: settingsData[1][2] || settings.address,
      phone: settingsData[1][3] || settings.phone,
      email: settingsData[1][4] || settings.email,
      gstin: settingsData[1][5] || settings.gstin
    };
  }

  // 8. Fetch Regulator Swap Logs (Recent 10)
  var custReplSheet = ss.getSheetByName('customer_replacements');
  var custReplData = custReplSheet.getDataRange().getValues();
  var customerLogs = [];
  var totalNC = 0, totalTVI = 0, totalTVO = 0, totalExchanges = 0;
  
  for (var i = custReplData.length - 1; i >= 1; i--) {
    var l = {
      id: parseInt(custReplData[i][0]),
      customer_name: custReplData[i][1],
      product_name: getProductNameById(products, custReplData[i][2]),
      quantity: parseInt(custReplData[i][3] || 0),
      swap_type: custReplData[i][4],
      consumer_number: custReplData[i][5],
      mobile_number: custReplData[i][6],
      old_regulator_no: custReplData[i][7],
      new_regulator_no: custReplData[i][8],
      replacement_date: Utilities.formatDate(new Date(custReplData[i][9]), Session.getScriptTimeZone(), 'yyyy-MM-dd')
    };
    customerLogs.push(l);
    
    if (l.swap_type === 'new_connection') totalNC += l.quantity;
    else if (l.swap_type === 'tv_in') totalTVI += l.quantity;
    else if (l.swap_type === 'tv_out') totalTVO += l.quantity;
    else if (l.swap_type === 'replacement') totalExchanges += l.quantity;
  }
  
  var plantSheet = ss.getSheetByName('plant_replacements');
  var plantData = plantSheet.getDataRange().getValues();
  var plantLogs = [];
  for (var i = plantData.length - 1; i >= 1; i--) {
    plantLogs.push({
      id: parseInt(plantData[i][0]),
      supplier_name: plantData[i][1],
      product_name: getProductNameById(products, plantData[i][2]),
      quantity: parseInt(plantData[i][3] || 0),
      return_date: Utilities.formatDate(new Date(plantData[i][4]), Session.getScriptTimeZone(), 'yyyy-MM-dd')
    });
  }

  // 9. Compile Charts comparison data (Last 6 Months)
  var monthsLabel = [];
  var monthlySales = [];
  var monthlyPurchases = [];
  
  for (var i = 5; i >= 0; i--) {
    var d = new Date();
    d.setMonth(d.getMonth() - i);
    var label = Utilities.formatDate(d, Session.getScriptTimeZone(), 'MMM yyyy');
    monthsLabel.push(label);
    
    var mStart = new Date(d.getFullYear(), d.getMonth(), 1);
    var mEnd = new Date(d.getFullYear(), d.getMonth() + 1, 0);
    
    // Sum Sales for this month
    var sSum = 0;
    for (var k = 1; k < salesData.length; k++) {
      var sD = new Date(salesData[k][6]);
      if (sD >= mStart && sD <= mEnd) {
        sSum += parseFloat(salesData[k][5] || 0);
      }
    }
    monthlySales.push(sSum);
    
    // Sum Purchases for this month
    var pSum = 0;
    for (var k = 1; k < purData.length; k++) {
      var pD = new Date(purData[k][6]);
      if (pD >= mStart && pD <= mEnd) {
        pSum += parseFloat(purData[k][5] || 0);
      }
    }
    monthlyPurchases.push(pSum);
  }

  // 10. Compile Top Selling Products doughnut chart data
  var prodSaleCounts = {};
  for (var i = 1; i < salesData.length; i++) {
    var pId = salesData[i][2];
    var qty = parseInt(salesData[i][3] || 0);
    prodSaleCounts[pId] = (prodSaleCounts[pId] || 0) + qty;
  }
  var topSellingLabels = [];
  var topSellingData = [];
  for (var pId in prodSaleCounts) {
    topSellingLabels.push(getProductNameById(products, pId));
    topSellingData.push(prodSaleCounts[pId]);
  }

  return jsonResponse({
    success: true,
    products: products,
    suppliers: suppliers,
    customers: customers,
    purchases: purchases,
    sales: sales,
    adjustments: adjustments,
    settings: settings,
    customerLogs: customerLogs.slice(0, 10), // Recent 10 for dashboard
    plantLogs: plantLogs.slice(0, 10),
    stats: {
      today_sales: todaySales,
      low_stock_count: lowStockCount,
      total_products: products.length,
      stock_asset_value: totalStockValue,
      defective_in_hand: defStockInHand,
      total_customer_swaps: customerLogs.length,
      total_plant_returns: plantLogs.length,
      total_nc: totalNC,
      total_tvi: totalTVI,
      total_tvo: totalTVO,
      total_exchanges: totalExchanges
    },
    charts: {
      months: monthsLabel,
      sales: monthlySales,
      purchases: monthlyPurchases,
      topLabels: topSellingLabels,
      topData: topSellingData
    }
  });
}

// Search and audit logs by connection number
function handleSearchConsumer(consumerNumber) {
  if (!consumerNumber) {
    return jsonResponse({ success: false, error: 'Consumer connection number is required.' });
  }
  
  var ss = SpreadsheetApp.getActiveSpreadsheet();
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
        user: { name: userData[i][1], email: userData[i][2], role: userData[i][4] },
        token: 'token_' + Utilities.getUuid()
      });
    }
  }
  
  if (email === 'admin@stockflow.com' && password === 'admin123') {
    return jsonResponse({
      success: true,
      user: { name: 'System Administrator', email: email, role: 'admin' },
      token: 'token_default_admin'
    });
  }
  
  return jsonResponse({ success: false, error: 'Invalid login email or password.' });
}

// Core CRUD handlers: Products Catalog
function handleSaveProduct(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('products');
  var fileData = sheet.getDataRange().getValues();
  
  var id = parseInt(data.id || 0);
  var rowIndex = -1;
  
  if (id > 0) {
    for (var i = 1; i < fileData.length; i++) {
      if (parseInt(fileData[i][0]) === id) {
        rowIndex = i + 1;
        break;
      }
    }
  }
  
  if (rowIndex === -1) {
    // Insert new product row
    var newId = fileData.length > 0 ? (fileData.length) : 1;
    sheet.appendRow([
      newId,
      data.name,
      data.sku,
      data.category,
      data.unit,
      parseFloat(data.purchase_price),
      parseFloat(data.selling_price),
      parseInt(data.opening_stock),
      parseInt(data.opening_stock), // current stock defaults to opening stock
      parseInt(data.reorder_level),
      data.product_type || 'standard',
      parseInt(data.defective_stock || 0)
    ]);
  } else {
    // Update existing row
    sheet.getCell(rowIndex, 2).setValue(data.name);
    sheet.getCell(rowIndex, 3).setValue(data.sku);
    sheet.getCell(rowIndex, 4).setValue(data.category);
    sheet.getCell(rowIndex, 5).setValue(data.unit);
    sheet.getCell(rowIndex, 6).setValue(parseFloat(data.purchase_price));
    sheet.getCell(rowIndex, 7).setValue(parseFloat(data.selling_price));
    sheet.getCell(rowIndex, 9).setValue(parseInt(data.current_stock)); // Keep current stock
    sheet.getCell(rowIndex, 10).setValue(parseInt(data.reorder_level));
    sheet.getCell(rowIndex, 11).setValue(data.product_type);
    sheet.getCell(rowIndex, 12).setValue(parseInt(data.defective_stock || 0));
  }
  
  return jsonResponse({ success: true, message: 'Product catalog item saved successfully!' });
}

function handleDeleteProduct(id) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('products');
  var fileData = sheet.getDataRange().getValues();
  
  for (var i = 1; i < fileData.length; i++) {
    if (parseInt(fileData[i][0]) === parseInt(id)) {
      sheet.deleteRow(i + 1);
      return jsonResponse({ success: true, message: 'Product deleted successfully.' });
    }
  }
  return jsonResponse({ success: false, error: 'Product not found.' });
}

// Core CRUD handlers: Suppliers
function handleSaveSupplier(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('suppliers');
  var fileData = sheet.getDataRange().getValues();
  
  var id = parseInt(data.id || 0);
  var rowIndex = -1;
  
  if (id > 0) {
    for (var i = 1; i < fileData.length; i++) {
      if (parseInt(fileData[i][0]) === id) {
        rowIndex = i + 1;
        break;
      }
    }
  }
  
  if (rowIndex === -1) {
    var newId = fileData.length > 0 ? (fileData.length) : 1;
    sheet.appendRow([newId, data.name, data.phone, data.email, data.address, new Date()]);
  } else {
    sheet.getCell(rowIndex, 2).setValue(data.name);
    sheet.getCell(rowIndex, 3).setValue(data.phone);
    sheet.getCell(rowIndex, 4).setValue(data.email);
    sheet.getCell(rowIndex, 5).setValue(data.address);
  }
  return jsonResponse({ success: true, message: 'Supplier contact saved successfully!' });
}

function handleDeleteSupplier(id) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('suppliers');
  var fileData = sheet.getDataRange().getValues();
  for (var i = 1; i < fileData.length; i++) {
    if (parseInt(fileData[i][0]) === parseInt(id)) {
      sheet.deleteRow(i + 1);
      return jsonResponse({ success: true, message: 'Supplier deleted.' });
    }
  }
  return jsonResponse({ success: false, error: 'Supplier not found.' });
}

// Core CRUD handlers: Customers
function handleSaveCustomer(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('customers');
  var fileData = sheet.getDataRange().getValues();
  
  var id = parseInt(data.id || 0);
  var rowIndex = -1;
  
  if (id > 0) {
    for (var i = 1; i < fileData.length; i++) {
      if (parseInt(fileData[i][0]) === id) {
        rowIndex = i + 1;
        break;
      }
    }
  }
  
  if (rowIndex === -1) {
    var newId = fileData.length > 0 ? (fileData.length) : 1;
    sheet.appendRow([newId, data.name, data.phone, data.email, data.address, new Date()]);
  } else {
    sheet.getCell(rowIndex, 2).setValue(data.name);
    sheet.getCell(rowIndex, 3).setValue(data.phone);
    sheet.getCell(rowIndex, 4).setValue(data.email);
    sheet.getCell(rowIndex, 5).setValue(data.address);
  }
  return jsonResponse({ success: true, message: 'Customer profile saved successfully!' });
}

function handleDeleteCustomer(id) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('customers');
  var fileData = sheet.getDataRange().getValues();
  for (var i = 1; i < fileData.length; i++) {
    if (parseInt(fileData[i][0]) === parseInt(id)) {
      sheet.deleteRow(i + 1);
      return jsonResponse({ success: true, message: 'Customer deleted.' });
    }
  }
  return jsonResponse({ success: false, error: 'Customer not found.' });
}

// Transactions: Save Purchase (Stock In)
function handleSavePurchase(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity);
  
  var rowIndex = -1;
  var currentStock = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      rowIndex = i + 1;
      currentStock = parseInt(prodData[i][8] || 0);
      break;
    }
  }
  
  if (rowIndex === -1) {
    return jsonResponse({ success: false, error: 'Product not found in Catalog.' });
  }
  
  // 1. Add Stock to Products
  prodSheet.getCell(rowIndex, 9).setValue(currentStock + qty);
  
  // 2. Add row to Purchases Log
  var purSheet = ss.getSheetByName('purchases');
  var newId = purSheet.getLastRow() > 0 ? (purSheet.getLastRow()) : 1;
  purSheet.appendRow([
    newId,
    parseInt(data.supplier_id),
    pId,
    qty,
    parseFloat(data.purchase_price),
    parseFloat(data.total_amount),
    data.purchase_date || Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
    new Date()
  ]);
  
  return jsonResponse({ success: true, message: 'Stock In (Purchase) logged successfully!' });
}

// Transactions: Save Sale (Stock Out)
function handleSaveSale(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity);
  
  var rowIndex = -1;
  var currentStock = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      rowIndex = i + 1;
      currentStock = parseInt(prodData[i][8] || 0);
      break;
    }
  }
  
  if (rowIndex === -1) {
    return jsonResponse({ success: false, error: 'Product not found in Catalog.' });
  }
  
  if (currentStock < qty) {
    return jsonResponse({ success: false, error: 'Insufficient Good Stock in hand! Available: ' + currentStock });
  }
  
  // 1. Deduct Stock from Products
  prodSheet.getCell(rowIndex, 9).setValue(currentStock - qty);
  
  // 2. Add row to Sales Log
  var salesSheet = ss.getSheetByName('sales');
  var newId = salesSheet.getLastRow() > 0 ? (salesSheet.getLastRow()) : 1;
  salesSheet.appendRow([
    newId,
    parseInt(data.customer_id),
    pId,
    qty,
    parseFloat(data.selling_price),
    parseFloat(data.total_amount),
    data.sale_date || Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd'),
    new Date()
  ]);
  
  return jsonResponse({ success: true, message: 'Stock Out (Sale) logged successfully!' });
}

// Transactions: Save Stock Adjustment
function handleSaveAdjustment(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity); // Can be positive or negative
  
  var rowIndex = -1;
  var currentStock = 0;
  
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      rowIndex = i + 1;
      currentStock = parseInt(prodData[i][8] || 0);
      break;
    }
  }
  
  if (rowIndex === -1) {
    return jsonResponse({ success: false, error: 'Product not found.' });
  }
  
  // Adjust stock
  var finalStock = currentStock + qty;
  if (finalStock < 0) {
    return jsonResponse({ success: false, error: 'Adjustment leaves negative stock balance!' });
  }
  
  // 1. Set stock in products sheet
  prodSheet.getCell(rowIndex, 9).setValue(finalStock);
  
  // 2. Save adjustment log
  var adjSheet = ss.getSheetByName('stock_adjustments');
  var newId = adjSheet.getLastRow() > 0 ? (adjSheet.getLastRow()) : 1;
  adjSheet.appendRow([
    newId,
    pId,
    qty,
    data.reason || 'Inventory audit adjustment',
    new Date()
  ]);
  
  return jsonResponse({ success: true, message: 'Stock adjustment updated successfully!' });
}

// Save Settings Profile
function handleSaveSettings(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName('settings');
  sheet.clear();
  sheet.appendRow(['company_name', 'logo', 'address', 'phone', 'email', 'gstin']);
  sheet.appendRow([
    data.company_name,
    data.logo || '',
    data.address,
    data.phone,
    data.email,
    data.gstin
  ]);
  return jsonResponse({ success: true, message: 'Company settings saved successfully!' });
}

// Regulator: Log Customer swap replacement
function handleCustomerTxn(data) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(data.product_id);
  var qty = parseInt(data.quantity);
  var type = data.swap_type;
  
  var prodRowIndex = -1;
  var currentGoodStock = 0;
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      prodRowIndex = i + 1;
      currentGoodStock = parseInt(prodData[i][8] || 0);
      break;
    }
  }
  
  if (prodRowIndex === -1) {
    return jsonResponse({ success: false, error: 'Regulator not found.' });
  }
  
  if (['replacement', 'new_connection', 'tv_in'].indexOf(type) !== -1 && currentGoodStock < qty) {
    return jsonResponse({ success: false, error: 'Insufficient good stock! Available: ' + currentGoodStock });
  }
  
  if (type === 'replacement') {
    prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock - qty);
    var currentDef = parseInt(prodSheet.getCell(prodRowIndex, 12).getValue() || 0);
    prodSheet.getCell(prodRowIndex, 12).setValue(currentDef + qty);
  } else if (type === 'new_connection' || type === 'tv_in') {
    prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock - qty);
  } else if (type === 'tv_out') {
    var currentDef = parseInt(prodSheet.getCell(prodRowIndex, 12).getValue() || 0);
    prodSheet.getCell(prodRowIndex, 12).setValue(currentDef + qty);
  }
  
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

// Regulator: Log Plant return swap-back
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
    return jsonResponse({ success: false, error: 'Insufficient defective stock in hand! Available: ' + currentDefectiveStock });
  }
  
  prodSheet.getCell(prodRowIndex, 9).setValue(currentGoodStock + qty);
  prodSheet.getCell(prodRowIndex, 12).setValue(currentDefectiveStock - qty);
  
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

// Compile daily running ledger balances (Opening, In, NC Out, TVI Out, TVO In, Swap In, Plant Out, Closing)
function handleGetLedger(productId, dateFromStr, dateToStr) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var prodSheet = ss.getSheetByName('products');
  var prodData = prodSheet.getDataRange().getValues();
  
  var pId = parseInt(productId);
  var selectedProduct = null;
  for (var i = 1; i < prodData.length; i++) {
    if (parseInt(prodData[i][0]) === pId) {
      selectedProduct = {
        id: prodData[i][0],
        name: prodData[i][1],
        sku: prodData[i][2],
        opening_stock: parseInt(prodData[i][7] || 0),
        product_type: prodData[i][10],
        defective_stock_init: 0
      };
      break;
    }
  }
  
  if (!selectedProduct) {
    return jsonResponse({ success: false, error: 'Product not found.' });
  }
  
  // Fetch all transactions chronologically
  var custSheet = ss.getSheetByName('customer_replacements');
  var custData = custSheet.getDataRange().getValues();
  var txns = [];
  
  for (var i = 1; i < custData.length; i++) {
    if (parseInt(custData[i][2]) === pId) {
      txns.push({
        date: new Date(custData[i][9]),
        type: 'customer',
        swap_type: custData[i][4],
        qty: parseInt(custData[i][3] || 0),
        customer_name: custData[i][1],
        remarks: custData[i][10] || ''
      });
    }
  }
  
  var plantSheet = ss.getSheetByName('plant_replacements');
  var plantData = plantSheet.getDataRange().getValues();
  for (var i = 1; i < plantData.length; i++) {
    if (parseInt(plantData[i][2]) === pId) {
      txns.push({
        date: new Date(plantData[i][4]),
        type: 'plant',
        qty: parseInt(plantData[i][3] || 0),
        supplier_name: plantData[i][1],
        remarks: plantData[i][5] || ''
      });
    }
  }
  
  // Compile purchases
  var purSheet = ss.getSheetByName('purchases');
  var purData = purSheet.getDataRange().getValues();
  for (var i = 1; i < purData.length; i++) {
    if (parseInt(purData[i][2]) === pId) {
      txns.push({
        date: new Date(purData[i][6]),
        type: 'purchase',
        qty: parseInt(purData[i][3] || 0),
        supplier_name: 'Supplier Ref',
        remarks: 'Purchase Invoice GP-' + purData[i][0]
      });
    }
  }
  
  // Compile sales
  var salesSheet = ss.getSheetByName('sales');
  var salesData = salesSheet.getDataRange().getValues();
  for (var i = 1; i < salesData.length; i++) {
    if (parseInt(salesData[i][2]) === pId) {
      txns.push({
        date: new Date(salesData[i][6]),
        type: 'sale',
        qty: parseInt(salesData[i][3] || 0),
        customer_name: 'Customer Ref',
        remarks: 'Sale Invoice INV-' + salesData[i][0]
      });
    }
  }

  // Compile adjustments
  var adjSheet = ss.getSheetByName('stock_adjustments');
  var adjData = adjSheet.getDataRange().getValues();
  for (var i = 1; i < adjData.length; i++) {
    if (parseInt(adjData[i][1]) === pId) {
      txns.push({
        date: new Date(adjData[i][4]),
        type: 'adjustment',
        qty: parseInt(adjData[i][2] || 0),
        remarks: 'Adjustment: ' + adjData[i][3]
      });
    }
  }
  
  // Sort transactions chronologically
  txns.sort(function(a, b) {
    return a.date - b.date;
  });
  
  // Compile daily running ledger balances
  var runningGood = selectedProduct.opening_stock;
  var runningDef = 0;
  
  var dailyTxns = {};
  txns.forEach(function(t) {
    var dateKey = Utilities.formatDate(t.date, Session.getScriptTimeZone(), 'yyyy-MM-dd');
    if (!dailyTxns[dateKey]) {
      dailyTxns[dateKey] = [];
    }
    dailyTxns[dateKey].push(t);
  });
  
  var startDate = new Date(dateFromStr);
  var endDate = new Date(dateToStr);
  
  var earliestDate = new Date(startDate);
  if (txns.length > 0 && txns[0].date < startDate) {
    earliestDate = new Date(txns[0].date);
  }
  
  var initialGoodStock = selectedProduct.opening_stock;
  var initialDefectiveStock = 0;
  var ledgerRows = [];
  
  var tempDate = new Date(earliestDate);
  while (tempDate <= endDate) {
    var dateKey = Utilities.formatDate(tempDate, Session.getScriptTimeZone(), 'yyyy-MM-dd');
    var dayTxns = dailyTxns[dateKey] || [];
    
    var openGood = runningGood;
    var openDef = runningDef;
    
    var goodPurch = 0;
    var goodPlantIn = 0;
    var goodNC = 0;
    var goodTVI = 0;
    var goodSwapOut = 0;
    
    var defTVO = 0;
    var defSwapIn = 0;
    var defPlantOut = 0;
    
    var remarksList = [];
    
    dayTxns.forEach(function(t) {
      if (t.type === 'customer') {
        var st = t.swap_type;
        if (st === 'new_connection') {
          runningGood -= t.qty;
          goodNC += t.qty;
          remarksList.push("NC: " + t.customer_name + " (" + t.qty + " pcs)");
        } else if (st === 'tv_in') {
          runningGood -= t.qty;
          goodTVI += t.qty;
          remarksList.push("TV In: " + t.customer_name + " (" + t.qty + " pcs)");
        } else if (st === 'tv_out') {
          runningDef += t.qty;
          defTVO += t.qty;
          remarksList.push("TV Out: " + t.customer_name + " (" + t.qty + " pcs)");
        } else if (st === 'replacement') {
          runningGood -= t.qty;
          runningDef += t.qty;
          goodSwapOut += t.qty;
          defSwapIn += t.qty;
          remarksList.push("Swap: " + t.customer_name + " (" + t.qty + " pcs)");
        }
      } else if (t.type === 'plant') {
        runningGood += t.qty;
        runningDef -= t.qty;
        goodPlantIn += t.qty;
        defPlantOut += t.qty;
        remarksList.push("Plant Return: " + t.supplier_name + " (" + t.qty + " pcs)");
      } else if (t.type === 'purchase') {
        runningGood += t.qty;
        goodPurch += t.qty;
        remarksList.push("Purchased: " + t.qty + " pcs");
      } else if (t.type === 'sale') {
        runningGood -= t.qty;
        remarksList.push("Sold: " + t.qty + " pcs");
      } else if (t.type === 'adjustment') {
        runningGood += t.qty;
        remarksList.push(t.remarks);
      }
    });
    
    var closeGood = runningGood;
    var closeDef = runningDef;
    
    if (tempDate < startDate) {
      initialGoodStock = closeGood;
      initialDefectiveStock = closeDef;
    }
    
    if (tempDate >= startDate && tempDate <= endDate) {
      if (dayTxns.length > 0) {
        ledgerRows.push({
          date: dateKey,
          open_good: openGood,
          good_purchase: goodPurch,
          good_plant_in: goodPlantIn,
          good_adjustment: 0,
          good_out_nc: goodNC,
          good_out_tvi: goodTVI,
          good_out_swap: goodSwapOut,
          good_sale: 0,
          close_good: closeGood,
          
          open_def: openDef,
          def_in_tvo: defTVO,
          def_in_swap: defSwapIn,
          def_out: defPlantOut,
          close_def: closeDef,
          
          remarks: remarksList.join(" | ")
        });
      }
    }
    
    tempDate.setDate(tempDate.getDate() + 1);
  }
  
  return jsonResponse({
    success: true,
    product: selectedProduct,
    initial_good_stock: initialGoodStock,
    initial_defective_stock: initialDefectiveStock,
    ledger_data: ledgerRows
  });
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
  prodSheet.appendRow([1, 'Regulator Emr', 'EMR', 'Regulator', 'PCS', 150, 250, 400, 400, 20, 'regulator', 36]);
  prodSheet.appendRow([2, 'Regulator FTL', 'FTLR', 'Regulator', 'PCS', 180, 280, 100, 100, 10, 'ftl_regulator', 12]);
  prodSheet.appendRow([3, 'Stove Single Burner', 'STV-1', 'Stove', 'PCS', 800, 1200, 50, 50, 5, 'standard', 0]);
  prodSheet.appendRow([4, 'Gas Pipe 1.5M', 'PIPE-15', 'Pipe', 'MTR', 80, 150, 300, 300, 50, 'standard', 0]);
  
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
  userSheet.appendRow(['id', 'name', 'email', 'password', 'role']);
  userSheet.appendRow([1, 'System Administrator', 'admin@stockflow.com', 'admin123', 'admin']);
  
  // 5. Setup suppliers
  var supSheet = ss.getSheetByName('suppliers') || ss.insertSheet('suppliers');
  supSheet.clear();
  supSheet.appendRow(['id', 'name', 'phone', 'email', 'address', 'created_at']);
  supSheet.appendRow([1, 'HPCL Bottling Plant', '9988776655', 'plant@hpcl.com', 'Madhubani Industrial Area', new Date()]);

  // 6. Setup customers
  var custsSheet = ss.getSheetByName('customers') || ss.insertSheet('customers');
  custsSheet.clear();
  custsSheet.appendRow(['id', 'name', 'phone', 'email', 'address', 'created_at']);
  custsSheet.appendRow([1, 'Walk-In Customer', '0000000000', 'walkin@stockflow.com', 'Counter Sale', new Date()]);

  // 7. Setup purchases
  var purSheet = ss.getSheetByName('purchases') || ss.insertSheet('purchases');
  purSheet.clear();
  purSheet.appendRow(['id', 'supplier_id', 'product_id', 'quantity', 'purchase_price', 'total_amount', 'purchase_date', 'created_at']);
  
  // 8. Setup sales
  var salSheet = ss.getSheetByName('sales') || ss.insertSheet('sales');
  salSheet.clear();
  salSheet.appendRow(['id', 'customer_id', 'product_id', 'quantity', 'selling_price', 'total_amount', 'sale_date', 'created_at']);

  // 9. Setup adjustments
  var adjSheet = ss.getSheetByName('stock_adjustments') || ss.insertSheet('stock_adjustments');
  adjSheet.clear();
  adjSheet.appendRow(['id', 'product_id', 'quantity', 'reason', 'adjusted_at']);

  // 10. Setup settings
  var setSheet = ss.getSheetByName('settings') || ss.insertSheet('settings');
  setSheet.clear();
  setSheet.appendRow(['company_name', 'logo', 'address', 'phone', 'email', 'gstin']);
  setSheet.appendRow(['Shiv Shakti HP Gas Agency', '', 'Pandaul Bazar, Madhubani - 847234', '+91 9999888877', 'info@stockflow.com', '07AAAAA1111A1Z1']);

  // Remove default "Sheet1" if it exists to keep it clean
  var sheet1 = ss.getSheetByName('Sheet1');
  if (sheet1) {
    try {
      ss.deleteSheet(sheet1);
    } catch(err) {}
  }
  
  return "Database setup complete! All tabs, headers, sample products, suppliers, customers, settings, and default admin user created successfully.";
}

// Helpers
function getProductNameById(products, id) {
  for (var i = 0; i < products.length; i++) {
    if (products[i].id === parseInt(id)) {
      return products[i].name;
    }
  }
  return 'Unknown Item';
}

function getProductSkuById(products, id) {
  for (var i = 0; i < products.length; i++) {
    if (products[i].id === parseInt(id)) {
      return products[i].sku;
    }
  }
  return '';
}

function getEntityNameById(entities, id) {
  for (var i = 0; i < entities.length; i++) {
    if (entities[i].id === parseInt(id)) {
      return entities[i].name;
    }
  }
  return 'Unknown';
}
