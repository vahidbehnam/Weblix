jQuery(document).ready(function ($) {
    setTimeout(function () {
        fetch(weblix_ajax.rest_url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                nonce: weblix_ajax.nonce,       // Send nonce
                page_url: window.location.href, // Full page URL
                page_title: document.title      // Page title
            })
        })
        .then(response => response.json())
        .then(data => console.log("Tracking Response:", data))
        .catch(error => console.error("Error:", error));
    }, 2000); // 2 seconds delay
});
