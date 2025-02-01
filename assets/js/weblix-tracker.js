jQuery(document).ready(function ($) {
    setTimeout(function () {
        $.post(weblix_ajax.ajax_url, {
            action: 'track_page_view',
            page_url: window.location.href, // Full page URL
            page_title: document.title,      // Page title
            nonce: weblix_ajax.nonce // Send nonce
        });
    }, 2000); // 2 seconds delay
});
