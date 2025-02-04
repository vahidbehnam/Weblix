document.addEventListener('DOMContentLoaded', function () {
    function refreshWidget() {
        const apiUrl = `${weblix_ajax_object.api_url}?nocache=${new Date().getTime()}`;
        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': weblix_ajax_object.nonce,
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0',
            },
            cache: 'no-store',
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                document.querySelector('#weblix-user-count').textContent = data.unique_users || 0;
                document.querySelector('#weblix-views-count').textContent = data.total_views || 0;
                document.querySelector('#weblix-desktop-count').textContent = data.device_stats.Desktop || 0;
                document.querySelector('#weblix-mobile-count').textContent = data.device_stats.Mobile || 0;
                document.querySelector('#weblix-tablet-count').textContent = data.device_stats.Tablet || 0;

                const pagesTable = document.querySelector('#weblix-pages-table tbody');
                pagesTable.innerHTML = '';
                if (data.pages && data.pages.length > 0) {
                    data.pages.forEach(page => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${page.total_views}</td>
                            <td><a href="${page.page_url}" target="_blank">${page.page_title}</a></td>
                        `;
                        pagesTable.appendChild(row);
                    });
                } else {
                    pagesTable.innerHTML = '<tr><td colspan="2">No data available</td></tr>';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
            });
    }

    setInterval(refreshWidget, 15000); // Refresh every 15 seconds
    refreshWidget(); // Initial execution
});
