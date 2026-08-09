/**
 * Custom JavaScript for Stock Register Dashboard
 */

$(document).ready(function () {
    // Sidebar toggle functionality on mobile layout
    $("#sidebar-toggle-btn").on("click", function () {
        $("#sidebar").toggleClass("toggled");
        if ($("#sidebar").hasClass("toggled") && $(window).width() < 992) {
            if ($(".sidebar-backdrop").length === 0) {
                $('<div class="sidebar-backdrop"></div>').appendTo('body').on('click', function() {
                    $("#sidebar").removeClass("toggled");
                    $(this).remove();
                });
            }
        } else {
            $(".sidebar-backdrop").remove();
        }
    });

    // Close sidebar on mobile close button click (delegated for maximum reliability)
    $(document).on("click", "#sidebar-close-btn", function () {
        $("#sidebar").removeClass("toggled");
        $(".sidebar-backdrop").remove();
    });

    // Cleanup backdrop on resize
    $(window).on("resize", function() {
        if ($(window).width() >= 992) {
            $(".sidebar-backdrop").remove();
            $("#sidebar").removeClass("toggled");
        }
    });

    // Auto-initialize standard DataTables
    if ($(".datatable").length > 0) {
        $(".datatable").DataTable({
            responsive: true,
            order: [], // Disable initial ordering to let PHP sort defaults
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search records..."
            }
        });
    }

    // Initialize Dashboard Analytics Charts if data is available
    if (window.stockflowChartsData) {
        const ctxTrend = document.getElementById('monthlyTrendChart');
        if (ctxTrend) {
            new Chart(ctxTrend, {
                type: 'bar',
                data: {
                    labels: window.stockflowChartsData.months,
                    datasets: [
                        {
                            label: 'Sales (₹)',
                            data: window.stockflowChartsData.sales,
                            backgroundColor: 'rgba(16, 185, 129, 0.85)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 6
                        },
                        {
                            label: 'Purchases (₹)',
                            data: window.stockflowChartsData.purchases,
                            backgroundColor: 'rgba(79, 70, 229, 0.85)',
                            borderColor: '#4f46e5',
                            borderWidth: 1,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Outfit' } } }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { family: 'Outfit' },
                                callback: function(value) { return '₹' + value.toLocaleString('en-IN'); }
                            }
                        },
                        x: { ticks: { font: { family: 'Outfit' } } }
                    }
                }
            });
        }

        const ctxTop = document.getElementById('topProductsChart');
        if (ctxTop && window.stockflowChartsData.topData.length > 0) {
            new Chart(ctxTop, {
                type: 'doughnut',
                data: {
                    labels: window.stockflowChartsData.topLabels,
                    datasets: [{
                        data: window.stockflowChartsData.topData,
                        backgroundColor: [
                            '#10b981',
                            '#4f46e5',
                            '#f59e0b',
                            '#ef4444',
                            '#06b6d4'
                        ],
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { family: 'Outfit', size: 11 },
                                boxWidth: 12
                            }
                        }
                    }
                }
            });
        }
    }
});

/**
 * Export HTML Table directly to Excel sheet (.xls)
 */
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    
    // Create a clone to clean up action elements or buttons during export
    var tableClone = tableSelect.cloneNode(true);
    
    // Remove anything with class no-print, button elements, or action headers
    var noExports = tableClone.querySelectorAll('.no-print, button, .btn');
    noExports.forEach(el => el.remove());
    
    var tableHTML = tableClone.outerHTML;

    // Specify filename
    filename = filename ? filename + '.xls' : 'report_export.xls';

    // Create download link element
    downloadLink = document.createElement("a");

    document.body.appendChild(downloadLink);

    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], {
            type: dataType
        });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        // Create a link to the file
        downloadLink.href = 'data:' + dataType + ', ' + encodeURIComponent(tableHTML);

        // Setting the file name
        downloadLink.download = filename;

        // Triggering the function
        downloadLink.click();
    }
    
    document.body.removeChild(downloadLink);
}
